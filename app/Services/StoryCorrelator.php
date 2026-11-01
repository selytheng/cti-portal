<?php

namespace App\Services;

use App\Models\AiIntegration;
use App\Models\CtiIoc;
use App\Models\CtiRecord;
use App\Models\CtiRevision;
use App\Models\QueryRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cross-story correlation & incremental CTI update engine (spec Section 8):
 * 1. Same-URL content-update detection (SHA-256 hash compare)
 * 2. Cross-source candidate matching (CVE / IOC / pg_trgm similarity)
 * 3. AI-verified merge decision with deterministic fallback
 */
class StoryCorrelator
{
    private const MERGE_PROMPT = <<<'PROMPT'
        You are a cyber threat-intelligence analyst. Decide whether the NEW ARTICLE describes the SAME incident or vulnerability as the EXISTING CTI RECORD.

        Reply with ONLY JSON: {"merge": true or false, "reason": "short reason"}

        EXISTING CTI RECORD:
        %s

        NEW ARTICLE:
        %s
        PROMPT;

    /** Stage 1 — find an existing CTI whose sources already contain this URL. */
    public function findBySourceUrl(?string $url): ?CtiRecord
    {
        if (blank($url)) {
            return null;
        }

        return CtiRecord::query()
            ->whereRaw('sources::text ILIKE ?', ['%'.$url.'%'])
            ->first();
    }

