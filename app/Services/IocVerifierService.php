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

        $response = Http::timeout(15)
            ->withHeaders(['X-OTX-API-KEY' => (string) $verifier->api_key])
            ->get($base.'/users/me');

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

        // MalwareBazaar is the auth-aware endpoint of the abuse.ch family.
        $response = Http::timeout(15)->asForm()->post('https://mbazaar.abuse.ch/api/v1/', [
            'query' => 'get_recent',
            'selector' => 'time',
            'auth_key' => (string) $verifier->api_key,
        ]);

        if (! $response->successful()) {
            return ['ok' => false, 'detail' => 'HTTP '.$response->status().' from MalwareBazaar.'];
        }

        $status = $response->json('query_status');

        if ($status === 'no_auth' || $status === 'no_auth_key') {
            return ['ok' => false, 'detail' => 'Auth key rejected by MalwareBazaar.'];
        }

        if ($status === 'illegal_query') {
            return ['ok' => false, 'detail' => 'Query rejected — check sub-service configuration.'];
        }

        return ['ok' => true, 'detail' => 'MalwareBazaar responded: query_status='.$status.'.'];
    }
}
