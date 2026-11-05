<?php

namespace App\Http\Controllers;

use App\Events\QueryProgressEvent;
use App\Jobs\RunIntelligenceQueryJob;
use App\Models\AiIntegration;
use App\Models\QueryRun;
use App\Models\RssFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class QueryHubController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        if (! in_array($perPage, [20, 50, 100, 1000], true)) {
            $perPage = 20;
        }

        return Inertia::render('QueryHub/Index', [
            'activeQueries' => QueryRun::query()
                ->with('user:id,name,email')
                ->whereIn('status', ['queued', 'running'])
                ->latest()
                ->limit(25)
                ->get(),
            'history' => QueryRun::query()
                ->with('user:id,name')
                ->whereIn('status', ['completed', 'failed', 'cancelled'])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),
            'perPage' => $perPage,
            'feeds' => RssFeed::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'aiIntegrations' => AiIntegration::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'name', 'provider', 'model_id', 'is_default']),
            'canExecute' => $request->user()->can('query.execute'),
        ]);
    }

    /**
     * "Run Intelligence Query" (spec 4): creates the run, dispatches the
     * pipeline job, live progress streams over Reverb.
     */
    public function store(Request $request)
    {
        abort_unless($request->user()->can('query.execute'), 403);

        $data = $request->validate([
            'lookback_days' => ['required', 'integer', 'min:1', 'max:365'],
            'feed_ids' => ['nullable', 'array'],
            'feed_ids.*' => ['integer', 'exists:rss_feeds,id'],
            'use_keywords' => ['required', 'boolean'],
            'ai_integration_id' => ['nullable', 'exists:ai_integrations,id'],
            'match_existing' => ['required', 'boolean'],
            'match_lookback_days' => ['nullable', 'integer', 'in:1,7,30'],
        ]);

        $run = QueryRun::create([
            'uuid' => (string) Str::uuid(),
            'triggered_by_user_id' => $request->user()->id,
            'trigger_type' => 'manual',
            'lookback_days' => $data['lookback_days'],
            'feed_ids' => $data['feed_ids'] ?? null,
            'use_keywords' => $data['use_keywords'],
            'ai_integration_id' => $data['ai_integration_id'] ?? null,
            'match_existing' => $data['match_existing'],
            'match_lookback_days' => $data['match_lookback_days'] ?? 7,
            'status' => 'queued',
        ]);

        RunIntelligenceQueryJob::dispatch($run);

        activity()->causedBy($request->user())->event('query_triggered')
            ->log("Triggered intelligence query ({$data['lookback_days']} days ago)");

        return back()->with('success', 'Intelligence query dispatched — track it in the live panel below.');
    }

    public function cancel(Request $request, QueryRun $run)
    {
        abort_unless($run->isActive(), 422, 'Query is not active.');
        abort_unless(
            $request->user()->can('query.cancel-all')
                || ($request->user()->can('query.cancel-own') && $run->triggered_by_user_id === $request->user()->id),
            403
        );

        $run->update(['status' => 'cancelled', 'completed_at' => now()]);
        broadcast(new QueryProgressEvent($run->fresh()));

        activity()->causedBy($request->user())->event('query_cancelled')
            ->log('Cancelled intelligence query #'.$run->id);

        return back()->with('success', 'Query cancellation requested.');
    }
}
