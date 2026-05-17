<?php

namespace App\Notifications\Affiliate;

use App\Models\Commerce\CommissionPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Tiered grace-period warning sent to affiliates who haven't connected Stripe.
// Three escalation tiers: T-30 (informational), T-7 (urgent), T-1 (final).
class AffiliatePayoutGraceWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CommissionPayout $payout,
        public int $daysRemaining
    ) {}

    /** @return array<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = $this->payout->brandProfessional?->display_name ?? 'a brand';
        $amount = '$'.number_format($this->payout->gross_commission_cents / 100, 2);
        $connect = config('app.frontend_url').'/affiliate/stripe/connect';

        return match (true) {
            $this->daysRemaining >= 30 => (new MailMessage)
                ->subject("Your {$amount} from {$brand} expires in 30 days")
                ->greeting('Heads up')
                ->line("You have {$amount} in commission from {$brand} ready to be paid.")
                ->line('To receive it, connect a Stripe account. After 60 days unconnected, the commission expires and the brand keeps the funds.')
                ->action('Connect Stripe (5 min)', $connect),

            $this->daysRemaining >= 7 => (new MailMessage)
                ->subject("Only {$this->daysRemaining} days left to claim your {$amount} from {$brand}")
                ->greeting('Time is running short')
                ->line("Your {$amount} commission from {$brand} expires in {$this->daysRemaining} days.")
                ->line("Connect Stripe now and we'll send the funds within 24h.")
                ->action('Connect Stripe', $connect),

            default => (new MailMessage)
                ->subject("Final notice: {$amount} from {$brand} expires tomorrow")
                ->greeting('Last chance')
                ->line("This is your final reminder. Your {$amount} commission from {$brand} expires in 24 hours.")
                ->line("If you don't connect Stripe before then, the commission is forfeited.")
                ->action('Connect Stripe — final reminder', $connect),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'payout_id' => $this->payout->id,
            'brand_name' => $this->payout->brandProfessional?->display_name,
            'amount_cents' => $this->payout->gross_commission_cents,
            'void_at' => $this->payout->void_at?->toIso8601String(),
            'days_remaining' => $this->daysRemaining,
            'connect_url' => config('app.frontend_url').'/affiliate/stripe/connect',
        ];
    }
}
