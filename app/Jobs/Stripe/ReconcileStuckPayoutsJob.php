<?php

namespace App\Jobs\Stripe;

use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

// STRP-3: daily round-trip to Stripe for payouts stuck in 'processing' beyond the BECS T+2
// window. The only normal path that advances a 'processing' payout is a delivered
// payment_intent.* webhook. If that webhook is permanently lost (Stripe retry window
// exhausted, or silenced by the ack-before-process race), this job asks Stripe directly
// and drives the payout to its correct terminal state.
class ReconcileStuckPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    // 3 days aligns with BECS T+2 + one buffer day. Card PIs resolve same-day, so a card
    // payout should never be 3 days old in 'processing' under normal circumstances.
    private const STALE_DAYS = 3;

    // PI statuses that confirm the payment will never succeed — drive to failed.
    // Note: requires_capture is deliberately excluded — it means the PI is authorised but awaiting
    // manual capture, which is not a failure. Treating it as failed would void the auth and risk
    // a double charge on the next sweep cycle.
    private const TERMINAL_FAILED_STATUSES = ['requires_payment_method', 'canceled'];

    public function __construct(
        private readonly ?StripeClient $stripe = null,
        private readonly ?CommissionPayoutService $payoutService = null,
    ) {
        $this->onQueue('stripe');
    }

    public function handle(): void
    {
        $stripe = $this->stripe ?? new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));

        $payoutService = $this->payoutService ?? app(CommissionPayoutService::class);

        $stalePayouts = CommissionPayout::where('status', 'processing')
            ->whereNotNull('payment_intent_id')
            ->where('updated_at', '<', now()->subDays(self::STALE_DAYS))
            ->get();

        Log::info('stripe.reconcile.start', ['count' => $stalePayouts->count()]);

        foreach ($stalePayouts as $payout) {
            $this->reconcileOne($payout, $stripe, $payoutService);
        }
    }

    private function extractChargeId(object $pi): ?string
    {
        if (is_string($pi->latest_charge ?? null) && $pi->latest_charge !== '') {
            return $pi->latest_charge;
        }

        if (is_object($pi->latest_charge ?? null) && is_string($pi->latest_charge->id ?? null)) {
            return $pi->latest_charge->id;
        }

        return null;
    }

    private function reconcileOne(CommissionPayout $payout, StripeClient $stripe, CommissionPayoutService $payoutService): void
    {
        try {
            $pi = $stripe->paymentIntents->retrieve($payout->payment_intent_id);

            if ($pi->status === 'succeeded') {
                $chargeId = $this->extractChargeId($pi);
                $payoutService->markPaymentIntentSucceeded($payout, $chargeId);
                Log::info('stripe.reconcile.recovered', [
                    'payout_id' => $payout->id,
                    'pi_id' => $pi->id,
                ]);
            } elseif (in_array($pi->status, self::TERMINAL_FAILED_STATUSES, true)) {
                $payoutService->markPaymentIntentFailed(
                    $payout,
                    'reconcile_'.$pi->status,
                    "Reconciliation found PI in terminal failed state: {$pi->status}.",
                );
                Log::warning('stripe.reconcile.failed', [
                    'payout_id' => $payout->id,
                    'pi_id' => $pi->id,
                    'pi_status' => $pi->status,
                ]);
            } else {
                // PI is still processing at Stripe's end — BECS settlement not yet resolved.
                Log::info('stripe.reconcile.still_processing', [
                    'payout_id' => $payout->id,
                    'pi_id' => $pi->id,
                    'pi_status' => $pi->status,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('stripe.reconcile.error', [
                'payout_id' => $payout->id,
                'payment_intent_id' => $payout->payment_intent_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
