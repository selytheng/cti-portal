<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueryRun extends Model
{
    use HasFactory;

    public const STATUSES = ['queued', 'running', 'completed', 'failed', 'cancelled'];

    protected $fillable = [
        'uuid', 'triggered_by_user_id', 'trigger_type', 'lookback_days', 'feed_ids',
        'status', 'current_stage', 'progress_percent', 'feeds_processed',
        'items_fetched', 'items_in_date_window', 'items_filtered_exception',
        'duplicates_skipped', 'ctis_created', 'ctis_updated',
        'created_cti_ids', 'updated_cti_ids', 'ai_provider_used',
        'error_log', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'feed_ids' => 'array',
            'created_cti_ids' => 'array',
            'updated_cti_ids' => 'array',
            'lookback_days' => 'integer',
            'progress_percent' => 'integer',
            'feeds_processed' => 'integer',
            'items_fetched' => 'integer',
            'items_in_date_window' => 'integer',
            'items_filtered_exception' => 'integer',
            'duplicates_skipped' => 'integer',
            'ctis_created' => 'integer',
            'ctis_updated' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running']);
    }
}
