<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IocVerifier extends Model
{
    use HasFactory;

    public const SERVICES = ['otx', 'abusech'];

    public const ABUSECH_SUB_SERVICES = ['urlhaus', 'malwarebazaar', 'threatfox', 'feodo_tracker'];

    protected $fillable = [
        'service',
        'api_key',
        'base_url',
        'sub_services',
        'cache_ttl_minutes',
        'auto_enrich',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'sub_services' => 'array',
            'cache_ttl_minutes' => 'integer',
            'auto_enrich' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
