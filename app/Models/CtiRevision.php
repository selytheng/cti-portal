<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CtiRevision extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'cti_record_id', 'query_run_id', 'updated_by_user_id', 'source_url',
        'fields_updated', 'previous_values', 'new_values', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'fields_updated' => 'array',
            'previous_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ctiRecord(): BelongsTo
    {
        return $this->belongsTo(CtiRecord::class);
    }
}
