<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Support\MetricsLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ExpireHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private ?MetricsLogger $metrics = null
    ) {
        $this->metrics = $this->metrics ?? app(MetricsLogger::class);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $expiredCount = 0;

        // Find all expired, unconsumed holds
        $expiredHolds = Hold::with('product')
            ->where('consumed', false)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredHolds as $hold) {
            DB::transaction(function () use ($hold) {
                // Double-check it hasn't been consumed (race condition protection)
                $hold->refresh();

                if (!$hold->consumed) {
                    // Mark as consumed to prevent double-release
                    $hold->update(['consumed' => true]);

                    // Release stock back to product
                    $hold->product->incrementStock($hold->quantity);

                    $this->metrics->logHoldExpired($hold->id);
                }
            });

            $expiredCount++;
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->metrics->logBatchExpiry($expiredCount, $duration);
    }
}
