<?php

namespace App\Http\Controllers;

use App\Models\IocVerifier;
use App\Services\IocVerifierService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IocVerifierController extends Controller
{
    public function update(Request $request, IocVerifier $iocVerifier)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:4096'],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'sub_services' => ['nullable', 'array'],
            'cache_ttl_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'auto_enrich' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! $request->filled('api_key')) {
            unset($data['api_key']);
        }

        $iocVerifier->update($data);

        activity()->causedBy($request->user())->event('updated')
            ->log("Updated IOC verifier {$iocVerifier->service}");

        return back()->with('success', ucfirst($iocVerifier->service).' verifier configuration saved.');
    }

    /** "Test OTX API" / "Test Abuse.ch API" buttons (spec 5.3). */
    public function test(IocVerifier $iocVerifier, IocVerifierService $service)
    {
        $result = $service->test($iocVerifier);

        return back()->with('testResult', [
            'type' => 'ioc',
            'verifier_id' => $iocVerifier->id,
            'service' => $iocVerifier->service,
            ...$result,
        ]);
    }
}
