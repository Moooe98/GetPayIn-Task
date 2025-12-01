<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WebhookEvent;
use App\Support\MetricsLogger;
use Illuminate\Support\Facades\DB;

class PaymentWebhookService
{
    public function __construct(
        private OrderService $orderService,
        private MetricsLogger $metrics
    ) {
    }

    /**
     * Process a payment webhook with idempotency.
     *
     * @param string $idempotencyKey
     * @param int $orderId
     * @param string $status 'success' or 'failure'
     * @param array $payload
     * @return array
     */
    public function processWebhook(
        string $idempotencyKey,
        int $orderId,
        string $status,
        array $payload
    ): array {
        return DB::transaction(function () use ($idempotencyKey, $orderId, $status, $payload) {
            // Check if this webhook has already been processed
            $existingEvent = WebhookEvent::where('idempotency_key', $idempotencyKey)->first();

            if ($existingEvent) {
                // Webhook already processed - return cached result
                $this->metrics->logWebhookProcessed($idempotencyKey, true);

                return [
                    'processed' => true,
                    'duplicate' => true,
                    'order_id' => $existingEvent->order_id,
                    'status' => $existingEvent->status,
                ];
            }

            // Get or create the order
            $order = Order::find($orderId);

            if (!$order) {
                // Webhook arrived before order creation - extract data from payload
                if (!isset($payload['product_id'], $payload['quantity'])) {
                    throw new \Exception('Missing product_id or quantity in payload');
                }

                $holdId = $payload['hold_id'] ?? null;
                $order = $this->orderService->createOrderDirect(
                    $payload['product_id'],
                    $payload['quantity'],
                    $holdId
                );
            }

            // Process the payment result
            if ($status === 'success') {
                $order->markAsPaid();
            } else {
                $order->cancel(); // Releases stock
            }

            // Record the webhook event for idempotency
            $webhookEvent = WebhookEvent::create([
                'idempotency_key' => $idempotencyKey,
                'order_id' => $order->id,
                'status' => $status,
                'payload' => $payload,
                'processed_at' => now(),
            ]);

            $this->metrics->logWebhookProcessed($idempotencyKey, false);

            return [
                'processed' => true,
                'duplicate' => false,
                'order_id' => $order->id,
                'status' => $status,
            ];
        });
    }
}
