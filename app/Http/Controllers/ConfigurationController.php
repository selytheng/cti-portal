<?php

namespace App\Http\Controllers;

use App\Models\AiIntegration;
use App\Models\IocVerifier;
use App\Models\KeywordRule;
use App\Models\ReportTemplate;
use App\Models\RssFeed;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ConfigurationController extends Controller
{
    /**
     * Configuration Center (spec Section 5) — one page, five tabs.
     * API keys never leave the server: only has_api_key flags are exposed.
     */
    public function index(Request $request)
    {
        $ai = AiIntegration::query()->orderBy('name')->get()->map(fn (AiIntegration $i) => [
            'id' => $i->id,
            'provider' => $i->provider,
            'name' => $i->name,
            'base_url' => $i->base_url,
            'model_id' => $i->model_id,
            'system_prompt' => $i->system_prompt,
            'merge_prompt' => $i->merge_prompt,
            'temperature' => $i->temperature,
            'max_tokens' => $i->max_tokens,
            'timeout' => $i->timeout,
            'is_default' => $i->is_default,
            'is_fallback' => $i->is_fallback,
            'is_active' => $i->is_active,
            'has_api_key' => filled($i->getRawOriginal('api_key')),
        ]);

        $verifiers = IocVerifier::query()->orderBy('service')->get()->map(fn (IocVerifier $v) => [
            'id' => $v->id,
            'service' => $v->service,
            'base_url' => $v->base_url,
            'sub_services' => $v->sub_services ?? [],
            'cache_ttl_minutes' => $v->cache_ttl_minutes,
            'auto_enrich' => $v->auto_enrich,
            'is_active' => $v->is_active,
            'has_api_key' => filled($v->getRawOriginal('api_key')),
        ]);

        return Inertia::render('Configuration/Index', [
            'feeds' => RssFeed::query()->orderBy('name')->get(),
            'aiIntegrations' => $ai,
            'verifiers' => $verifiers,
            'keywordRules' => KeywordRule::query()->orderBy('rule_type')->orderBy('keyword')->get(),
            // Unwrap the ['value' => x] wrapper from SystemSetting::put so the
            // frontend sees plain booleans/strings.
            'settings' => SystemSetting::query()->get()->mapWithKeys(fn (SystemSetting $s) => [
                $s->key => (is_array($s->value) && array_keys($s->value) === ['value']) ? $s->value['value'] : $s->value,
            ]),
            'templates' => ReportTemplate::query()->with('author:id,name')->get(),
            'variables' => ReportTemplate::VARIABLES,
        ]);
    }

    // ===== Application branding (sidebar / login logo, spec Section 5 spirit) =====

    public function uploadAppLogo(Request $request)
    {
        $data = $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $path = $data['logo']->store('branding', 'public');

        $previous = SystemSetting::get('app_logo_path');
        if ($previous && Storage::disk('public')->exists($previous)) {
            Storage::disk('public')->delete($previous);
        }

        SystemSetting::put('app_logo_path', $path);

        activity()->causedBy($request->user())->event('updated')
            ->log('Updated application logo');

        return back()->with('success', 'Application logo updated — it now appears across the portal.');
    }

    public function resetAppLogo(Request $request)
    {
        $previous = SystemSetting::get('app_logo_path');
        if ($previous && Storage::disk('public')->exists($previous)) {
            Storage::disk('public')->delete($previous);
        }

        SystemSetting::put('app_logo_path', null);

        activity()->causedBy($request->user())->event('updated')
            ->log('Reset application logo to default');

        return back()->with('success', 'Application logo reset to the default mark.');
    }
}
