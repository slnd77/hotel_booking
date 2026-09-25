<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    protected $fillable = [
        'key',
        'data',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function options(string $key): array
    {
        return static::query()->where('key', $key)->value('data') ?? [];
    }
}
