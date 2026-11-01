<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CtiNote extends Model
{
    use HasFactory;

    public const APPLICABILITY = ['Affected', 'Not Affected', 'Mitigated', 'Investigating'];

    protected $fillable = ['cti_record_id', 'user_id', 'body', 'applicability'];

    public function ctiRecord(): BelongsTo
    {
        return $this->belongsTo(CtiRecord::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
