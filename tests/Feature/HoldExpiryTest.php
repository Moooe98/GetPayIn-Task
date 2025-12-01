<?php

namespace Tests\Feature;

use App\Jobs\ExpireHoldsJob;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that expired holds release stock back to the product.
     */
    public function test_expired_holds_release_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 50.00,
            'stock' => 10,
        ]);

        // Create a hold
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 3,
        ]);

        $response->assertStatus(201);
        $holdId = $response->json('hold_id');

        // Verify stock was decremented
        $product->refresh();
        $this->assertEquals(7, $product->stock);

        // Manually expire the hold by updating its expires_at
        $hold = Hold::find($holdId);
        $hold->update(['expires_at' => now()->subMinutes(5)]);

        // Run the expiry job
        $job = new ExpireHoldsJob();
        $job->handle();

        // Verify stock was restored
        $product->refresh();
        $this->assertEquals(10, $product->stock, 'Stock should be restored after hold expiry');

        // Verify hold is marked as consumed
        $hold->refresh();
        $this->assertTrue($hold->consumed, 'Expired hold should be marked as consumed');
    }

    /**
     * Test that available stock excludes expired holds.
     */
    public function test_available_stock_excludes_expired_holds(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 75.00,
            'stock' => 15,
        ]);

        // Create two holds
        $hold1 = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => now()->addMinutes(10), // Active
            'consumed' => false,
        ]);

        $hold2 = Hold::create([
            'product_id' => $product->id,
            'quantity' => 3,
            'expires_at' => now()->subMinutes(5), // Expired
            'consumed' => false,
        ]);

        // Clear cache to force recalculation
        Cache::forget("product:{$product->id}:available_stock");

        // Available stock should only exclude active holds
        $availableStock = $product->getAvailableStock();
        $this->assertEquals(10, $availableStock, 'Available stock should exclude only active holds');
    }

    /**
     * Test that double expiry doesn't double-increment stock.
     */
    public function test_expiry_job_prevents_double_release(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 20,
        ]);

        // Create and expire a hold
        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => now()->subMinutes(10),
            'consumed' => false,
        ]);

        // Manually decrement stock as if hold was created properly
        $product->update(['stock' => 15]);

        // Run expiry job twice
        $job1 = new ExpireHoldsJob();
        $job1->handle();

        $job2 = new ExpireHoldsJob();
        $job2->handle();

        // Stock should only be incremented once
        $product->refresh();
        $this->assertEquals(20, $product->stock, 'Stock should only be restored once');
    }

    /**
     * Test that consumed holds are not processed by expiry job.
     */
    public function test_consumed_holds_not_processed(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 60.00,
            'stock' => 10,
        ]);

        // Create an expired but consumed hold
        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 4,
            'expires_at' => now()->subMinutes(5),
            'consumed' => true, // Already consumed
        ]);

        $stockBefore = $product->stock;

        // Run expiry job
        $job = new ExpireHoldsJob();
        $job->handle();

        // Stock should not change
        $product->refresh();
        $this->assertEquals($stockBefore, $product->stock, 'Stock should not change for consumed holds');
    }
}
