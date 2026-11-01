<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KeywordRule extends Model
{
    use HasFactory;

    public const MATCH_TYPES = ['exact', 'wildcard', 'regex'];

    public const RULE_TYPES = ['focus', 'exception'];

    public const CATEGORIES = ['Software', 'Hardware', 'Vendor', 'Cloud Service', 'Industry', 'Internal Asset'];

    protected $fillable = [
        'keyword',
        'match_type',
        'rule_type',
        'category',
        'severity_boost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'severity_boost' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
