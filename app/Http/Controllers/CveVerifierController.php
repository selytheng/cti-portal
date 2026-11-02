<?php

namespace App\Http\Controllers;

use App\Models\CveVerifier;
use App\Services\CveVerifierService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CveVerifierController extends Controller
{
    public function update(Request $request, CveVerifier $cveVerifier)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:4096'],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'request_interval_seconds' => ['required', 'integer', 'min:1', 'max:60'],
            'auto_verify' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! $request->filled('api_key')) {
            unset($data['api_key']);
        }

        $cveVerifier->update($data);

        activity()->causedBy($request->user())->event('updated')
            ->log("Updated CVE verifier {$cveVerifier->service} configuration");

        return back()->with('success', 'CVE verification configuration saved.');
    }

    public function test(CveVerifier $cveVerifier, CveVerifierService $service)
    {
        $result = $service->test($cveVerifier);

        return back()->with('testResult', ['type' => 'cve', 'service' => $cveVerifier->service, ...$result]);
    }
}
