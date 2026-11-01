<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTemplate extends Model
{
    use HasFactory;

    /** Template variables from spec Section 5.5. */
    public const VARIABLES = [
        'cti_id', 'subject', 'type', 'severity', 'tlp',
        'published_at', 'modified_at', 'sources', 'summary',
        'technical_analysis', 'cves_table', 'iocs_table',
        'impact_hardware', 'impact_software', 'threat_actor',
        'target_object', 'mitigation', 'logo_url', 'generated_by', 'generated_at',
    ];

    protected $fillable = [
        'name',
        'html_content',
        'css_styles',
        'logo_path',
        'watermark_text',
        'is_default',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
