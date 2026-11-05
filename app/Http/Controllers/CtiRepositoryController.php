<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyCveRepositoryJob;
use App\Models\CtiIoc;
use App\Models\CtiNote;
use App\Models\CtiRecord;
use App\Models\CtiReport;
use App\Models\CtiRevision;
use App\Models\CtiUserRead;
use App\Models\CveVerifier;
use App\Models\ReportTemplate;
use App\Services\CtiExportService;
use App\Services\CveVerifierService;
use App\Services\IocCheckService;
use App\Services\ReportRenderer;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CtiRepositoryController extends Controller
{
    /**
     * CTI Repository (spec Section 6): full-text search, multi-facet filters,
     * server-side pagination.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        if (! in_array($perPage, [20, 50, 100, 1000], true)) {
            $perPage = 20;
        }

        $driver = DB::getDriverName();
        $ilike = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $userId = $request->user()?->id;

        $records = CtiRecord::query()
            ->with('iocs:ioc_type,value,verdict,cti_record_id')
            ->leftJoin('cti_user_reads', function ($join) use ($userId) {
                $join->on('cti_records.id', '=', 'cti_user_reads.cti_record_id')
                    ->where('cti_user_reads.user_id', '=', $userId);
            })
            ->select('cti_records.*', 'cti_user_reads.read_at as user_read_at')
            ->when($request->input('search'), function ($q, $s) use ($driver, $ilike) {
                $q->where(function ($qq) use ($s, $driver, $ilike) {
                    if ($driver === 'pgsql') {
                        $qq->whereRaw("search @@ plainto_tsquery('english', ?)", [$s]);
                    } else {
                        $qq->where('subject', 'like', "%{$s}%")->orWhere('summary', 'like', "%{$s}%");
                    }

                    if (preg_match('/CVE-\d{4}-\d{4,}/i', $s)) {
                        $qq->orWhereRaw("cves::text {$ilike} ?", ['%'.$s.'%']);
                    }
                });
            })
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->when($request->input('tlp'), fn ($q, $v) => $q->where('tlp', $v))
            ->when($request->input('workflow_status'), fn ($q, $v) => $q->where('workflow_status', $v))
            ->when($request->boolean('developing'), fn ($q) => $q->where('is_developing', true))
            ->when($request->input('ioc_verdict'), fn ($q, $v) => $q->whereHas('iocs', fn ($iq) => $iq->where('verdict', $v)))
            ->when($request->input('source_feed'), fn ($q, $v) => $q->whereRaw("sources::text {$ilike} ?", ['%'.$v.'%']))
            ->when($request->boolean('focus_matched'), fn ($q) => $q->whereNotNull('matched_focus_keywords'))
            ->when($request->input('published_from'), fn ($q, $v) => $q->where('published_at', '>=', $v))
            ->when($request->input('published_to'), fn ($q, $v) => $q->where('published_at', '<=', $v.' 23:59:59'))
            ->when($request->input('modified_from'), fn ($q, $v) => $q->where('modified_at', '>=', $v))
            ->when($request->input('modified_to'), fn ($q, $v) => $q->where('modified_at', '<=', $v.' 23:59:59'))
            ->orderByRaw('CASE
                WHEN cti_user_reads.read_at IS NULL AND COALESCE(cti_records.revision_count, 1) <= 1 THEN 1
                WHEN (cti_user_reads.read_at IS NOT NULL AND cti_records.modified_at > cti_user_reads.read_at)
                     OR (cti_user_reads.read_at IS NULL AND cti_records.revision_count > 1) THEN 2
                ELSE 3
            END ASC')
            ->orderByDesc('cti_records.cti_id')
            ->orderByDesc('cti_records.id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('CtiRepository/Index', [
            'records' => $records,
            'filters' => array_merge($request->only([
                'search', 'type', 'severity', 'tlp', 'workflow_status', 'developing',
                'ioc_verdict', 'source_feed', 'focus_matched',
                'published_from', 'published_to', 'modified_from', 'modified_to',
            ]), ['per_page' => $perPage]),
            'templates' => ReportTemplate::query()->orderBy('name')->get(['id', 'name', 'is_default']),
        ]);
    }

    public function show(Request $request, CtiRecord $record)
    {
        if ($request->user()) {
            CtiUserRead::updateOrCreate(
                ['user_id' => $request->user()->id, 'cti_record_id' => $record->id],
                ['read_at' => now()]
            );
        }

        $record->load([
            'iocs',
            'revisions' => fn ($q) => $q->with('updatedBy:id,name')->latest('created_at'),
            'notes' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        return Inertia::render('CtiRepository/Show', [
            'record' => $record,
            'templates' => ReportTemplate::query()->orderBy('name')->get(['id', 'name', 'is_default']),
        ]);
    }

    public function markAsRead(Request $request, CtiRecord $record)
    {
        if ($request->user()) {
            CtiUserRead::updateOrCreate(
                ['user_id' => $request->user()->id, 'cti_record_id' => $record->id],
                ['read_at' => now()]
            );
        }

        return back();
    }

    public function bulkMarkAsRead(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:cti_records,id'],
        ]);

        $now = now();
        $records = collect($data['ids'])->map(fn ($id) => [
            'user_id' => $request->user()->id,
            'cti_record_id' => $id,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        CtiUserRead::upsert($records, ['user_id', 'cti_record_id'], ['read_at', 'updated_at']);

        return back()->with('success', count($data['ids']).' CTI record(s) marked as read.');
    }

    public function edit(CtiRecord $record)
    {
        $record->load('iocs');

        return Inertia::render('CtiRepository/Edit', [
            'record' => $record,
        ]);
    }

    /**
     * Analyst edit (spec Section 7): revision-tracked, bumps modified_at and
     * revision_count, refreshes the developing flag.
     */
    public function update(Request $request, CtiRecord $record)
    {
        $data = $this->validated($request);

        $before = $record->only(array_keys($data));
        $changes = collect($data)->filter(fn ($v, $k) => $record->{$k} != $v)->all();

        $record->fill($changes);
        $record->modified_at = now();
        $record->revision_count += 1;
        $record->refreshDevelopingFlag();
        $record->save();

        // Optional new IOCs added from the editor.
        $iocsAdded = 0;
        foreach ($data['iocs_add'] ?? [] as $ioc) {
            $record->iocs()->firstOrCreate(
                ['ioc_type' => $ioc['type'], 'value' => $ioc['value']],
                ['defanged_value' => CtiIoc::defang($ioc['value'], $ioc['type'])]
            );
            $iocsAdded++;
        }

        if ($iocsAdded > 0) {
            $changes['iocs'] = "+{$iocsAdded} IOC(s)";
        }

        CtiRevision::create([
            'cti_record_id' => $record->id,
            'updated_by_user_id' => $request->user()->id,
            'fields_updated' => array_keys($changes) ?: ['no-op'],
            'previous_values' => $before,
            'new_values' => $record->only(array_keys($data)),
            'created_at' => now(),
        ]);

        activity()->causedBy($request->user())->performedOn($record)->event('updated')
            ->log("Edited CTI {$record->cti_id}");

        return redirect()->route('cti.show', $record)->with('success', "CTI {$record->cti_id} updated (Rev {$record->revision_count}).");
    }

    public function destroy(Request $request, CtiRecord $record)
    {
        $ctiId = $record->cti_id;
        $record->delete();

        activity()->causedBy($request->user())->event('deleted')->log("Deleted CTI {$ctiId}");

        return redirect()->route('cti.index')->with('success', "CTI {$ctiId} deleted.");
    }

    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:cti_records,id'],
            'workflow_status' => ['required', Rule::in(CtiRecord::WORKFLOW_STATUSES)],
        ]);

        CtiRecord::query()->whereIn('id', $data['ids'])->update([
            'workflow_status' => $data['workflow_status'],
            'modified_at' => now(),
        ]);

        activity()->causedBy($request->user())->event('bulk_updated')
            ->log('Bulk status change to '.$data['workflow_status'].' on '.count($data['ids']).' CTIs');

        return back()->with('success', 'Bulk status applied to '.count($data['ids']).' CTIs.');
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:cti_records,id'],
        ]);

        $count = count($data['ids']);
        CtiRecord::query()->whereIn('id', $data['ids'])->delete();

        activity()->causedBy($request->user())->event('bulk_deleted')
            ->log("Bulk deleted {$count} CTIs");

        return back()->with('success', "{$count} CTIs deleted.");
    }

    public function exportCsv(Request $request, CtiExportService $export)
    {
        $records = $this->recordsForExport($request);

        return response($export->toCsv($records), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="cti-export-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    public function exportStix(Request $request, CtiExportService $export)
    {
        $records = $this->recordsForExport($request);

        return response()->json($export->toStix($records), 200, [
            'Content-Disposition' => 'attachment; filename="cti-export-'.now()->format('Ymd-His').'.stix.json"',
        ]);
    }

    /** Machine-readable IOC feed for SIEM/EDR polling (spec Section 8). */
    public function iocFeed(Request $request)
    {
        $verdicts = collect(explode(',', (string) $request->input('verdict', 'Malicious')))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => in_array($v, CtiIoc::VERDICTS, true))
            ->values();

        if ($verdicts->isEmpty()) {
            $verdicts = collect(['Malicious']);
        }

        $iocs = CtiIoc::query()
            ->whereIn('verdict', $verdicts->all())
            ->when($request->input('since'), fn ($q, $since) => $q->whereHas(
                'ctiRecord',
                fn ($cq) => $cq->where('modified_at', '>=', $since)
            ))
            ->with('ctiRecord:id,cti_id,subject,modified_at')
            ->get();

        return match ($request->input('format', 'txt')) {
            'csv' => response(collect([['ioc_type', 'value', 'verdict', 'cti_id']])
                ->concat($iocs->map(fn (CtiIoc $i) => [
                    $i->ioc_type, $i->value, $i->verdict, $i->ctiRecord?->cti_id,
                ]))
                ->map(fn (array $row) => implode(',', $row))
                ->implode("\n"), 200, ['Content-Type' => 'text/csv']),
            'stix' => response()->json((new CtiExportService)->toStix($iocs->map(fn ($i) => $i->ctiRecord)->unique('id')->filter())),
            default => response($iocs->pluck('value')->implode("\n"), 200, ['Content-Type' => 'text/plain']),
        };
    }

    /** "Quick Report" from a table row (spec Section 6). */
    public function quickReport(Request $request, CtiExportService $export, ReportRenderer $renderer)
    {
        $data = $this->validateReportRequest($request);
        $html = $this->renderReports(CtiRecord::query()->whereIn('id', $data['cti_ids'])->orderBy('cti_id')->get(), $data, $export, $renderer);

        return back()->with('testResult', ['type' => 'report_preview', 'html' => $html]);
    }

    /** Report preview from the detail view (spec Section 7). */
    public function preview(Request $request, CtiExportService $export, ReportRenderer $renderer)
    {
        $data = $this->validateReportRequest($request);
        $html = $this->renderReports(CtiRecord::query()->whereIn('id', $data['cti_ids'])->orderBy('cti_id')->get(), $data, $export, $renderer);

        return back()->with('testResult', ['type' => 'report_preview', 'html' => $html]);
    }

    /** Print-ready PDF via DomPDF (spec Section 7). */
    public function generatePdf(Request $request, CtiExportService $export, ReportRenderer $renderer)
    {
        $data = $this->validateReportRequest($request);

        $records = CtiRecord::query()->whereIn('id', $data['cti_ids'])->orderBy('cti_id')->get();
        $html = $this->renderReports($records, $data, $export, $renderer);

        $pdf = new Dompdf(['isRemoteEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();

        Storage::makeDirectory('reports');
        $path = 'reports/'.Str::uuid().'.pdf';
        Storage::put($path, $pdf->output());

        $report = CtiReport::create([
            'template_id' => $data['template_id'],
            'generated_by_user_id' => $request->user()->id,
            'title' => 'CTI Report — '.collect($data['cti_ids'])->count().' record(s)',
            'cti_record_ids' => $data['cti_ids'],
            'file_path' => $path,
            'format' => 'pdf',
            'created_at' => now(),
        ]);

        activity()->causedBy($request->user())->event('report_generated')
            ->log("Generated PDF report {$report->title}");

        if ($request->wantsJson()) {
            return response()->json([
                'download_url' => route('cti.report-download', $report),
            ]);
        }

        return back()->with('testResult', [
            'type' => 'report_pdf',
            'download_url' => route('cti.report-download', $report),
        ]);
    }

    public function downloadReport(CtiReport $report)
    {
        return Storage::download($report->file_path, Str::slug($report->title).'.pdf');
    }

    public function storeNote(Request $request, CtiRecord $record)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'applicability' => ['required', Rule::in(CtiNote::APPLICABILITY)],
        ]);

        CtiNote::create([
            'cti_record_id' => $record->id,
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return back()->with('success', 'Analyst note added.');
    }

    public function reverifyAll(CtiRecord $record, IocCheckService $iocCheck)
    {
        $record->iocs->each(fn (CtiIoc $ioc) => $iocCheck->verifyIoc($ioc));

        return back()->with('success', 'Re-verification complete for '.$record->iocs->count().' IOCs.');
    }

    public function reverifyOne(CtiIoc $ioc, IocCheckService $iocCheck)
    {
        $iocCheck->verifyIoc($ioc);

        return back()->with('success', 'IOC re-checked: '.$ioc->verdict);
    }

    // ===== CVE trust-source verification (NVD / CISA KEV / EPSS) =====

    public function verifyCves(Request $request, CtiRecord $record, CveVerifierService $service)
    {
        $verifier = CveVerifier::query()->where('service', 'nvd')->first();

        if (! $verifier) {
            return back()->withErrors(['cves' => 'CVE verifier (NVD) is not configured.']);
        }

        $enriched = $service->verifyRecord($record, $verifier);

        return back()->with('success', $enriched
            ? 'CVEs verified & enriched against NVD / CISA KEV / EPSS.'
            : 'CVEs were already up to date — nothing changed.');
    }

    public function verifyAllCves(Request $request)
    {
        VerifyCveRepositoryJob::dispatch();

        activity()->causedBy($request->user())->event('cve_verify_triggered')
            ->log('Triggered repository-wide CVE verification (NVD/KEV/EPSS)');

        return back()->with('success', 'Repository-wide CVE verification dispatched — Horizon will process it.');
    }

    // ===== Contributing sources management (spec Section 6 sources JSONB) =====

    public function addSource(Request $request, CtiRecord $record)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'author' => ['nullable', 'string', 'max:255'],
            'published_at' => ['nullable', 'date'],
        ]);

        if ($record->hasSourceUrl($data['url'])) {
            return back()->withErrors(['url' => 'This source URL is already attached to this CTI.']);
        }

        $source = [
            'name' => $data['name'],
            'url' => $data['url'],
            'author' => $data['author'] ?? null,
            'published_at' => $data['published_at'] ?? null,
        ];

        $record->addSource($source);

        $this->touchRevision($request, $record, ['sources'], ['added' => $source], "Added source {$data['url']}");

        return back()->with('success', 'Source added.');
    }

    public function updateSource(Request $request, CtiRecord $record)
    {
        $data = $request->validate([
            'original_url' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'author' => ['nullable', 'string', 'max:255'],
            'published_at' => ['nullable', 'date'],
        ]);

        $sources = $record->sources ?? [];
        $index = collect($sources)->search(fn (array $s) => ($s['url'] ?? null) === $data['original_url']);

        abort_if($index === false, 404, 'Source not found.');

        if ($data['url'] !== $data['original_url'] && $record->hasSourceUrl($data['url'])) {
            return back()->withErrors(['url' => 'Another source with this URL already exists on this CTI.']);
        }

        $previous = $sources[$index];
        $sources[$index] = array_merge($previous, [
            'name' => $data['name'],
            'url' => $data['url'],
            'author' => $data['author'] ?? null,
            'published_at' => $data['published_at'] ?? ($previous['published_at'] ?? null),
        ]);

        $record->sources = $sources;

        $this->touchRevision($request, $record, ['sources'], ['before' => $previous, 'after' => $sources[$index]], "Updated source {$data['url']}");

        return back()->with('success', 'Source updated.');
    }

    public function deleteSource(Request $request, CtiRecord $record)
    {
        $data = $request->validate(['url' => ['required', 'string']]);

        $sources = $record->sources ?? [];
        $index = collect($sources)->search(fn (array $s) => ($s['url'] ?? null) === $data['url']);

        abort_if($index === false, 404, 'Source not found.');

        $removed = $sources[$index];
        $record->sources = array_values(collect($sources)->forget($index)->all());

        $this->touchRevision($request, $record, ['sources'], ['removed' => $removed], "Removed source {$data['url']}");

        return back()->with('success', 'Source removed.');
    }

    /**
     * Analyst source edits follow the same revision discipline as pipeline
     * merges: bump modified_at/revision_count, write a cti_revisions diff row.
     */
    private function touchRevision(Request $request, CtiRecord $record, array $fieldsUpdated, array $details, string $description): void
    {
        $record->modified_at = now();
        $record->revision_count += 1;
        $record->save();

        CtiRevision::create([
            'cti_record_id' => $record->id,
            'updated_by_user_id' => $request->user()->id,
            'fields_updated' => $fieldsUpdated,
            'previous_values' => $details,
            'created_at' => now(),
        ]);

        activity()->causedBy($request->user())->performedOn($record)->event('updated')->log($description);
    }

    // ===== helpers =====

    private function recordsForExport(Request $request): Collection
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer', 'exists:cti_records,id'],
        ]);

        return CtiRecord::query()
            ->with('iocs')
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('cti_id')
            ->get();
    }

    private function validateReportRequest(Request $request): array
    {
        return $request->validate([
            'cti_ids' => ['required', 'array', 'min:1'],
            'cti_ids.*' => ['integer', 'exists:cti_records,id'],
            'template_id' => ['nullable', 'exists:report_templates,id'],
        ]) + ['template_id' => $request->input('template_id') ?: ReportTemplate::where('is_default', true)->value('id')];
    }

    private function renderReports(Collection $records, array $data, CtiExportService $export, ReportRenderer $renderer): string
    {
        $template = ReportTemplate::find($data['template_id']);
        $logoUrl = null;
        if ($template && $template->logo_path) {
            $logoUrl = $renderer->imageToBase64($template->logo_path) ?: Storage::url($template->logo_path);
        }

        $sections = $records->map(
            fn (CtiRecord $record) => $renderer->render(
                $template->html_content,
                $export->toRendererData($record->load('iocs')),
                $template->css_styles,
                $logoUrl,
                $template->watermark_text
            )
        );

        return $sections->implode('<div style="page-break-after: always;"></div>');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['Vul', 'Mal'])],
            'severity' => ['required', Rule::in(CtiRecord::SEVERITIES)],
            'tlp' => ['required', 'string', 'max:20'],
            'workflow_status' => ['required', Rule::in(CtiRecord::WORKFLOW_STATUSES)],
            'confidence_score' => ['required', 'integer', 'min:0', 'max:100'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'technical_analysis' => ['nullable', 'string', 'max:50000'],
            'mitigation' => ['nullable', 'string', 'max:20000'],
            'exploit_status' => ['nullable', Rule::in(CtiRecord::EXPLOIT_STATUSES)],
            'cves' => ['nullable', 'array'],
            'cves.*' => ['string', 'regex:/^CVE-\d{4}-\d{4,}$/i'],
            'impact_hardware' => ['nullable', 'array'],
            'impact_software' => ['nullable', 'array'],
            'threat_actors' => ['nullable', 'array'],
            'target_objects' => ['nullable', 'array'],
            'mitre_attack' => ['nullable', 'array'],
            'mitre_attack.*.id' => ['nullable', 'string', 'max:50'],
            'mitre_attack.*.name' => ['nullable', 'string', 'max:255'],
            'mitre_attack.*.tactic' => ['nullable', 'string', 'max:255'],
            'iocs_add' => ['nullable', 'array'],
            'iocs_add.*.type' => ['required_with:iocs_add', Rule::in(CtiIoc::TYPES)],
            'iocs_add.*.value' => ['required_with:iocs_add', 'string', 'max:512'],
        ]);
    }
}
