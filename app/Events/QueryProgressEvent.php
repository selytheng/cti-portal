<?php

namespace App\Events;

use App\Models\QueryRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Streams live Query Hub pipeline progress over Reverb (spec Section 4).
 */
class QueryProgressEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public QueryRun $run)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('query-runs')];
    }

    public function broadcastAs(): string
    {
        return 'progress';
    }

    public function broadcastWith(): array
    {
        return ['run' => [
            'id' => $this->run->id,
            'uuid' => $this->run->uuid,
            'status' => $this->run->status,
            'current_stage' => $this->run->current_stage,
            'progress_percent' => $this->run->progress_percent,
            'lookback_days' => $this->run->lookback_days,
            'feeds_processed' => $this->run->feeds_processed,
            'items_in_date_window' => $this->run->items_in_date_window,
            'items_filtered_exception' => $this->run->items_filtered_exception,
            'duplicates_skipped' => $this->run->duplicates_skipped,
            'ctis_created' => $this->run->ctis_created,
            'ctis_updated' => $this->run->ctis_updated,
            'triggered_by' => $this->run->user?->name ?? 'System Scheduler',
        ]];
    }
}
