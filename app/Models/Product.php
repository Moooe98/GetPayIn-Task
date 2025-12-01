<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    protected $fillable = ['name', 'price', 'stock'];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Decrement stock with pessimistic locking to prevent race conditions.
     * Must be called within a transaction.
     *
     * @param int $quantity
     * @return void
     * @throws \Exception
     */
    public function decrementStock(int $quantity): void
    {
        // Lock the row for update (pessimistic locking)
        $product = DB::table('products')
            ->where('id', $this->id)
            ->lockForUpdate()
            ->first();

        if (!$product || $product->stock < $quantity) {
            throw new \Exception('Insufficient stock');
        }

        DB::table('products')
            ->where('id', $this->id)
            ->decrement('stock', $quantity);

        // Invalidate cache
        $this->invalidateCache();

        // Refresh the model
        $this->refresh();
    }

    /**
     * Increment stock when releasing holds.
     * Must be called within a transaction.
     *
     * @param int $quantity
     * @return void
     */
    public function incrementStock(int $quantity): void
    {
        DB::table('products')
            ->where('id', $this->id)
            ->increment('stock', $quantity);

        // Invalidate cache
        $this->invalidateCache();

        // Refresh the model
        $this->refresh();
    }

    /**
     * Get available stock (actual stock - active holds).
     * This is cached for performance.
     *
     * @return int
     */
    public function getAvailableStock(): int
    {
        return Cache::remember(
            "product:{$this->id}:available_stock",
            now()->addSeconds(5), // Short cache to balance performance and accuracy
            function () {
                // Get active (non-consumed, non-expired) holds
                $activeHoldsQuantity = $this->holds()
                    ->where('consumed', false)
                    ->where('expires_at', '>', now())
                    ->sum('quantity');

                return max(0, $this->stock - $activeHoldsQuantity);
            }
        );
    }

    /**
     * Invalidate all caches for this product.
     *
     * @return void
     */
    public function invalidateCache(): void
    {
        Cache::forget("product:{$this->id}:available_stock");
    }
}
