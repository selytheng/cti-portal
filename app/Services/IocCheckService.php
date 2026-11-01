<?php

namespace App\Services;

use App\Models\CtiIoc;
use App\Models\IocVerifier;
use Illuminate\Support\Collection;
use Throwable;

/**
 * IOC verification against OTX and Abuse.ch (spec 5.3 + 6: verdicts
 * Malicious / Suspicious / Clean / Unknown). Inactive or key-less verifiers
 * are skipped gracefully — IOCs stay Unknown until services are configured.
 */
class IocCheckService
{
    public function verifyIoc(CtiIoc $ioc, ?Collection $verifiers = null): CtiIoc
    {
        $verifiers ??= IocVerifier::query()
            ->where('is_active', true)
            ->whereNotNull('api_key')
            ->get();

        if ($verifiers->isEmpty()) {
            $ioc->forceFill(['verdict' => 'Unknown', 'last_verified_at' => now()])->save();

            return $ioc;
        }

        $malicious = false;
        $abusechData = [];
        $otxData = [];

        foreach ($verifiers as $verifier) {
            try {
                $result = match ($verifier->service) {
                    'otx' => $this->checkOtx($ioc, $verifier, $otxData),
                    'abusech' => $this->checkAbuseCh($ioc, $verifier, $abusechData),
                    default => null,
                };

                $malicious = $malicious || $result === 'malicious';
            } catch (Throwable) {
                continue; // one failed service must not break verification
            }
        }

        if ($otxData !== []) {
            $ioc->otx_data = $otxData;
        }

        if ($abusechData !== []) {
            $ioc->abusech_data = $abusechData;
        }

        $ioc->forceFill([
            'verdict' => $malicious ? 'Malicious' : ($ioc->verdict ?: 'Unknown'),
            'last_verified_at' => now(),
        ])->save();

        return $ioc;
    }

    private function checkOtx(CtiIoc $ioc, IocVerifier $verifier, array &$otxData): ?string
    {
        $typeMap = [
            'IPv4' => 'IPv4', 'IPv6' => 'IPv6', 'Domain' => 'domain', 'URL' => 'url',
            'MD5' => 'file', 'SHA1' => 'file', 'SHA256' => 'file',
        ];

        $otxType = $typeMap[$ioc->ioc_type] ?? null;

        if ($otxType === null) {
            return null;
        }

        $base = rtrim($verifier->base_url ?: 'https://otx.alienvault.com/api/v1', '/');

        $response = Http::timeout(15)
            ->withHeaders(['X-OTX-API-KEY' => (string) $verifier->api_key])
            ->get("{$base}/indicators/{$otxType}/{$ioc->value}/general");

        if (! $response->successful()) {
            return null;
        }

        $pulseCount = (int) ($response->json('pulse_info.count') ?? 0);
        $otxData['pulse_count'] = $pulseCount;

        return $pulseCount > 0 ? 'malicious' : null;
    }

    private function checkAbuseCh(CtiIoc $ioc, IocVerifier $verifier, array &$abusechData): ?string
    {
        $subs = $verifier->sub_services ?? [];
        $found = null;

        if ($ioc->ioc_type === 'URL' && ($subs['urlhaus'] ?? false)) {
            $response = Http::timeout(15)->asForm()->post('https://urlhaus-api.abuse.ch/v1/url/', [
                'url' => $ioc->value,
            ]);

            if ($response->successful() && ($response->json('query_status') ?? '') === 'ok') {
                $abusechData['urlhaus'] = ['threat' => $response->json('threat')];
                $found = 'malicious';
            }
        }

        if (in_array($ioc->ioc_type, ['MD5', 'SHA1', 'SHA256']) && ($subs['malwarebazaar'] ?? false)) {
            $response = Http::timeout(15)->asForm()->post('https://mbazaar.abuse.ch/api/v1/', [
                'query' => 'get_info',
                'hash' => $ioc->value,
                'auth_key' => (string) $verifier->api_key,
            ]);

            if ($response->successful() && ($response->json('query_status') ?? '') === 'ok') {
                $abusechData['malwarebazaar'] = ['signature' => $response->json('data.0.signature')];
                $found = 'malicious';
            }
        }

        if (in_array($ioc->ioc_type, ['IPv4', 'Domain', 'URL']) && ($subs['threatfox'] ?? false)) {
            $response = Http::timeout(15)->post('https://threatfox-api.abuse.ch/api/v1/', [
                'query' => 'search_ioc',
                'search_term' => $ioc->value,
            ]);

            if ($response->successful() && ($response->json('query_status') ?? '') === 'ok' && $response->json('data')) {
                $abusechData['threatfox'] = [
                    'malware' => $response->json('data.0.malware_printable'),
                    'confidence' => $response->json('data.0.confidence'),
                ];
                $found = 'malicious';
            }
        }

        return $found;
    }
}
