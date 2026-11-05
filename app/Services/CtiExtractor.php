<?php

namespace App\Services;

use App\Models\AiIntegration;
use App\Models\CtiIoc;
use App\Models\CtiRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single-article structured extraction (spec 5.2 prompt #1).
 * Extracts Executive Summary, Technical Analysis, Mitigation, CVEs, IOCs,
 * Threat Actors, Impacted Hardware/Software, Target Objects, MITRE ATT&CK.
 * Falls back to deterministic extraction when no provider is configured
 * or on unexpected model errors.
 */
class CtiExtractor
{
    private const EXTRACTION_PROMPT = <<<'PROMPT'
You are an expert Cyber Threat Intelligence (CTI) analyst. Extract thorough, comprehensive, and highly structured threat intelligence from the security advisory or article below.

Reply with ONLY a valid, parseable JSON object without markdown formatting, matching exactly this structure:
{
  "subject": string (clear, normalized advisory, campaign, or incident title),
  "type": "Vul" | "Mal",
  "subtypes": string[] (subset of: Ransomware, APT, Exploit, Zero-Day, Supply Chain),
  "severity": "Critical" | "High" | "Medium" | "Low" | "Informational",
  "cves": [{"cve": "CVE-YYYY-NNNNN"}],
  "iocs": [{"type": "IPv4|IPv6|Domain|URL|MD5|SHA1|SHA256|Email", "value": string}],
  "threat_actors": string[] (names of known threat actors, APT groups, or ransomware syndicates involved),
  "target_objects": string[] (targeted industries/sectors e.g. Healthcare, Financial, Government, and targeted geographies/countries),
  "impact_hardware": string[] (affected hardware models, appliances, IoT, or networking devices),
  "impact_software": string[] (affected software products, platforms, operating systems, libraries, packages, or specific versions),
  "mitre_attack": [{"id": "T1xxx", "name": "Technique Name", "tactic": "Tactic Name"}],
  "exploit_status": "Unproven" | "PoC Available" | "Actively Exploited in the Wild" | "Weaponized",
  "summary": string (a comprehensive Executive Summary detailing the nature of the threat, who/what is at risk, business impact, and key findings),
  "technical_analysis": string (an in-depth technical analysis explaining root cause, attack vector, vulnerability mechanics, payload behavior, execution flow, or protocol details),
  "mitigation": string (clear, actionable mitigation recommendations, defensive measures, patch instructions, configuration changes, or detection guidance),
  "confidence": integer 0-100
}
Rules:
- Never leave "summary", "technical_analysis", or "mitigation" blank if information can be reasonably analyzed or synthesized from the article.
- Use empty arrays [] when unknown.
- Do not invent non-existent CVEs or IOCs — only extract ones present or referenced in the article.
- Ensure the JSON is strictly valid.

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
     */
    public function extract(string $title, string $url, string $feedName, ?string $publishedAt, ?AiIntegration $ai = null, string $rssFallback = ''): array
    {
        if ($ai) {
            try {
                $content = $this->fetchContent($url, $rssFallback);
                $data = $this->extractWithAi($ai, $title, $content);

                return ['extracted' => true, 'provider' => $ai->provider, 'data' => $data];
            } catch (Throwable $e) {
                Log::warning("AI extraction failed for '{$title}' via {$ai->provider}: ".$e->getMessage());
            }
        }

        return ['extracted' => false, 'provider' => null, 'data' => $this->extractFallback($title, $url, $feedName, $rssFallback)];
    }

    private function extractWithAi(AiIntegration $ai, string $title, string $content): array
    {
        $maxTokens = $ai->max_tokens ?? 3000;
        $temperature = (float) ($ai->temperature ?? 0.2);
        $timeout = max((int) ($ai->timeout ?? 120), 120);
        $prompt = (($ai->system_prompt ?: self::EXTRACTION_PROMPT)."\n\nTitle: {$title}\n\nContent:\n{$content}");

        $text = match ($ai->provider) {
            'gemini' => $this->gemini($ai, $prompt, $maxTokens, $temperature, $timeout),
            'claude' => $this->claude($ai, $prompt, $maxTokens, $temperature, $timeout),
            default => $this->openAiCompatible($ai, $prompt, $maxTokens, $temperature, $timeout),
        };

        $clean = trim($text);
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $clean, $m)) {
            $clean = $m[1];
        } elseif (preg_match('/\{[\s\S]*\}/', $clean, $m)) {
            $clean = $m[0];
        }

        $data = json_decode($clean, true);

        if (! is_array($data)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $substr = substr($text, $start, $end - $start + 1);
                $data = json_decode($substr, true);
            }
        }

        if (! is_array($data)) {
            throw new \RuntimeException('Model returned invalid JSON: '.Str::limit($text, 200));
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

        $mitre = collect($data['mitre_attack'] ?? [])
            ->map(function ($m) {
                if (is_string($m)) {
                    return ['id' => trim($m), 'name' => trim($m), 'tactic' => ''];
                }
                if (is_array($m) && filled($m['id'] ?? $m['technique_id'] ?? null)) {
                    return [
                        'id' => (string) ($m['id'] ?? $m['technique_id']),
                        'name' => (string) ($m['name'] ?? $m['technique_name'] ?? ''),
                        'tactic' => (string) ($m['tactic'] ?? ''),
                    ];
                }

                return null;
            })
            ->filter()
            ->unique('id')
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
            'mitre_attack' => $mitre,
            'exploit_status' => in_array($data['exploit_status'] ?? null, CtiRecord::EXPLOIT_STATUSES, true)
                ? $data['exploit_status'] : null,
            'summary' => (string) ($data['summary'] ?? ''),
            'technical_analysis' => (string) ($data['technical_analysis'] ?? ''),
            'mitigation' => (string) ($data['mitigation'] ?? ''),
            'confidence' => min(100, max(0, (int) ($data['confidence'] ?? 60))),
        ];
    }

    private function extractFallback(string $title, string $url, string $feedName, string $rssFallback = ''): array
    {
        $text = $title.' '.$rssFallback;

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
            'mitre_attack' => [],
            'exploit_status' => null,
            'summary' => filled($rssFallback) ? Str::limit($rssFallback, 500) : "Reported by {$feedName}: {$title}",
            'technical_analysis' => '',
            'mitigation' => '',
            'confidence' => 30,
        ];
    }

    public function fetchContent(string $url, string $rssFallback = ''): string
    {
        $text = '';
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ])
                    ->get($url);

                if ($response->successful()) {
                    $html = $response->body();
                    $clean = preg_replace('/<(script|style|noscript|svg|header|footer|nav)\b[^>]*>(.*?)<\/\1>/is', ' ', $html);
                    $clean = strip_tags($clean ?? '');
                    $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
                    if (mb_strlen($clean) >= 150) {
                        $text = Str::limit($clean, 12000);
                    }
                }
            } catch (Throwable) {
                // fall through to RSS fallback
            }
        }

        if (mb_strlen($text) < 150 && filled($rssFallback)) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($rssFallback)) ?? '');
        }

        return $text;
    }

    private function gemini(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        $base = rtrim($i->base_url ?: 'https://generativelanguage.googleapis.com', '/');

        return retry(3, function () use ($i, $base, $prompt, $maxTokens, $temperature, $timeout) {
            $payload = Http::timeout($timeout)
                ->post($base."/v1beta/models/{$i->model_id}:generateContent?key=***>api_key}", [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens],
                ]);

            if ($payload->status() === 429) {
                sleep(2);
            }

            $payload->throw();

            return (string) ($payload->json('candidates.0.content.parts.0.text') ?? '');
        }, 2000);
    }

    private function claude(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        return retry(3, function () use ($i, $prompt, $maxTokens, $temperature, $timeout) {
            $payload = Http::timeout($timeout)
                ->withHeaders(['x-api-key' => $i->api_key, 'anthropic-version' => '2023-06-01'])
                ->post(rtrim($i->base_url ?: 'https://api.anthropic.com', '/').'/v1/messages', [
                    'model' => $i->model_id,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if ($payload->status() === 429) {
                sleep(2);
            }

            $payload->throw();

            return (string) ($payload->json('content.0.text') ?? '');
        }, 2000);
    }

    private function openAiCompatible(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        $base = rtrim($i->base_url ?: match ($i->provider) {
            'openai' => 'https://api.openai.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => 'https://api.openai.com/v1',
        }, '/');

        $headers = [];
        if ($i->provider === 'openrouter' || str_contains($base, 'openrouter.ai')) {
            $headers['HTTP-Referer'] = config('app.url', 'http://127.0.0.1:8000');
            $headers['X-Title'] = config('app.name', 'CTI Portal');
        }

        return retry(3, function () use ($i, $base, $headers, $prompt, $maxTokens, $temperature, $timeout) {
            $payload = Http::timeout($timeout)
                ->withHeaders($headers)
                ->withToken((string) $i->api_key)
                ->post($base.'/chat/completions', [
                    'model' => $i->model_id,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if ($payload->status() === 429) {
                sleep(2);
            }

            $payload->throw();

            return (string) ($payload->json('choices.0.message.content') ?? '');
        }, 2000);
    }
}
