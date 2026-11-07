<?php

namespace App\Models;

use Database\Factories\BlockReasonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reason offered when blocking rooms on the smart chart, managed in
 * Application Settings → Block Unblock Config.
 */
class BlockReason extends Model
{
    /** @use HasFactory<BlockReasonFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'display_order',
    ];

    /**
     * @param  Builder<BlockReason>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('display_order')->orderBy('id');
    }

    /**
     * "Maintenance" style reasons are drawn grey on the chart; the rest amber.
     */
    public function isMaintenance(): bool
    {
        return str_contains(strtolower($this->name), 'maintenance');
    }

    /**
     * @return HasMany<RoomBlock, $this>
     */
    public function roomBlocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }
}
