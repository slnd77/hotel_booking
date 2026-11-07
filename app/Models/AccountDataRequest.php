<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An export of all the account's data (a ZIP to download for a few days), or the account's
 * deletion, scheduled after a grace period and cancellable until it runs.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property int|null $user_id
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string|null $error
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class AccountDataRequest extends Model
{
    public const EXPORT = 'export';

    public const DELETION = 'deletion';

    public const QUEUED = 'queued';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const SCHEDULED = 'scheduled';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    protected $fillable = [
        'type', 'status', 'user_id', 'file_path', 'file_size', 'error',
        'scheduled_for', 'expires_at', 'completed_at', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The account's deletion waiting for its date, if any.
     */
    public static function scheduledDeletion(): ?self
    {
        return self::query()->where('type', self::DELETION)->where('status', self::SCHEDULED)->latest('id')->first();
    }
}
