<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge code (by code) or payment mode (by value) posting to an account other than its default.
 */
class GlMapping extends Model
{
    public const CHARGE_CODE = 'charge_code';

    public const PAYMENT_MODE = 'payment_mode';

    protected $fillable = [
        'property_id',
        'mapping_type',
        'key',
        'gl_account_id',
    ];

    /**
     * @return BelongsTo<GlAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
