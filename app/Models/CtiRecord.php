<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtiRecord extends Model
{
    use HasFactory;

    public const SEVERITIES = ['Critical', 'High', 'Medium', 'Low', 'Informational'];

    public const EXPLOIT_STATUSES = [
        'Unproven', 'PoC Available', 'Actively Exploited in the Wild', 'Weaponized',
    ];

    public const WORKFLOW_STATUSES = [
        'New', 'Developing', 'Under Review', 'Verified', 'Published', 'False Positive', 'Archived',
    ];

    protected $fillable = [
        'uuid', 'cti_id', 'subject', 'type', 'subtypes', 'sources',
        'published_at', 'modified_at', 'is_developing', 'revision_count',
        'tlp', 'workflow_status', 'confidence_score', 'cves', 'exploit_status',
        'severity', 'summary', 'technical_analysis', 'impact_hardware',
        'impact_software', 'threat_actors', 'target_objects', 'mitigation',
        'detection_rules', 'matched_focus_keywords', 'latest_query_run_id',
    ];

    protected function casts(): array
    {
        return [
            'subtypes' => 'array',
            'sources' => 'array',
            'published_at' => 'datetime',
            'modified_at' => 'datetime',
            'is_developing' => 'boolean',
            'revision_count' => 'integer',
            'confidence_score' => 'integer',
            'cves' => 'array',
            'impact_hardware' => 'array',
            'impact_software' => 'array',
            'threat_actors' => 'array',
            'target_objects' => 'array',
            'detection_rules' => 'array',
            'matched_focus_keywords' => 'array',
        ];
    }

    public function iocs(): HasMany
    {
        return $this->hasMany(CtiIoc::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CtiRevision::class);
    }

    /** Append a source entry (deduplicated by URL) — spec Section 8 merging. */
    public function addSource(array $source): bool
    {
        $sources = $this->sources ?? [];

        $exists = collect($sources)->first(fn (array $s) => ($s['url'] ?? null) === ($source['url'] ?? null));

        if ($exists) {
            $exists['content_hash'] = $source['content_hash'] ?? ($exists['content_hash'] ?? null);
            $this->sources = $sources;

            return false; // existing URL, possibly updated hash
        }

        $sources[] = $source + ['added_at' => now()->toIso8601String()];
        $this->sources = $sources;

        return true; // new source
    }

    public function hasSourceUrl(string $url): ?array
    {
        return collect($this->sources ?? [])
            ->first(fn (array $s) => ($s['url'] ?? null) === $url);
    }

    /** Fields that mark a developing story when empty (spec Section 6). */
    public function refreshDevelopingFlag(): void
    {
        $this->is_developing = empty($this->cves)
            || $this->iocs()->count() === 0
            || blank($this->mitigation)
            || empty($this->threat_actors);
    }
}
