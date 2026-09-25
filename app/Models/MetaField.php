<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaField extends Model
{
    protected $fillable = [
        'key',
        'key_label',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }
}
