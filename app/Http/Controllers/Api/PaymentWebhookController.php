<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentWebhookService $webhookService
    ) {
    }

    /**
     * Handle payment webhook with idempotency.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function webhook(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'idempotency_key' => 'required|string',
            'order_id' => 'required|integer',
            'status' => 'required|in:success,failure',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->webhookService->processWebhook(
                $request->input('idempotency_key'),
                $request->input('order_id'),
                $request->input('status'),
                $request->all() // Full payload for logging
            );

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
