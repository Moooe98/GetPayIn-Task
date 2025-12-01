<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that webhook with same idempotency key returns same result.
     */
    public function test_webhook_idempotency_key_prevents_duplicate_processing(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        // Create a hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ]);

        $orderId = $orderResponse->json('order_id');

        // Send webhook with success
        $payload = [
            'idempotency_key' => 'unique-key-123',
            'order_id' => $orderId,
            'status' => 'success',
        ];

        $response1 = $this->postJson('/api/payments/webhook', $payload);
        $response1->assertStatus(200);
        $response1->assertJson([
            'processed' => true,
            'duplicate' => false,
        ]);

        // Send same webhook again
        $response2 = $this->postJson('/api/payments/webhook', $payload);
        $response2->assertStatus(200);
        $response2->assertJson([
            'processed' => true,
            'duplicate' => true,
        ]);

        // Send it a third time
        $response3 = $this->postJson('/api/payments/webhook', $payload);
        $response3->assertStatus(200);
        $response3->assertJson([
            'processed' => true,
            'duplicate' => true,
        ]);

        // Verify only one webhook event was created
        $this->assertEquals(1, WebhookEvent::where('idempotency_key', 'unique-key-123')->count());

        // Verify order is paid
        $order = Order::find($orderId);
        $this->assertEquals('paid', $order->status);
    }

    /**
     * Test webhook arriving before order creation.
     */
    public function test_webhook_arriving_before_order_creation(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 150.00,
            'stock' => 20,
        ]);

        // Create hold but don't create order yet
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 3,
        ]);

        $holdId = $holdResponse->json('hold_id');

        // Webhook arrives BEFORE client creates order
        // Use a non-existent order_id but include product info in payload
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'webhook-first-key',
            'order_id' => 99999, // Doesn't exist yet
            'status' => 'success',
            'product_id' => $product->id,
            'quantity' => 3,
            'hold_id' => $holdId,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'processed' => true,
            'duplicate' => false,
        ]);

        // Verify order was created by webhook
        $createdOrder = Order::where('hold_id', $holdId)->first();
        $this->assertNotNull($createdOrder, 'Order should be created by webhook');
        $this->assertEquals('paid', $createdOrder->status);

        // Now client tries to create order with same hold
        // This should fail because hold was already consumed by webhook
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        // Should return 422 because hold is already consumed
        $orderResponse->assertStatus(422);
        $orderResponse->assertJsonStructure(['error']);

        // But the order created by webhook exists and is paid
        $this->assertEquals('paid', $createdOrder->status);
    }

    /**
     * Test webhook with failure status cancels order and releases stock.
     */
    public function test_webhook_failure_cancels_order_and_releases_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 80.00,
            'stock' => 15,
        ]);

        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ]);

        $orderId = $orderResponse->json('order_id');

        // Verify stock was decremented
        $product->refresh();
        $this->assertEquals(10, $product->stock);

        // Send webhook with failure
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'failure-key-456',
            'order_id' => $orderId,
            'status' => 'failure',
        ]);

        $response->assertStatus(200);

        // Verify order is cancelled
        $order = Order::find($orderId);
        $this->assertEquals('cancelled', $order->status);

        // Verify stock was released back
        $product->refresh();
        $this->assertEquals(15, $product->stock, 'Stock should be released on payment failure');
    }

    /**
     * Test multiple different webhooks can be processed.
     */
    public function test_multiple_different_webhooks_processed(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 200.00,
            'stock' => 30,
        ]);

        // Create multiple orders
        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $holdResponse = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 2,
            ]);

            $orderResponse = $this->postJson('/api/orders', [
                'hold_id' => $holdResponse->json('hold_id'),
            ]);

            $orders[] = $orderResponse->json('order_id');
        }

        // Send webhooks with different idempotency keys
        foreach ($orders as $index => $orderId) {
            $response = $this->postJson('/api/payments/webhook', [
                'idempotency_key' => "key-{$index}",
                'order_id' => $orderId,
                'status' => 'success',
            ]);

            $response->assertStatus(200);
            $response->assertJson(['duplicate' => false]);
        }

        // Verify all webhook events were recorded
        $this->assertEquals(3, WebhookEvent::count());

        // Verify all orders are paid
        foreach ($orders as $orderId) {
            $order = Order::find($orderId);
            $this->assertEquals('paid', $order->status);
        }
    }
}
