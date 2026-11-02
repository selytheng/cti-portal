<?php

namespace App\Services;

use App\Models\CtiRecord;
use App\Models\CtiRevision;
use App\Models\CveVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * CVE trust-source verification (spec Section 6: cves enriched with CVSS,
 * EPSS, CWE, CISA KEV status):
 *  - NVD API 2.0 (API key optional but recommended) → CVSS score/vector,
 *    severity, CWE
 *  - CISA KEV catalog (public JSON, cached) → "Actively Exploited" flag
 *  - FIRST EPSS API (public) → exploitability score + percentile
 */
class CveVerifierService
{
    private const NVD_URL = 'https://services.nvd.nist.gov';
    private const KEV_URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';
    private const EPSS_URL = 'https://api.first.org/data/v1/epss';

    private const NVD_TEST_CVE = 'CVE-2021-44228'; // Log4Shell — stable, always in NVD

    /** "Test API" connectivity/credential check (config tab). */
    public function test(CveVerifier $verifier): array
    {
        if (! $verifier->is_active) {
            return ['ok' => false, 'detail' => 'Verifier is inactive — toggle it on to enable verification.'];
        }

        try {
            $response = $this->client($verifier)->get(
                rtrim($verifier->base_url ?: self::NVD_URL, '/').'/rest/json/cves/2.0',
                ['cveId' => self::NVD_TEST_CVE]
            );
        } catch (ConnectionException $e) {
            return ['ok' => false, 'detail' => 'Connection failed: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            $detail = $response->json('error') ?? $response->body();
            $detail = is_string($detail) ? Str::limit(trim($detail), 120) : '';

            return ['ok' => false, 'detail' => 'NVD rejected the request (HTTP '.$response->status().($detail ? ' — '.$detail : '').').'];
        }

        if ((int) $response->json('totalResults') === 0) {
            return ['ok' => false, 'detail' => 'NVD reachable but the test CVE returned no data.'];
        }

        $keyNote = filled($verifier->api_key)
            ? 'API key accepted'
            : 'no API key set — rate limited to 5 requests/30s';

        return ['ok' => true, 'detail' => "NVD OK ($keyNote)."];
    }

    /**
     * CISA KEV CVE IDs, cached for 6 hours (public catalog, no key).
     *
     * Stored as a plain ARRAY, not a Collection: objects in the cache can
     * come back as __PHP_Incomplete_Class (poisoned/stale serialization),
     * which crashed the strict Collection return type. Arrays are safe.
     */
    public function kevIds(): Collection
    {
        $cached = Cache::get('cisa_kev_ids');

        if (is_array($cached)) {
            return collect($cached);
        }

        // Poisoned or absent — drop it and refetch fresh.
        Cache::forget('cisa_kev_ids');

        try {
            $response = Http::timeout(30)->get(self::KEV_URL);
            $ids = collect($response->json('vulnerabilities') ?? [])
                ->pluck('cveID')
                ->filter()
                ->unique()
                ->values()
                ->all();

            Cache::put('cisa_kev_ids', $ids, now()->addHours(6));

            return collect($ids);
        } catch (Throwable) {
            return collect();
        }
    }

    /** FIRST EPSS score (public, no key). */
    public function epss(string $cveId): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::EPSS_URL, ['cve' => $cveId]);
            $entry = $response->json('data.0');

            return $entry ? [
                'epss' => (float) $entry['epss'],
                'epss_percentile' => (float) $entry['percentile'],
            ] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Query NVD for a single CVE. Returns normalized enrichment data or null.
     */
    public function fetchCve(string $cveId, CveVerifier $verifier): ?array
    {
        try {
            $response = $this->client($verifier)->get(
                rtrim($verifier->base_url ?: self::NVD_URL, '/').'/rest/json/cves/2.0',
                ['cveId' => $cveId]
            );
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || (int) $response->json('totalResults') === 0) {
            return null;
        }

        $cve = $response->json('vulnerabilities.0.cve') ?? [];
        $metrics = $cve['metrics'] ?? [];

        // Prefer CVSS v3.1 → v4.0 → v2 primary metric.
        $metric = $metrics['cvssMetricV31'][0] ?? $metrics['cvssMetricV40'][0] ?? $metrics['cvssMetricV2'][0] ?? null;

        $cwes = collect($cve['weaknesses'] ?? [])
            ->flatMap(fn (array $w) => collect($w['description'] ?? [])->pluck('value'))
            ->filter(fn ($v) => str_starts_with((string) $v, 'CWE-'))
            ->unique()
            ->values()
            ->all();

        if (! $metric) {
            return null;
        }

        return [
            'cvss' => (float) ($metric['cvssData']['baseScore'] ?? 0),
            'vector' => $metric['cvssData']['vectorString'] ?? null,
            'severity' => $metric['cvssData']['baseSeverity'] ?? ($metric['baseSeverity'] ?? null),
            'cwe' => $cwes ?: null,
            'source' => $metric['source'] ?? null,
        ];
    }

    /**
     * Enrich every CVE on a CTI record against NVD + KEV + EPSS.
     * Returns 1 if the record was updated, 0 if already current.
     */
    public function verifyRecord(CtiRecord $record, CveVerifier $verifier): int
    {
        if (empty($record->cves)) {
            return 0;
        }

        $kev = $this->kevIds();
        $before = $record->only(['cves']);
        $updated = [];
        $changed = false;

        foreach ($record->cves as $entry) {
            $cveId = is_array($entry) ? ($entry['cve'] ?? null) : $entry;
            $data = is_array($entry) ? $entry : ['cve' => $entry];

            if (filled($cveId)) {
                $nvd = $this->fetchCve($cveId, $verifier);

                if ($nvd) {
                    $data = array_merge($data, $nvd);
                }

                $data['kev'] = $kev->contains($cveId) ? 'Yes' : 'No';

                if ($epss = $this->epss($cveId)) {
                    $data = array_merge($data, $epss);
                }

                if ($updated != ($before['cves'] ?? null)) {
                    $changed = true;
                }
            }

            $updated[] = $data;

            // NVD rate limits: 5 req/30s without a key, 50 req/30s with one.
            usleep($verifier->requestInterval() * 1_000_000);
        }

        if ($updated == ($record->cves ?? [])) {
            return 0;
        }

        $record->cves = $updated;
        $record->modified_at = now();
        $record->revision_count += 1;
        $record->refreshDevelopingFlag();
        $record->save();

        CtiRevision::create([
            'cti_record_id' => $record->id,
            'source_url' => 'NVD / CISA KEV / FIRST EPSS enrichment',
            'fields_updated' => ['cves'],
            'previous_values' => $before,
            'new_values' => $record->only(['cves']),
            'created_at' => now(),
        ]);

        activity()->event('enriched')->log("CVEs verified against NVD/KEV/EPSS for {$record->cti_id}");

        return 1;
    }

    private function client(CveVerifier $verifier)
    {
        $client = Http::timeout(20);

        if (filled($verifier->api_key)) {
            $client = $client->withHeaders(['apiKey' => trim((string) $verifier->api_key)]);
        }

        return $client;
    }
}
