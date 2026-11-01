<?php

namespace App\Console\Commands;

use App\Jobs\RunIntelligenceQueryJob;
use App\Models\QueryRun;
use App\Models\RssFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PollFeeds extends Command
{
    protected $signature = 'cti:poll-feeds';

    protected $description = 'Dispatch scheduled intelligence queries for feeds whose polling interval elapsed';

    public function handle(): void
    {
        $due = RssFeed::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (RssFeed $feed) => ! $feed->last_fetched_at
                || $feed->last_fetched_at->lt(now()->subMinutes($feed->polling_interval_minutes)));

        foreach ($due as $feed) {
            $run = QueryRun::create([
                'uuid' => (string) Str::uuid(),
                'trigger_type' => 'scheduled',
                'lookback_days' => 3,
                'feed_ids' => [$feed->id],
                'use_keywords' => true,
                'match_existing' => true,
                'status' => 'queued',
            ]);

            RunIntelligenceQueryJob::dispatch($run);

            $this->info("Dispatched scheduled query for {$feed->name}.");
        }
    }
}
