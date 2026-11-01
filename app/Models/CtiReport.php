<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CtiReport extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'cti_record_id', 'template_id', 'generated_by_user_id',
        'title', 'cti_record_ids', 'file_path', 'format', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cti_record_ids' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ctiRecord(): BelongsTo
    {
        return $this->belongsTo(CtiRecord::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
