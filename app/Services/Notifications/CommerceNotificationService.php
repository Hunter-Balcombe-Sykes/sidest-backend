<?php

namespace App\Services\Notifications;

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Publishes booking completion notifications to professional and brand partners. Commission/payout notifications handled by observers instead.
class CommerceNotificationService
{
    /** @var array<int, int> */
    private const BOOKING_COUNT_MILESTONES = [1, 5, 10, 25, 50, 100];

    /** @var array<int, int> */
    private const BOOKING_REVENUE_MILESTONES_CENTS = [5_000, 10_000, 25_000, 50_000, 100_000, 250_000];

    /**
     * Short TTL for the milestone-totals snapshot. Long enough to absorb a burst
     * of bookings without re-scanning analytics.booking_events; short enough that
     * a newly-crossed milestone fires within ~60s of being earned. The publisher's
     * dedupe key keeps a re-publish at the next refresh idempotent.
     */
    private const MILESTONE_TOTALS_TTL_SECONDS = 60;

    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly CacheLockService $cacheLock,
    ) {}

    /**
     * @param  array{
     *   professional_id: string,
     *   brand_professional_ids?: array<int, string>,
     *   booking_event_id?: string|null,
     *   booking_id?: string|null,
     *   service_name?: string|null,
     *   customer_name?: string|null,
     *   amount_paid_cents?: int|float|string|null,
     *   currency_code?: string|null
     * }  $context
     */
    public function notifyBookingCompleted(array $context): void
    {
        try {
            $professionalId = trim((string) ($context['professional_id'] ?? ''));
            if ($professionalId === '') {
                return;
            }

            $eventId = trim((string) ($context['booking_event_id'] ?? ''));
            $bookingId = trim((string) ($context['booking_id'] ?? ''));
            $serviceName = trim((string) ($context['service_name'] ?? ''));
            $customerName = trim((string) ($context['customer_name'] ?? ''));
            $amountPaidCents = max(0, (int) ($context['amount_paid_cents'] ?? 0));
            $currencyCode = strtoupper(trim((string) ($context['currency_code'] ?? 'AUD')));
            if ($currencyCode === '') {
                $currencyCode = 'AUD';
            }

            $amountLabel = Money::format($amountPaidCents, $currencyCode);
            $serviceLabel = $serviceName !== '' ? $serviceName : 'Service';
            $customerLabel = $customerName !== '' ? $customerName : 'Customer';
            $eventKey = $eventId !== '' ? $eventId : ($bookingId !== '' ? $bookingId : Str::uuid()->toString());

            $affiliateName = (string) (DB::table('core.professionals')
                ->where('id', $professionalId)
                ->whereNull('deleted_at')
                ->value(DB::raw("COALESCE(NULLIF(display_name, ''), NULLIF(handle, ''), 'Partner')")));

            $ctaUrl = '/account/sitepage?section=bookings&event='.$eventKey;
            $body = $amountPaidCents > 0
                ? "{$customerLabel} booked {$serviceLabel} ({$amountLabel})."
                : "{$customerLabel} booked {$serviceLabel}.";

            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'analytics_milestones',
                title: 'New booking received',
                body: $body,
                dedupeKey: 'booking:user:'.$eventKey,
                ctaUrl: $ctaUrl,
                retentionConfigKey: 'analytics_milestones',
            );

            $brandProfessionalIds = collect($context['brand_professional_ids'] ?? [])
                ->filter(static fn ($value): bool => is_string($value) || is_numeric($value))
                ->map(static fn ($value): string => trim((string) $value))
                ->filter(static fn (string $value): bool => $value !== '')
                ->unique()
                ->values();

            foreach ($brandProfessionalIds as $brandProfessionalId) {
                $brandBody = $amountPaidCents > 0
                    ? "{$affiliateName} received a booking for {$serviceLabel} ({$amountLabel})."
                    : "{$affiliateName} received a booking for {$serviceLabel}.";

                $this->publisher->publish(
                    professionalId: $brandProfessionalId,
                    frontendType: 'Info',
                    category: 'analytics_milestones',
                    title: 'New partner booking',
                    body: $brandBody,
                    dedupeKey: 'booking:brand:'.$brandProfessionalId.':'.$eventKey,
                    ctaUrl: '/account/commerce?section=analytics&booking='.$eventKey,
                    retentionConfigKey: 'analytics_milestones',
                );
            }

            $this->notifyBookingMilestonesForProfessional($professionalId);
        } catch (\Throwable $e) {
            Log::warning('Booking notifications failed', [
                'professional_id' => $context['professional_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyBookingMilestonesForProfessional(string $professionalId): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        // Cache the lifetime COUNT/SUM tuple for 60s. A burst of bookings within
        // this window reads the cached snapshot instead of rescanning every time;
        // the publisher's dedupe key (`booking:count:<threshold>`) keeps a stale
        // milestone read from re-firing the notification it already sent.
        $totals = $this->cacheLock->rememberLocked(
            CacheKeyGenerator::bookingMilestoneTotals($professionalId),
            self::MILESTONE_TOTALS_TTL_SECONDS,
            function () use ($professionalId): array {
                $row = DB::table('analytics.booking_events')
                    ->where('professional_id', $professionalId)
                    ->selectRaw('COUNT(*) as bookings_count')
                    ->selectRaw('COALESCE(SUM(amount_paid_cents), 0) as total_spent_cents')
                    ->first();

                return [
                    'bookings_count' => max(0, (int) ($row->bookings_count ?? 0)),
                    'total_spent_cents' => max(0, (int) ($row->total_spent_cents ?? 0)),
                ];
            },
        );

        $bookingsCount = (int) ($totals['bookings_count'] ?? 0);
        $totalSpentCents = (int) ($totals['total_spent_cents'] ?? 0);

        $bookingMilestone = $this->latestReachedThreshold($bookingsCount, self::BOOKING_COUNT_MILESTONES);
        if ($bookingMilestone !== null) {
            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'analytics_milestones',
                title: 'Booking milestone reached',
                body: "You have reached {$bookingMilestone} total bookings.",
                dedupeKey: 'booking:count:'.$bookingMilestone,
                ctaUrl: '/account/sitepage?section=bookings',
                retentionConfigKey: 'analytics_milestones',
            );
        }

        $revenueMilestone = $this->latestReachedThreshold($totalSpentCents, self::BOOKING_REVENUE_MILESTONES_CENTS);
        if ($revenueMilestone !== null) {
            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'analytics_milestones',
                title: 'Booking revenue milestone reached',
                body: 'Bookings revenue reached '.Money::format($revenueMilestone, 'AUD').'.',
                dedupeKey: 'booking:revenue:'.$revenueMilestone,
                ctaUrl: '/account/sitepage?section=bookings',
                retentionConfigKey: 'analytics_milestones',
            );
        }
    }

    /**
     * @param  array<int, int>  $thresholds
     */
    private function latestReachedThreshold(int $value, array $thresholds): ?int
    {
        $reached = collect($thresholds)
            ->filter(static fn (int $threshold): bool => $value >= $threshold)
            ->values();

        if ($reached->isEmpty()) {
            return null;
        }

        return (int) $reached->max();
    }
}
