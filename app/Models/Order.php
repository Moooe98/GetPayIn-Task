<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'hold_id',
        'product_id',
        'quantity',
        'total_price',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'total_price' => 'decimal:2',
    ];

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Mark the order as paid.
     *
     * @return void
     */
    public function markAsPaid(): void
    {
        $this->update(['status' => self::STATUS_PAID]);
    }

    /**
     * Cancel the order and release stock back to the product.
     *
     * @return void
     */
    public function cancel(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return; // Already cancelled
        }

        $this->update(['status' => self::STATUS_CANCELLED]);

        // Release stock back to product
        $this->product->incrementStock($this->quantity);
    }

    /**
     * Check if the order is in pending state.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
