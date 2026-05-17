<?php

namespace App\Notifications\Brand;

use App\Models\Commerce\CommissionPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Tiered grace-period warning sent to brands whose commission payout is blocked
// on a brand-side issue (no payment method on file, wallet currency mismatch).
// Mirror of AffiliatePayoutGraceWarningNotification but targets the brand —
// fireGraceWarnings routes based on the payout's failure_code so the party
// who can actually fix the issue gets the message.
//
// Three escalation tiers: T-30 (informational), T-7 (urgent), T-1 (final).
class BrandPayoutFundingGraceWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CommissionPayout $payout,
        public int $daysRemaining,
    ) {}

    /** @return array<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $affiliate = $this->payout->affiliateProfessional?->display_name ?? 'an affiliate';
        $amount = '$'.number_format($this->payout->gross_commission_cents / 100, 2);
        $settings = config('app.frontend_url').'/account/settings?section=stripe';

        $reasonLine = match ($this->payout->failure_code) {
            'wallet_currency_mismatch' => 'Your wallet balance is in a different currency than this payout requires — please contact support to resolve.',
            default => 'Add a payment method in your Stripe settings so we can collect the commission and pay your affiliate.',
        };

        return match (true) {
            $this->daysRemaining >= 30 => (new MailMessage)
                ->subject("Commission payout of {$amount} to {$affiliate} blocked")
                ->greeting('Action required')
                ->line("A commission payout of {$amount} to {$affiliate} is blocked and will expire in 30 days.")
                ->line($reasonLine)
                ->action('Fix payment settings', $settings),

            $this->daysRemaining >= 7 => (new MailMessage)
                ->subject("{$this->daysRemaining} days to fix payment — {$amount} to {$affiliate}")
                ->greeting('Time is running short')
                ->line("Your commission payout of {$amount} to {$affiliate} expires in {$this->daysRemaining} days.")
                ->line($reasonLine)
                ->action('Fix payment settings', $settings),

            default => (new MailMessage)
                ->subject("Final notice: {$amount} payout to {$affiliate} expires tomorrow")
                ->greeting('Last chance')
                ->line("This is your final reminder. A commission payout of {$amount} to {$affiliate} expires in 24 hours and the affiliate will not be paid.")
                ->line($reasonLine)
                ->action('Fix payment settings — final reminder', $settings),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'payout_id' => $this->payout->id,
            'affiliate_name' => $this->payout->affiliateProfessional?->display_name,
            'amount_cents' => $this->payout->gross_commission_cents,
            'void_at' => $this->payout->void_at?->toIso8601String(),
            'days_remaining' => $this->daysRemaining,
            'failure_code' => $this->payout->failure_code,
            'settings_url' => config('app.frontend_url').'/account/settings?section=stripe',
        ];
    }
}
