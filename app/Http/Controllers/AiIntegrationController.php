<?php

namespace App\Http\Controllers;

use App\Models\AiIntegration;
use App\Services\AiClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiIntegrationController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $integration = AiIntegration::create($data);
        $this->enforceSingleDefault($integration);

        activity()->causedBy($request->user())->event('created')
            ->log("Added AI integration {$integration->name} ({$integration->provider})");

        return back()->with('success', "AI integration {$integration->name} saved.");
    }

    public function update(Request $request, AiIntegration $aiIntegration)
    {
        $data = $this->validated($request, $aiIntegration);
        $aiIntegration->update($data);
        $this->enforceSingleDefault($aiIntegration);

        activity()->causedBy($request->user())->event('updated')
            ->log("Updated AI integration {$aiIntegration->name}");

        return back()->with('success', "AI integration {$aiIntegration->name} saved.");
    }

    public function destroy(Request $request, AiIntegration $aiIntegration)
    {
        $name = $aiIntegration->name;
        $aiIntegration->delete();

        activity()->causedBy($request->user())->event('deleted')
            ->log("Deleted AI integration {$name}");

        return back()->with('success', "AI integration {$name} deleted.");
    }

    /**
     * "Test AI Connection" (spec 5.2): sends a sample cyber-news snippet and
     * returns the model's structured JSON for rendering in the UI.
     */
    public function test(AiIntegration $aiIntegration, AiClient $client)
    {
        try {
            $result = $client->complete($aiIntegration);

            return back()->with('testResult', [
                'type' => 'ai',
                'ai_id' => $aiIntegration->id,
                'name' => $aiIntegration->name,
                'parsed' => $result['parsed_json'],
                'raw' => $result['raw'],
            ]);
        } catch (\Throwable $e) {
            return back()->with('testResult', [
                'type' => 'ai',
                'ai_id' => $aiIntegration->id,
                'name' => $aiIntegration->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function validated(Request $request, ?AiIntegration $existing = null): array
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(AiIntegration::PROVIDERS)],
            'name' => ['required', 'string', 'max:255'],
            // Absent on update = keep the stored key.
            'api_key' => [$existing ? 'nullable' : 'required', 'string', 'max:4096'],
            'base_url' => [
                'nullable', 'url', 'max:2048',
                Rule::requiredIf(in_array($request->input('provider'), ['openrouter', 'custom'])),
            ],
            'model_id' => ['required', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:20000'],
            'merge_prompt' => ['nullable', 'string', 'max:20000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:16', 'max:100000'],
            'timeout' => ['nullable', 'integer', 'min:5', 'max:300'],
            'is_default' => ['required', 'boolean'],
            'is_fallback' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($existing && ! $request->filled('api_key')) {
            unset($data['api_key']);
        }

        return $data;
    }

    private function enforceSingleDefault(AiIntegration $integration): void
    {
        if ($integration->is_default) {
            AiIntegration::query()
                ->whereKeyNot($integration->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
