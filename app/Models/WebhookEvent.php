<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $fillable = [
        'idempotency_key',
        'order_id',
        'status',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if this webhook has been processed.
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Mark the webhook as processed.
     *
     * @return void
     */
    public function markAsProcessed(): void
    {
        $this->update(['processed_at' => now()]);
    }
}
