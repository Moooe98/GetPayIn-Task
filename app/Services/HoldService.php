<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use App\Support\MetricsLogger;
use Illuminate\Support\Facades\DB;

class HoldService
{
    public function __construct(
        private MetricsLogger $metrics
    ) {
    }

    /**
     * Create a hold for a product.
     *
     * @param int $productId
     * @param int $quantity
     * @return Hold
     * @throws \Exception
     */
    public function createHold(int $productId, int $quantity): Hold
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive');
        }

        return DB::transaction(function () use ($productId, $quantity) {
            $product = Product::findOrFail($productId);

            try {
                // Decrement stock with pessimistic locking
                $product->decrementStock($quantity);
            } catch (\Exception $e) {
                $this->metrics->logStockContention($productId, 1);
                throw new \Exception('Insufficient stock available');
            }

            // Create the hold with 2-minute expiry
            $hold = Hold::create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(2),
                'consumed' => false,
            ]);

            $this->metrics->logHoldCreated($productId, $quantity);

            // Invalidate product cache
            $product->invalidateCache();

            return $hold;
        });
    }
}
