<?php

namespace App\Http\Controllers;

use App\Models\CtiIoc;
use App\Models\CtiRecord;
use App\Models\IocVerifier;
use App\Models\QueryRun;
use App\Models\RssFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Executive & Operational Dashboard (spec Section 3): KPI cards, threat
     * velocity, severity/TLP distribution, top actors, pipeline telemetry.
     */
    public function index(Request $request)
    {
        $now = now();

        // ===== KPI cards =====
        $kpis = [
            'total' => CtiRecord::count(),
            'last24h' => CtiRecord::where('created_at', '>=', $now->copy()->subDay())->count(),
            'last7d' => CtiRecord::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'last30d' => CtiRecord::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'recentlyUpdated' => CtiRecord::whereColumn('modified_at', '>', 'created_at')->count(),
            'criticalHigh' => CtiRecord::whereIn('severity', ['Critical', 'High'])->count(),
            'maliciousIocs' => CtiIoc::where('verdict', 'Malicious')->count(),
            'totalIocs' => CtiIoc::count(),
            'developing' => CtiRecord::where('is_developing', true)->count(),
            'kevCves' => CtiRecord::whereRaw("cves::text ILIKE '%\"kev\": \"Yes\"%'")->count(),
            'activeFeeds' => RssFeed::where('is_active', true)->count(),
            'failingFeeds' => RssFeed::where('failure_count', '>', 0)->count(),
            'activeVerifiers' => IocVerifier::where('is_active', true)->count(),
            'queuedRuns' => QueryRun::where('status', 'queued')->count(),
            'failedRuns24h' => QueryRun::where('status', 'failed')->where('completed_at', '>=', $now->copy()->subDay())->count(),
        ];

        // ===== Threat velocity (last 14 days, Vul vs Mal) =====
        $records = CtiRecord::query()
            ->where('created_at', '>=', $now->copy()->subDays(13)->startOfDay())
            ->get(['created_at', 'type']);

        $velocity = collect(range(13, 0))->map(function (int $i) use ($records) {
            $day = now()->copy()->subDays($i)->toDateString();

            $dayRecords = $records->filter(fn ($r) => $r->created_at->toDateString() === $day);

            return [
                'date' => $day,
                'vul' => $dayRecords->where('type', 'Vul')->count(),
                'mal' => $dayRecords->where('type', 'Mal')->count(),
            ];
        })->values();

        // ===== Distributions =====
        $severityDist = CtiRecord::query()->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity');
        $tlpDist = CtiRecord::query()->selectRaw('tlp, count(*) as c')->groupBy('tlp')->pluck('c', 'tlp');
        $iocVerdicts = CtiIoc::query()->selectRaw('verdict, count(*) as c')->groupBy('verdict')->pluck('c', 'verdict');

        // ===== Top threat actors & focus-keyword matches =====
        $topActors = CtiRecord::query()
            ->whereNotNull('threat_actors')
            ->get(['threat_actors'])
            ->flatMap(fn ($r) => $r->threat_actors ?? [])
            ->countBy()
            ->sortDesc()
            ->take(8);

        $topFocusKeywords = CtiRecord::query()
            ->whereNotNull('matched_focus_keywords')
            ->get(['matched_focus_keywords'])
            ->flatMap(fn ($r) => $r->matched_focus_keywords ?? [])
            ->countBy()
            ->sortDesc()
            ->take(8);

        // ===== Pipeline telemetry =====
        $feedHealth = RssFeed::query()
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'is_active', 'last_fetched_at', 'last_status_code', 'failure_count']);

        $recentRuns = QueryRun::query()
            ->with('user:id,name')
            ->latest()
            ->take(6)
            ->get(['id', 'status', 'trigger_type', 'lookback_days', 'current_stage', 'progress_percent',
                'items_in_date_window', 'ctis_created', 'ctis_updated', 'started_at', 'completed_at', 'triggered_by_user_id']);

        return Inertia::render('Dashboard', [
            'kpis' => $kpis,
            'velocity' => $velocity,
            'severityDist' => $severityDist,
            'tlpDist' => $tlpDist,
            'iocVerdicts' => $iocVerdicts,
            'topActors' => $topActors,
            'topFocusKeywords' => $topFocusKeywords,
            'feedHealth' => $feedHealth,
            'recentRuns' => $recentRuns,
        ]);
    }
}
