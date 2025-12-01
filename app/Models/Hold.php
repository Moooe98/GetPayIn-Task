<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hold extends Model
{
    protected $fillable = [
        'product_id',
        'quantity',
        'expires_at',
        'consumed',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'consumed' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Check if the hold has expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expires_at < now();
    }

    /**
     * Check if the hold is valid (not expired and not consumed).
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return !$this->consumed && !$this->isExpired();
    }

    /**
     * Mark the hold as consumed.
     * Uses optimistic locking to prevent double consumption.
     *
     * @return bool
     */
    public function consume(): bool
    {
        // Optimistic locking: only update if not already consumed
        $updated = $this->newQuery()
            ->where('id', $this->id)
            ->where('consumed', false)
            ->update(['consumed' => true]);

        if ($updated) {
            $this->consumed = true;
            return true;
        }

        return false;
    }
}
