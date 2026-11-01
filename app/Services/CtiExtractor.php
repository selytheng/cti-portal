<?php

namespace App\Services;

use App\Models\AiIntegration;
use App\Models\CtiIoc;
use App\Models\CtiRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single-article structured extraction (spec 5.2 prompt #1). Falls back to a
 * deterministic no-AI extraction when no provider has an API key configured,
 * so the pipeline produces developing CTI records even before keys are added.
 */
class CtiExtractor
{
    private const EXTRACTION_PROMPT = <<<'PROMPT'
        You are a cyber threat-intelligence analyst. Extract structured intel from the article below.

        Reply with ONLY a JSON object, no markdown, matching exactly:
        {
          "subject": string (normalized advisory/campaign title),
          "type": "Vul" | "Mal",
          "subtypes": string[] (subset of: Ransomware, APT, Exploit, Zero-Day, Supply Chain),
          "severity": "Critical" | "High" | "Medium" | "Low" | "Informational",
          "cves": [{"cve": "CVE-YYYY-NNNNN"}],
          "iocs": [{"type": "IPv4|IPv6|Domain|URL|MD5|SHA1|SHA256|Email", "value": string}],
          "threat_actors": [string],
          "target_objects": [string],
          "impact_hardware": [string],
          "impact_software": [string],
          "exploit_status": "Unproven" | "PoC Available" | "Actively Exploited in the Wild" | "Weaponized",
          "summary": string,
          "technical_analysis": string,
          "mitigation": string,
          "confidence": integer 0-100
        }
        Use empty arrays when unknown. Never invent CVE IDs or IOCs — only ones literally present in the article.

        ARTICLE:
        PROMPT;

    public function defaultIntegration(): ?AiIntegration
    {
        return AiIntegration::query()
            ->where('is_active', true)
            ->whereNotNull('api_key')
            ->orderByDesc('is_default')
            ->first();
    }

    /**
     * @return array{extracted: bool, provider: ?string, data: array}
     *         data keys: subject, type, subtypes, severity, cves, iocs,
     *         threat_actors, target_objects, impact_hardware, impact_software,
     *         exploit_status, summary, technical_analysis, mitigation, confidence
     */
    public function extract(string $title, string $url, string $feedName, ?string $publishedAt, ?AiIntegration $ai = null): array
    {
        if ($ai) {
            try {
                return ['extracted' => true, 'provider' => $ai->provider, 'data' => $this->extractWithAi($ai, $title, $content = $this->fetchContent($url))];
            } catch (Throwable) {
                // fall through to deterministic extraction
            }
        }

        return ['extracted' => false, 'provider' => null, 'data' => $this->extractFallback($title, $url, $feedName)];
    }

    private function extractWithAi(AiIntegration $ai, string $title, string $content): array
    {
        $maxTokens = $ai->max_tokens ?? 2000;
        $temperature = (float) ($ai->temperature ?? 0.1);
        $timeout = $ai->timeout ?? 60;
        $prompt = (($ai->system_prompt ?: self::EXTRACTION_PROMPT)."Title: {$title}\n\n{$content}");

        $text = match ($ai->provider) {
            'gemini' => $this->gemini($ai, $prompt, $maxTokens, $temperature, $timeout),
            'claude' => $this->claude($ai, $prompt, $maxTokens, $temperature, $timeout),
            default => $this->openAiCompatible($ai, $prompt, $maxTokens, $temperature, $timeout),
        };

        if (! preg_match('/\{.*\}/s', $text, $m)) {
            throw new \RuntimeException('Model returned no JSON object.');
        }

        $data = json_decode($m[0], true);

        if (! is_array($data)) {
            throw new \RuntimeException('Model JSON was invalid.');
        }

        return $this->normalize($data, $title);
    }

    private function normalize(array $data, string $fallbackTitle): array
    {
        $cveRegex = '/^CVE-\d{4}-\d{4,}$/i';

        $cves = collect($data['cves'] ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['cve'] ?? null) : $c)
            ->filter(fn ($id) => is_string($id) && preg_match($cveRegex, $id) === 1)
            ->unique()
            ->map(fn ($id) => ['cve' => strtoupper($id)])
            ->values()
            ->all();

        $iocs = collect($data['iocs'] ?? [])
            ->map(fn ($i) => is_array($i) ? $i : ['value' => $i])
            ->filter(fn ($i) => filled($i['value'] ?? null))
            ->map(function ($i) {
                $type = in_array($i['type'] ?? null, CtiIoc::TYPES, true)
                    ? $i['type']
                    : (CtiIoc::detectType((string) $i['value']) ?? null);

                return $type ? ['type' => $type, 'value' => (string) $i['value']] : null;
            })
            ->filter()
            ->unique('value')
            ->values()
            ->all();

        $severity = in_array($data['severity'] ?? null, CtiRecord::SEVERITIES, true)
            ? $data['severity'] : 'Medium';

        return [
            'subject' => filled($data['subject'] ?? null) ? (string) $data['subject'] : $fallbackTitle,
            'type' => ($data['type'] ?? 'Vul') === 'Mal' ? 'Mal' : 'Vul',
            'subtypes' => array_values(array_filter((array) ($data['subtypes'] ?? []))),
            'severity' => $severity,
            'cves' => $cves,
            'iocs' => $iocs,
            'threat_actors' => array_values(array_filter((array) ($data['threat_actors'] ?? []))),
            'target_objects' => array_values(array_filter((array) ($data['target_objects'] ?? []))),
            'impact_hardware' => array_values(array_filter((array) ($data['impact_hardware'] ?? []))),
            'impact_software' => array_values(array_filter((array) ($data['impact_software'] ?? []))),
            'exploit_status' => in_array($data['exploit_status'] ?? null, CtiRecord::EXPLOIT_STATUSES, true)
                ? $data['exploit_status'] : null,
            'summary' => (string) ($data['summary'] ?? ''),
            'technical_analysis' => (string) ($data['technical_analysis'] ?? ''),
            'mitigation' => (string) ($data['mitigation'] ?? ''),
            'confidence' => min(100, max(0, (int) ($data['confidence'] ?? 60))),
        ];
    }

    /**
     * Deterministic extraction without AI: pull CVE IDs and obvious IOCs from
     * the title, keep everything else empty so the record stays "developing".
     */
    private function extractFallback(string $title, string $url, string $feedName): array
    {
        $text = $title;

        $cves = collect(preg_match_all('/CVE-\d{4}-\d{4,}/i', $text, $m) ? $m[0] : [])
            ->unique()
            ->map(fn ($id) => ['cve' => strtoupper($id)])
            ->values()
            ->all();

        $iocs = [];
        foreach ([CtiIoc::detectType($url) ? $url : null] as $candidate) {
            if ($candidate) {
                $iocs[] = ['type' => CtiIoc::detectType($candidate), 'value' => $candidate];
            }
        }

        return [
            'subject' => $title,
            'type' => $cves === [] && preg_match('/ransomware|stealer|malware|campaign|backdoor|botnet|infostealer/i', $text) ? 'Mal' : 'Vul',
            'subtypes' => [],
            'severity' => 'Medium',
            'cves' => $cves,
            'iocs' => $iocs,
            'threat_actors' => [],
            'target_objects' => [],
            'impact_hardware' => [],
            'impact_software' => [],
            'exploit_status' => null,
            'summary' => "Reported by {$feedName}: {$title}",
            'technical_analysis' => '',
            'mitigation' => '',
            'confidence' => 30,
        ];
    }

    /** Best-effort page text fetch so the model sees more than a headline. */
    private function fetchContent(string $url): string
    {
        try {
            $body = Http::timeout(10)->withUserAgent('CTI-Portal/1.0')->get($url)->body();

            $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

            return Str::limit($text, 6000);
        } catch (Throwable) {
            return '';
        }
    }

    private function gemini(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        $base = rtrim($i->base_url ?: 'https://generativelanguage.googleapis.com', '/');

        $payload = Http::timeout($timeout)
            ->post($base."/v1beta/models/{$i->model_id}:generateContent?key=***>api_key}", [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens],
            ]);
        $payload->throw();

        return (string) ($payload->json('candidates.0.content.parts.0.text') ?? '');
    }

    private function claude(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        $payload = Http::timeout($timeout)
            ->withHeaders(['x-api-key' => $i->api_key, 'anthropic-version' => '2023-06-01'])
            ->post(rtrim($i->base_url ?: 'https://api.anthropic.com', '/').'/v1/messages', [
                'model' => $i->model_id,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        $payload->throw();

        return (string) ($payload->json('content.0.text') ?? '');
    }

    private function openAiCompatible(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        $base = rtrim($i->base_url ?: match ($i->provider) {
            'openai' => 'https://api.openai.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => 'https://api.openai.com/v1',
        }, '/');

        $payload = Http::timeout($timeout)
            ->withToken((string) $i->api_key)
            ->post($base.'/chat/completions', [
                'model' => $i->model_id,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        $payload->throw();

        return (string) ($payload->json('choices.0.message.content') ?? '');
    }
}
