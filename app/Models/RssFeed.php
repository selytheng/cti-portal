<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RssFeed extends Model
{
    use HasFactory;

    public const CATEGORIES = ['Vendor Advisory', 'CERT/Gov', 'Security Blog', 'Exploit Feed'];

    protected $fillable = [
        'name',
        'url',
        'category',
        'polling_interval_minutes',
        'custom_headers',
        'is_active',
        'last_fetched_at',
        'last_status_code',
        'failure_count',
    ];

    protected function casts(): array
    {
        return [
            'custom_headers' => 'array',
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
            'polling_interval_minutes' => 'integer',
            'last_status_code' => 'integer',
            'failure_count' => 'integer',
        ];
    }
}
