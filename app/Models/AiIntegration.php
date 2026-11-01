<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiIntegration extends Model
{
    use HasFactory;

    public const PROVIDERS = ['gemini', 'claude', 'openai', 'openrouter', 'custom'];

    protected $fillable = [
        'provider',
        'name',
        'api_key',
        'base_url',
        'model_id',
        'system_prompt',
        'merge_prompt',
        'temperature',
        'max_tokens',
        'timeout',
        'is_default',
        'is_fallback',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'settings' => 'array',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'timeout' => 'integer',
            'is_default' => 'boolean',
            'is_fallback' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
