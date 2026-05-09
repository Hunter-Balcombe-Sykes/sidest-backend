<?php

namespace App\Notifications\Brand;

use App\Models\Retail\CommissionPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BrandPayoutFundingFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CommissionPayout $payout,
        public bool $isTerminal
    ) {}

    /**
     * Email digest pattern: send mail only on the FIRST cycle ("here's what's happening,
     * we'll keep retrying for 7 days") and the TERMINAL cycle ("we gave up, action required").
     * Cycles 2..6 only write to the database channel so the dashboard banner stays fresh
     * without spamming the brand's inbox.
     *
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        $count = $this->payout->funding_failure_count ?? 0;

        if ($this->isTerminal || $count <= 1) {
            return ['mail', 'database'];
        }

        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $aff = $this->payout->affiliateProfessional?->display_name ?? 'an affiliate';
        $amount = '$' . number_format($this->payout->gross_commission_cents / 100, 2);

        if ($this->isTerminal) {
            return (new MailMessage)
                ->subject('Action required: payout permanently failed')
                ->greeting('We need your help')
                ->line("Your card has failed 7 times trying to pay {$aff} their commission of {$amount}.")
                ->line('Your wallet has been credited back. Update your card and reach out to support so we can retry.')
                ->action('Update payment method', config('app.frontend_url').'/brand/billing');
        }

        return (new MailMessage)
            ->subject("We'll retry your payout to {$aff} tomorrow")
            ->line("Your card couldn't be charged for {$aff}'s commission of {$amount}.")
            ->line("Reason: {$this->payout->failure_reason}")
            ->line('We\'ll retry on '.optional($this->payout->next_retry_at)->format('jS F').'.');
    }

    /**
     * @return array{payout_id: string, affiliate_name: string|null, amount_cents: int, failure_reason: string|null, next_retry_at: string|null, is_terminal: bool}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'payout_id'      => $this->payout->id,
            'affiliate_name' => $this->payout->affiliateProfessional?->display_name,
            'amount_cents'   => $this->payout->gross_commission_cents,
            'failure_reason' => $this->payout->failure_reason,
            'next_retry_at'  => $this->payout->next_retry_at?->toIso8601String(),
            'is_terminal'    => $this->isTerminal,
        ];
    }
}
