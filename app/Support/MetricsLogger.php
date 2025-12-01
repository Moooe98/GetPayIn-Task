<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class MetricsLogger
{
    /**
     * Log when a hold is created.
     *
     * @param int $productId
     * @param int $quantity
     * @return void
     */
    public function logHoldCreated(int $productId, int $quantity): void
    {
        Log::info('Hold created', [
            'metric' => 'hold.created',
            'product_id' => $productId,
            'quantity' => $quantity,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log when a hold expires and stock is released.
     *
     * @param int $holdId
     * @return void
     */
    public function logHoldExpired(int $holdId): void
    {
        Log::info('Hold expired', [
            'metric' => 'hold.expired',
            'hold_id' => $holdId,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log webhook processing.
     *
     * @param string $idempotencyKey
     * @param bool $isDuplicate
     * @return void
     */
    public function logWebhookProcessed(string $idempotencyKey, bool $isDuplicate): void
    {
        Log::info('Webhook processed', [
            'metric' => 'webhook.processed',
            'idempotency_key' => $idempotencyKey,
            'is_duplicate' => $isDuplicate,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log stock contention events (when pessimistic lock fails).
     *
     * @param int $productId
     * @param int $attempts
     * @return void
     */
    public function logStockContention(int $productId, int $attempts): void
    {
        Log::warning('Stock contention detected', [
            'metric' => 'stock.contention',
            'product_id' => $productId,
            'attempts' => $attempts,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log batch expiry processing.
     *
     * @param int $expiredCount
     * @param float $durationMs
     * @return void
     */
    public function logBatchExpiry(int $expiredCount, float $durationMs): void
    {
        Log::info('Batch expiry processed', [
            'metric' => 'expiry.batch',
            'expired_count' => $expiredCount,
            'duration_ms' => $durationMs,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
