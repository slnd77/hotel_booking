<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Account-wide settings, one JSON document per section. Read and write them
 * through App\Services\ApplicationSettings, which applies the defaults.
 */
class ApplicationSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
