<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test parallel holds at stock boundary to ensure no overselling.
     */
    public function test_parallel_holds_at_stock_boundary_prevent_overselling(): void
    {
        // Create a product with limited stock
        $product = Product::create([
            'name' => 'Limited Product',
            'price' => 50.00,
            'stock' => 5,
        ]);

        // Attempt to create 10 concurrent holds for 1 unit each
        $results = [];
        $processes = [];

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            $results[] = $response;
        }

        // Count successful holds
        $successCount = 0;
        foreach ($results as $response) {
            if ($response->status() === 201) {
                $successCount++;
            }
        }

        // Exactly 5 should succeed (matching stock)
        $this->assertEquals(5, $successCount, 'Exactly 5 holds should succeed when stock is 5');

        // Verify product stock is now 0
        $product->refresh();
        $this->assertEquals(0, $product->stock, 'Stock should be 0 after all holds');

        // Verify available stock is 0
        $this->assertEquals(0, $product->getAvailableStock(), 'Available stock should be 0');
    }

    /**
     * Test that stock never goes negative under concurrent requests.
     */
    public function test_stock_never_goes_negative(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 3,
        ]);

        // Try to create holds that would exceed stock
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 2,
            ]);
        }

        // Count successes
        $successCount = 0;
        foreach ($responses as $response) {
            if ($response->status() === 201) {
                $successCount++;
            }
        }

        // Only 1 should succeed (3 stock / 2 qty = 1 hold)
        $this->assertEquals(1, $successCount);

        // Verify stock is not negative
        $product->refresh();
        $this->assertGreaterThanOrEqual(0, $product->stock);
    }

    /**
     * Test that multiple small concurrent holds are handled correctly.
     */
    public function test_multiple_concurrent_small_holds(): void
    {
        $product = Product::create([
            'name' => 'Flash Sale Item',
            'price' => 99.99,
            'stock' => 20,
        ]);

        // Create 25 concurrent requests for 1 unit each
        $results = [];
        for ($i = 0; $i < 25; $i++) {
            $results[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);
        }

        $successCount = collect($results)->filter(fn($r) => $r->status() === 201)->count();

        // Exactly 20 should succeed
        $this->assertEquals(20, $successCount);

        // Check stock
        $product->refresh();
        $this->assertEquals(0, $product->stock);
    }
}
