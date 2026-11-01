<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CtiIoc extends Model
{
    use HasFactory;

    public const TYPES = [
        'IPv4', 'IPv6', 'Domain', 'URL', 'MD5', 'SHA1', 'SHA256',
        'Email', 'Mutex', 'User-Agent', 'Registry Key',
    ];

    public const VERDICTS = ['Malicious', 'Suspicious', 'Clean', 'Unknown'];

    protected $fillable = [
        'cti_record_id', 'ioc_type', 'value', 'defanged_value', 'context',
        'verdict', 'otx_data', 'abusech_data', 'added_in_revision', 'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'otx_data' => 'array',
            'abusech_data' => 'array',
            'added_in_revision' => 'integer',
            'last_verified_at' => 'datetime',
        ];
    }

    public function ctiRecord(): BelongsTo
    {
        return $this->belongsTo(CtiRecord::class);
    }

    public static function defang(string $value, string $type): string
    {
        return match ($type) {
            'IPv4' => preg_replace('/\./', '[.]', $value),
            'Domain', 'URL', 'Email' => preg_replace('/\./', '[.]', preg_replace('/^https?:\/\//', 'hxxp://', $value)),
            default => $value,
        };
    }

    /** Detect IOC type from a raw value (used to validate AI output). */
    public static function detectType(string $value): ?string
    {
        return match (true) {
            (bool) preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $value) => 'IPv4',
            (bool) preg_match('/^[0-9a-f]{32}$/i', $value) => 'MD5',
            (bool) preg_match('/^[0-9a-f]{40}$/i', $value) => 'SHA1',
            (bool) preg_match('/^[0-9a-f]{64}$/i', $value) => 'SHA256',
            (bool) filter_var($value, FILTER_VALIDATE_EMAIL) => 'Email',
            (bool) preg_match('/^https?:\/\//i', $value) => 'URL',
            (bool) preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $value) => 'Domain',
            default => null,
        };
    }
}
