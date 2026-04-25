<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// V2: Sends category-specific transactional emails (invites, commissions, payouts). Respects feature flags and user email preferences.
class SendTransactionalNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $notificationId,
        public readonly string $category,
        public readonly string $professionalId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        if (! config('sidest.notifications.email_enabled', false)) {
            Log::debug('Notification email skipped: feature disabled', [
                'category' => $this->category,
            ]);

            return;
        }

        // Early-exit if this category has no mailable (in-app-only or unregistered).
        // Avoids unnecessary DB queries when there's nothing to send.
        $class = config("sidest.notifications.mailables.{$this->category}");
        if (! is_string($class) || ! class_exists($class)) {
            Log::debug('Notification email skipped: category has no mailable', [
                'category' => $this->category,
            ]);

            return;
        }

        if (! NotificationPublisher::resolveEmailEnabled($this->professionalId, $this->category)) {
            Log::debug('Notification email skipped: user preference disabled', [
                'category' => $this->category,
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

        $notification = Notification::query()->find($this->notificationId);
        if (! $notification instanceof Notification) {
            Log::warning('Notification email skipped: notification not found', [
                'notification_id' => $this->notificationId,
            ]);

            return;
        }

        $email = DB::table('core.professionals')
            ->where('id', $this->professionalId)
            ->value('primary_email');

        if (! $email) {
            Log::warning('Notification email skipped: no email on record', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

        $mailable = $this->buildMailable($notification, $class);
        if ($mailable === null) {
            Log::warning('Notification email skipped: mailable instantiation failed', [
                'category' => $this->category,
                'class' => $class,
            ]);

            return;
        }

        Mail::to($email)->send($mailable);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Transactional notification email failed', [
            'notification_id' => $this->notificationId,
            'category' => $this->category,
            'professional_id' => $this->professionalId,
            'message' => $e->getMessage(),
        ]);
    }

    private function buildMailable(Notification $notification, string $class): ?\Illuminate\Mail\Mailable
    {
        $mailable = new $class($notification);

        if (! $mailable instanceof \Illuminate\Mail\Mailable) {
            return null;
        }

        return $mailable;
    }
}
