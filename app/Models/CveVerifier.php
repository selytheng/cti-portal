<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CveVerifier extends Model
{
    use HasFactory;

    public const SERVICES = ['nvd'];

    protected $fillable = [
        'service', 'api_key', 'base_url', 'request_interval_seconds',
        'auto_verify', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'request_interval_seconds' => 'integer',
            'auto_verify' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Effective delay between NVD requests. NVD rate limits: 5 req/30s
     * without a key, 50 req/30s with one — so keyless deployments are
     * automatically slowed down further.
     */
    public function requestInterval(): int
    {
        return filled($this->api_key)
            ? max(1, $this->request_interval_seconds)
            : max(6, $this->request_interval_seconds);
    }
}
