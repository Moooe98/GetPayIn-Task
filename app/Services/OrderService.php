<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Create an order from a hold.
     *
     * @param int $holdId
     * @return Order
     * @throws \Exception
     */
    public function createOrderFromHold(int $holdId): Order
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::with('product')->findOrFail($holdId);

            // Validate hold
            if (!$hold->isValid()) {
                throw new \Exception('Hold is invalid or expired');
            }

            // Check if order already exists for this hold
            $existingOrder = Order::where('hold_id', $holdId)->first();
            if ($existingOrder) {
                return $existingOrder; // Idempotent
            }

            // Mark hold as consumed
            if (!$hold->consume()) {
                throw new \Exception('Hold has already been consumed');
            }

            // Create the order
            $order = Order::create([
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity,
                'total_price' => $hold->product->price * $hold->quantity,
                'status' => Order::STATUS_PENDING,
            ]);

            return $order;
        });
    }

    /**
     * Create an order directly (for webhook-first scenarios).
     *
     * @param int $productId
     * @param int $quantity
     * @param int|null $holdId
     * @return Order
     * @throws \Exception
     */
    public function createOrderDirect(int $productId, int $quantity, ?int $holdId = null): Order
    {
        return DB::transaction(function () use ($productId, $quantity, $holdId) {
            $product = Product::findOrFail($productId);

            // If hold_id is provided, validate it
            if ($holdId) {
                $hold = Hold::find($holdId);
                if ($hold && $hold->isValid()) {
                    $hold->consume();
                }
            }

            // Create the order
            $order = Order::create([
                'hold_id' => $holdId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'total_price' => $product->price * $quantity,
                'status' => Order::STATUS_PENDING,
            ]);

            return $order;
        });
    }
}