    /** Stage 2 — candidate records: same CVE, same IOC, or similar subject. */
    public function findCandidates(array $cves, array $iocs, string $subject): Collection
    {
        $cveIds = collect($cves)->pluck('cve')->filter()->values();
        $iocValues = collect($iocs)->pluck('value')->filter()->values();

        return CtiRecord::query()
            ->where('created_at', '>=', now()->subDays(60))
            ->where(function ($q) use ($cveIds, $iocValues, $subject) {
                foreach ($cveIds as $cve) {
                    $q->orWhereRaw('cves::text ILIKE ?', ['%'.$cve.'%']);
                }

                if ($iocValues->isNotEmpty()) {
                    $q->orWhereHas('iocs', fn ($iq) => $iq->whereIn('value', $iocValues->all()));
                }

                $q->orWhereRaw('subject % ?', [$subject]);
            })
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    /** Stage 3 — merge decision: AI-verified, with deterministic fallback. */
    public function shouldMerge(CtiRecord $candidate, array $articleData, ?AiIntegration $ai): bool
    {
        // Overlapping CVE IDs is a definitive match.
        $candidateCves = collect($candidate->cves ?? [])->pluck('cve');
        $articleCves = collect($articleData['cves'] ?? [])->pluck('cve');

        if ($candidateCves->intersect($articleCves)->isNotEmpty()) {
            return true;
        }

        if (! $ai) {
            return false;
        }

        try {
            $prompt = sprintf(
                self::MERGE_PROMPT,
                json_encode($candidate->only(['cti_id', 'subject', 'type', 'severity', 'summary'])
                    + ['cves' => $candidateCves->all()], JSON_UNESCAPED_SLASHES),
                json_encode($articleData, JSON_UNESCAPED_SLASHES)
            );

            $text = $this->complete($ai, $prompt);

            if (preg_match('/\{.*\}/s', $text, $m)) {
                $decision = json_decode($m[0], true);

                return (bool) ($decision['merge'] ?? false);
            }
        } catch (Throwable) {
            // merge decision failure → do not merge
        }

        return false;
    }

    /**
     * Incremental merge (spec Section 8): populate empty fields, append new
     * deduplicated entries, bump modified_at/revision_count, record the exact
     * field diff in cti_revisions.
     */
    public function merge(CtiRecord $record, array $articleData, array $source, QueryRun $run): bool
    {
        $before = $record->only(['subject', 'severity', 'cves', 'threat_actors', 'mitigation', 'summary']);
        $fieldsUpdated = [];

        // Multi-value fields: append + deduplicate.
        $listFields = ['cves', 'threat_actors', 'target_objects', 'impact_hardware', 'impact_software'];
        $keyOf = fn ($item) => is_array($item) ? ($item['cve'] ?? $item['value'] ?? json_encode($item)) : $item;

        foreach ($listFields as $field) {
            $incoming = collect($articleData[$field] ?? [])->filter()->values();
            if ($incoming->isEmpty()) {
                continue;
            }

            $current = collect($record->{$field} ?? []);
            $mergedKeys = $current->map($keyOf)->merge($incoming->map($keyOf))->unique()->values();
            $pool = $current->concat($incoming);

            $merged = $mergedKeys->map(fn ($key) => $pool->first(fn ($item) => $keyOf($item) === $key))->all();

            if ($merged != $record->{$field}) {
                $record->{$field} = $merged;
                $fieldsUpdated[] = $field;
            }
        }

        // Narrative fields: fill when empty, append a dated update otherwise.
        foreach (['summary', 'technical_analysis', 'mitigation'] as $field) {
            $incoming = trim((string) ($articleData[$field] ?? ''));

            if ($incoming === '') {
                continue;
            }

            if (blank($record->{$field})) {
                $record->{$field} = $incoming;
                $fieldsUpdated[] = $field;
            } elseif (! str_contains((string) $record->{$field}, mb_substr($incoming, 0, 60))) {
                $record->{$field} .= "\n\n[Update ".now()->toDateString().'] '.$incoming;
                $fieldsUpdated[] = $field;
            }
        }

        // Scalar upgrades.
        if (filled($articleData['exploit_status'] ?? null) && $record->exploit_status !== $articleData['exploit_status']) {
            $record->exploit_status = $articleData['exploit_status'];
            $fieldsUpdated[] = 'exploit_status';
        }

        $sevOrder = array_values(CtiRecord::SEVERITIES); // Critical first = most severe
        $newIdx = array_search($articleData['severity'] ?? 'Medium', $sevOrder, true);
        $oldIdx = array_search($record->severity, $sevOrder, true);

        if ($newIdx !== false && $oldIdx !== false && $newIdx < $oldIdx) {
            $record->severity = $sevOrder[$newIdx];
            $fieldsUpdated[] = 'severity';
        }

        if ($record->addSource($source)) {
            $fieldsUpdated[] = 'sources';
        }

        // New IOCs → append (deduplicated).
        foreach ($articleData['iocs'] ?? [] as $ioc) {
            $record->iocs()->firstOrCreate(
                ['ioc_type' => $ioc['type'], 'value' => $ioc['value']],
                [
                    'defanged_value' => CtiIoc::defang($ioc['value'], $ioc['type']),
                    'added_in_revision' => $record->revision_count + 1,
                ]
            );
        }

        if ($fieldsUpdated === []) {
            return false;
        }

        $record->modified_at = now();
        $record->revision_count += 1;
        $record->refreshDevelopingFlag();
        $record->save();

        CtiRevision::create([
            'cti_record_id' => $record->id,
            'query_run_id' => $run->id,
            'source_url' => $source['url'] ?? null,
            'fields_updated' => array_values(array_unique($fieldsUpdated)),
            'previous_values' => $before,
            'new_values' => $record->only(['subject', 'severity', 'cves', 'threat_actors', 'mitigation', 'summary']),
            'created_at' => now(),
        ]);

        return true;
    }

    private function complete(AiIntegration $ai, string $prompt): string
    {
        $timeout = $ai->timeout ?? 60;

        $response = match ($ai->provider) {
            'gemini' => Http::timeout($timeout)->post(
                rtrim($ai->base_url ?: 'https://generativelanguage.googleapis.com', '/')."/v1beta/models/{$ai->model_id}:generateContent?key=***>api_key}",
                ['contents' => [['parts' => [['text' => $prompt]]]]]
            ),
            'claude' => Http::timeout($timeout)
                ->withHeaders(['x-api-key' => $ai->api_key, 'anthropic-version' => '2023-06-01'])
                ->post(rtrim($ai->base_url ?: 'https://api.anthropic.com', '/').'/v1/messages', [
                    'model' => $ai->model_id,
                    'max_tokens' => $ai->max_tokens ?? 512,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]),
            default => Http::timeout($timeout)
                ->withToken((string) $ai->api_key)
                ->post(rtrim($ai->base_url ?: 'https://openrouter.ai/api/v1', '/').'/chat/completions', [
                    'model' => $ai->model_id,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]),
        };

        $response->throw();

        return match ($ai->provider) {
            'gemini' => (string) ($response->json('candidates.0.content.parts.0.text') ?? ''),
            'claude' => (string) ($response->json('content.0.text') ?? ''),
            default => (string) ($response->json('choices.0.message.content') ?? ''),
        };
    }
}
