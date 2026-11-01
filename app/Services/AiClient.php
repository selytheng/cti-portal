<?php

namespace App\Services;

use App\Models\AiIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Minimal multi-provider LLM client for the Configuration Center "Test AI
 * Connection" button (spec 5.2) and, in Phase 3, for CTI extraction and
 * cross-story merging.
 *
 * Supported providers: gemini, claude, openai, openrouter, custom (OpenAI-
 * compatible endpoint such as Ollama/vLLM).
 */
class AiClient
{
    private const SAMPLE_PROMPT = <<<'PROMPT'
        You are a cyber threat-intelligence extractor. Read the snippet and reply with ONLY a JSON object matching:
        {"subject": string, "type": "Vul"|"Mal", "severity": "Critical"|"High"|"Medium"|"Low"|"Informational", "cves": string[]}

        Snippet: A critical unauthenticated remote code execution flaw, tracked as CVE-2026-109618, was disclosed in PHP this week. Attackers are actively exploiting it in the wild and web shells have been observed on patched-late servers.
        PROMPT;

    public function complete(AiIntegration $integration, ?string $prompt = null): array
    {
        $prompt ??= self::SAMPLE_PROMPT;

        $maxTokens = $integration->max_tokens ?? 512;
        $temperature = (float) ($integration->temperature ?? 0.2);
        $timeout = $integration->timeout ?? 30;

        $response = match ($integration->provider) {
            'gemini' => $this->gemini($integration, $prompt, $maxTokens, $temperature, $timeout),
            'claude' => $this->claude($integration, $prompt, $maxTokens, $temperature, $timeout),
            'openai', 'openrouter', 'custom' => $this->openAiCompatible($integration, $prompt, $maxTokens, $temperature, $timeout),
            default => throw new InvalidArgumentException("Unsupported provider [{$integration->provider}]."),
        };

        return [
            'provider' => $integration->provider,
            'model' => $integration->model_id,
            'raw' => $response,
            'parsed_json' => $this->tryParseJson($response),
        ];
    }

    private function gemini(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        $base = rtrim($i->base_url ?: 'https://generativelanguage.googleapis.com', '/');

        $payload = Http::timeout($timeout)
            ->post($base."/v1beta/models/{$i->model_id}:generateContent?key={$i->api_key}", [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ],
            ]);

        $payload->throw();

        return (string) ($payload->json('candidates.0.content.parts.0.text') ?? '');
    }

    private function claude(AiIntegration $i, string $prompt, int $maxTokens, float $temperature, int $timeout): string
    {
        $payload = Http::timeout($timeout)
            ->withHeaders([
                'x-api-key' => $i->api_key,
                'anthropic-version' => '2023-06-01',
            ])
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
            default => throw new InvalidArgumentException('Custom providers require a Base URL.'),
        }, '/');

        try {
            $payload = Http::timeout($timeout)
                ->withToken((string) $i->api_key)
                ->post($base.'/chat/completions', [
                    'model' => $i->model_id,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            throw new InvalidArgumentException("Connection failed: {$e->getMessage()}");
        }

        $payload->throw();

        return (string) ($payload->json('choices.0.message.content') ?? '');
    }

    /** Models sometimes wrap JSON in prose or fences — extract the object. */
    private function tryParseJson(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
