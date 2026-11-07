<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Activity Logs" entry on an Application Settings tab.
 */
class ApplicationSettingLog extends Model
{
    protected $fillable = [
        'section',
        'user_id',
        'description',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
