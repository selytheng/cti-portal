<?php

namespace App\Jobs;

use App\Events\QueryProgressEvent;
use App\Models\CtiIoc;
use App\Models\CtiRecord;
use App\Models\CveVerifier;
use App\Models\QueryRun;
use App\Models\RssFeed;
use App\Services\CtiExtractor;
use App\Services\CveVerifierService;
use App\Services\FeedParser;
use App\Services\IocCheckService;
use App\Services\KeywordMatcher;
use App\Services\StoryCorrelator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingestion pipeline orchestrator (spec Sections 4 + 8):
 * Fetching RSS → Filtering by Days Ago & Keywords → Correlating & Extracting
 * via AI → Merging/Updating Existing CTIs → Verifying IOCs → Completed.
 *
 * Runs sequentially per query with stage-level progress broadcasting so the
 * Live Active Queries Panel tracks each run in real time.
 */
class RunIntelligenceQueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public QueryRun $run)
    {
    }

    public function handle(FeedParser $parser, IocCheckService $iocCheck, StoryCorrelator $correlator, CveVerifierService $cveService): void
    {
        $run = $this->run->refresh();

        if ($run->status === 'cancelled') {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'current_stage' => 'Fetching RSS',
            'progress_percent' => 5,
        ]);
        broadcast(new QueryProgressEvent($run->fresh()));

        $matcher = KeywordMatcher::fromDatabase();
        $extractor = app(CtiExtractor::class);
        $ai = $run->ai_integration_id
            ? $extractor->defaultIntegration()?->find($run->ai_integration_id) ?? null
            : $extractor->defaultIntegration();

        if ($ai) {
            $run->update(['ai_provider_used' => $ai->provider.' / '.$ai->model_id]);
        }

        $feeds = RssFeed::query()
            ->where('is_active', true)
            ->when($run->feed_ids, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        $totalFeeds = max($feeds->count(), 1);
        $windowStart = now()->subDays($run->lookback_days);
        $createdIds = [];
        $updatedIds = [];

        foreach ($feeds as $index => $feed) {
            if ($this->cancelled()) {
                return;
            }

            try {
                $parsed = $parser->parse($feed->url, $feed->custom_headers ?? []);
                $feed->update(['last_fetched_at' => now(), 'last_status_code' => 200, 'failure_count' => 0]);
            } catch (Throwable) {
                $feed->update(['last_fetched_at' => now(), 'last_status_code' => 0, 'failure_count' => $feed->failure_count + 1]);
                $run->increment('feeds_processed');

                continue;
            }

            foreach ($parsed['items'] as $item) {
                if ($this->cancelled()) {
                    return;
                }

                $run->increment('items_fetched');

                $published = null;
                if (! empty($item['published_at'])) {
                    try {
                        $published = Carbon::parse($item['published_at']);
                    } catch (Throwable) {
                        $published = null;
                    }
                }

                // "Days ago" window filter (spec 4). Undated items count as first-seen now.
                if ($published && $published->lt($windowStart)) {
                    continue;
                }
                $run->increment('items_in_date_window');

                $url = $item['url'] ?? '';
                $contentHash = hash('sha256', ($item['title'] ?? '').'|'.$url);

                // Keyword evaluation BEFORE AI extraction (spec 5.4).
                if ($run->use_keywords) {
                    $kw = $matcher->evaluate(($item['title'] ?? '').' '.$url);

                    if ($kw['dropped']) {
                        $run->increment('items_filtered_exception');

                        continue;
                    }
                } else {
                    $kw = ['matchedFocus' => [], 'boost' => 0];
                }

                $extracted = $extractor->extract(
                    $item['title'] ?? 'Untitled',
                    $url,
                    $feed->name,
                    $published?->toIso8601String(),
                    $ai
                );
                $data = $extracted['data'];

                // Prioritize & highlight mode: focus matches boost severity.
                if ($kw['boost'] > 0 && $kw['matchedFocus'] !== []) {
                    $sevOrder = array_values(CtiRecord::SEVERITIES);
                    $idx = array_search($data['severity'], $sevOrder, true);
                    $data['severity'] = $sevOrder[max(0, ($idx === false ? 4 : $idx) - $kw['boost'])];
                }

                // Stage 1 — same-URL content update detection (spec 8.1).
                $existing = $correlator->findBySourceUrl($url);

                if ($existing) {
                    $sourceEntry = $existing->hasSourceUrl($url);

                    if (($sourceEntry['content_hash'] ?? null) === $contentHash) {
                        $run->increment('duplicates_skipped');

                        continue;
                    }
                } elseif ($run->match_existing) {
                    // Stage 2/3 — cross-source candidate matching + AI merge decision.
                    $candidates = $correlator->findCandidates($data['cves'], $data['iocs'], $data['subject']);

                    foreach ($candidates as $candidate) {
                        if ($correlator->shouldMerge($candidate, $data, $ai)) {
                            $existing = $candidate;

                            break;
                        }
                    }
                }

                $source = [
                    'name' => $feed->name,
                    'url' => $url,
                    'feed_id' => $feed->id,
                    'published_at' => $published?->toIso8601String(),
                    'content_hash' => $contentHash,
                ];

                if ($existing) {
                    $merged = $correlator->merge($existing, $data, $source, $run);

                    if (! in_array($existing->id, $updatedIds)) {
                        $updatedIds[] = $existing->id;
                    }

                    if ($merged) {
                        $run->increment('ctis_updated');
                    }
                } else {
                    $record = $this->createCti($feed, $item, $published, $data, $contentHash, $run, $kw['matchedFocus']);
                    $createdIds[] = $record->id;
                    $run->increment('ctis_created');
                }
            }

            $run->increment('feeds_processed');
            $run->update([
                'current_stage' => 'Correlating & Extracting via AI',
                'progress_percent' => min(80, 5 + (int) ((($index + 1) / $totalFeeds) * 70)),
            ]);
            broadcast(new QueryProgressEvent($run->fresh()));
        }

        if ($this->cancelled()) {
            return;
        }

        // Stage: IOC verification for this run's CTIs (spec 8: new IOCs only).
        $run->update(['current_stage' => 'Verifying IOCs', 'progress_percent' => 85]);
        broadcast(new QueryProgressEvent($run->fresh()));

        $targetIds = array_merge($createdIds, $updatedIds);

        if ($targetIds !== []) {
            CtiIoc::query()
                ->whereIn('cti_record_id', $targetIds)
                ->where(function ($q) {
                    $q->whereNull('last_verified_at')->orWhere('verdict', 'Unknown');
                })
                ->each(fn (CtiIoc $ioc) => $iocCheck->verifyIoc($ioc));
        }

        // Stage: CVE verification against trust sources (NVD/KEV/EPSS) —
        // mirrors how IOCs are verified in-pipeline.
        $run->update(['current_stage' => 'Verifying CVEs', 'progress_percent' => 92]);
        broadcast(new QueryProgressEvent($run->fresh()));

        if ($targetIds !== []) {
            $verifier = CveVerifier::query()->where('service', 'nvd')->first();

            if ($verifier) {
                CtiRecord::query()
                    ->whereIn('id', $targetIds)
                    ->whereRaw("jsonb_array_length(coalesce(cves, '[]'::jsonb)) > 0")
                    ->each(fn (CtiRecord $record) => $cveService->verifyRecord($record, $verifier));
            }
        }

        $run->update([
            'status' => 'completed',
            'current_stage' => 'Completed',
            'progress_percent' => 100,
            'completed_at' => now(),
            'created_cti_ids' => $createdIds ?: null,
            'updated_cti_ids' => $updatedIds ?: null,
        ]);
        broadcast(new QueryProgressEvent($run->fresh()));

        $this->raiseAlerts($createdIds);
    }

    /** Alert hook (spec 8): focus-keyword match + Critical/High severity. */
    private function raiseAlerts(array $createdIds): void
    {
        CtiRecord::query()
            ->whereIn('id', $createdIds)
            ->whereIn('severity', ['Critical', 'High'])
            ->whereNotNull('matched_focus_keywords')
            ->get()
            ->each(function (CtiRecord $record) {
                activity()->event('alert')->log(sprintf(
                    'ALERT: %s [%s] matched focus keywords: %s',
                    $record->cti_id,
                    $record->severity,
                    implode(', ', $record->matched_focus_keywords ?? [])
                ));
            });
    }

    private function cancelled(): bool
    {
        return $this->run->fresh()->status === 'cancelled';
    }

    private function createCti(RssFeed $feed, array $item, ?Carbon $published, array $data, string $contentHash, $run, array $focusKeywords): CtiRecord
    {
        $seq = CtiRecord::query()->whereYear('created_at', now()->year)->count() + 1;

        $record = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'subject' => $data['subject'],
            'type' => $data['type'],
            'subtypes' => $data['subtypes'] ?: null,
            'sources' => [[
                'name' => $feed->name,
                'url' => $item['url'] ?? null,
                'feed_id' => $feed->id,
                'published_at' => $published?->toIso8601String(),
                'content_hash' => $contentHash,
            ]],
            'published_at' => $published ?? now(),
            'modified_at' => now(),
            'is_developing' => true,
            'revision_count' => 1,
            'tlp' => 'TLP:CLEAR',
            'workflow_status' => 'New',
            'confidence_score' => $data['confidence'],
            'cves' => $data['cves'] ?: null,
            'exploit_status' => $data['exploit_status'],
            'severity' => $data['severity'],
            'summary' => $data['summary'],
            'technical_analysis' => $data['technical_analysis'],
            'impact_hardware' => $data['impact_hardware'] ?: null,
            'impact_software' => $data['impact_software'] ?: null,
            'threat_actors' => $data['threat_actors'] ?: null,
            'target_objects' => $data['target_objects'] ?: null,
            'mitigation' => $data['mitigation'],
            'matched_focus_keywords' => $focusKeywords ?: null,
            'latest_query_run_id' => $run->id,
        ]);

        foreach ($data['iocs'] ?? [] as $ioc) {
            $record->iocs()->firstOrCreate(
                ['ioc_type' => $ioc['type'], 'value' => $ioc['value']],
                ['defanged_value' => CtiIoc::defang($ioc['value'], $ioc['type'])]
            );
        }

        $record->refreshDevelopingFlag();
        $record->save();

        return $record;
    }

    public function failed(Throwable $e): void
    {
        $this->run->update([
            'status' => 'failed',
            'error_log' => mb_substr($e->getMessage(), 0, 2000),
            'completed_at' => now(),
        ]);
        broadcast(new QueryProgressEvent($this->run->fresh()));
    }
}
