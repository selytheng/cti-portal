<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
        $activities = Activity::query()
            ->with(['causer:id,name,email', 'subject:id'])
            ->when(
                $request->input('event'),
                fn ($q, $e) => $q->where('event', $e)
            )
            ->when(
                $request->input('causer'),
                fn ($q, $c) => $q->whereHas('causer', fn ($cq) => $cq->where('email', 'ilike', "%{$c}%"))
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'activities' => $activities,
            'filters' => $request->only(['event', 'causer']),
        ]);
    }
}
