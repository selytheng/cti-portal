<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    /**
     * Immutable audit trail viewer (spec Section 2: every login, CTI edit,
     * configuration change, query trigger and report export, with diffs).
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        if (! in_array($perPage, [20, 50, 100, 1000], true)) {
            $perPage = 20;
        }

        $likeOp = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $activities = Activity::query()
            ->with(['causer:id,name,email', 'subject:id'])
            ->when(
                $request->input('event'),
                fn ($q, $e) => $q->where('event', $e)
            )
            ->when(
                $request->input('causer'),
                fn ($q, $c) => $q->whereHas('causer', fn ($cq) => $cq->where('email', $likeOp, "%{$c}%"))
            )
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'activities' => $activities,
            'filters' => array_merge($request->only(['event', 'causer']), ['per_page' => $perPage]),
        ]);
    }
}
