<?php

namespace App\Services;

use App\Models\IocVerifier;
use Illuminate\Support\Facades\Http;

/**
 * "Test API" checks for the IOC verifier configuration cards (spec 5.3),
 * and the Phase 3 verification backend for OTX / Abuse.ch lookups.
 */
class IocVerifierService
{
    /**
     * Connectivity + credential test for a configured verifier.
     *
     * @return array{ok: bool, detail: string}
     */
    public function test(IocVerifier $verifier): array
    {
        return match ($verifier->service) {
            'otx' => $this->testOtx($verifier),
            'abusech' => $this->testAbuseCh($verifier),
            default => ['ok' => false, 'detail' => 'Unknown verifier service.'],
        };
    }

    private function testOtx(IocVerifier $verifier): array
    {
        if (empty($verifier->api_key)) {
            return ['ok' => false, 'detail' => 'No API key configured.'];
        }

        $base = rtrim($verifier->base_url ?: 'https://otx.alienvault.com/api/v1', '/');

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-OTX-API-KEY' => (string) $verifier->api_key])
                ->get($base.'/users/me');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'detail' => 'Connection failed: '.$e->getMessage()];
        }

        if ($response->status() === 403) {
            return ['ok' => false, 'detail' => 'API key rejected (403). Check the key.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'detail' => 'HTTP '.$response->status().' from OTX.'];
        }

        $username = $response->json('username');

        return ['ok' => true, 'detail' => 'Authenticated as '.($username ?: 'unknown OTX user').'.'];
    }

    private function testAbuseCh(IocVerifier $verifier): array
    {
        if (empty($verifier->api_key)) {
            return ['ok' => false, 'detail' => 'No auth key configured.'];
        }

        try {
            // MalwareBazaar lives at mb-api.abuse.ch — the old mbazaar. subdomain
            // is retired (DNS no longer resolves). All abuse.ch APIs require the
            // Auth-Key HTTP header.
            $response = Http::timeout(15)
                ->withHeaders(['Auth-Key' => trim((string) $verifier->api_key)])
                ->asForm()
                ->post('https://mb-api.abuse.ch/api/v1/', [
                    'query' => 'get_info',
                    'hash' => '094fd325049b8a9cf6d3e5ef2a6d4cc6a567d7d49c35f8bb8dd9e3c6acf3d78d',
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'detail' => 'Connection failed: '.$e->getMessage()];
        }

        // Rejections come back as HTTP 403 with a JSON query_status — surface it.
        $status = $response->json('query_status');

        if (! $response->successful()) {
            return ['ok' => false, 'detail' => 'MalwareBazaar rejected the request (HTTP '.$response->status().($status ? ', '.$status : '').').'];
        }

        $rejected = ['no_auth_key', 'no_api_key', 'unknown_auth_key', 'auth_key_invalid', 'user_blacklisted', 'no_auth'];

        if (in_array($status, $rejected, true)) {
            return ['ok' => false, 'detail' => 'Auth key rejected by MalwareBazaar ('.$status.').'];
        }

        // hash_not_found or an actual sample record both prove the key is valid.
        return ['ok' => true, 'detail' => 'Authenticated OK — MalwareBazaar responded: query_status='.$status.'.'];
    }
}
