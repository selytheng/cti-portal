<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /** Read a setting with a default; scalar values are unwrapped. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::query()->where('key', $key)->first()?->value;

        if ($value === null) {
            return $default;
        }

        return (is_array($value) && array_keys($value) === ['value']) ? $value['value'] : $value;
    }

    /** Write a setting (values are wrapped in arrays for the JSONB cast). */
    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? $value : ['value' => $value]]
        );
    }
}
