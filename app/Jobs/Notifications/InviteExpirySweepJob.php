<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Marks expired affiliate invites and publishes expiry notifications to brand managers.
class InviteExpirySweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        $now = now();

        DB::table('brand.brand_affiliate_invites')
            ->where('status', 'pending')
            ->where('expires_at', '<', $now)
            ->select(['id', 'brand_professional_id', 'email', 'first_name'])
            ->chunkById(500, function ($chunk) use ($publisher, $now) {
                $ids = $chunk->pluck('id')->all();

                // Bulk update reduces N×UPDATE round-trips to one per chunk;
                // status='pending' still guards against concurrent expiry.
                DB::table('brand.brand_affiliate_invites')
                    ->whereIn('id', $ids)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired', 'updated_at' => $now]);

                foreach ($chunk as $invite) {
                    try {
                        $brandId = trim((string) ($invite->brand_professional_id ?? ''));
                        if ($brandId === '') {
                            continue;
                        }

                        $label = trim((string) ($invite->first_name ?? ''));
                        if ($label === '') {
                            $label = trim((string) ($invite->email ?? ''));
                        }
                        if ($label === '') {
                            $label = 'An invitee';
                        }

                        $publisher->publish(
                            professionalId: $brandId,
                            frontendType: 'Warning',
                            category: 'invites',
                            title: 'Invite expired',
                            body: "Your invite to {$label} has expired.",
                            dedupeKey: "invite.expired.{$invite->id}",
                            ctaUrl: '/account/affiliates',
                            retentionConfigKey: 'invite',
                        );
                    } catch (\Throwable $e) {
                        Log::warning('InviteExpirySweepJob failed for invite', [
                            'invite_id' => $invite->id,
                            'brand_professional_id' => $brandId,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Invite expiry sweep job failed', [
            'message' => $e->getMessage(),
        ]);
    }
}
