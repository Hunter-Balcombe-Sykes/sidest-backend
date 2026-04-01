<?php

namespace App\Services\Notifications;

use App\Models\Retail\RetailOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommerceNotificationService
{
    /** @var array<int, int> */
    private const SALES_ORDER_MILESTONES = [1, 5, 10, 25, 50, 100, 250];

    /** @var array<int, int> */
    private const SALES_REVENUE_MILESTONES_CENTS = [10_000, 50_000, 100_000, 250_000, 500_000, 1_000_000];

    /** @var array<int, int> */
    private const BOOKING_COUNT_MILESTONES = [1, 5, 10, 25, 50, 100];

    /** @var array<int, int> */
    private const BOOKING_REVENUE_MILESTONES_CENTS = [5_000, 10_000, 25_000, 50_000, 100_000, 250_000];

    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function notifyStoreOrderById(string $orderId): void
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return;
        }

        try {
            $order = RetailOrder::query()->find($orderId);
            if (! $order instanceof RetailOrder) {
                return;
            }

            $this->notifyStoreOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Store order notifications failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function notifyStoreOrder(RetailOrder $order): void
    {
        $brandProfessionalId = trim((string) $order->brand_professional_id);
        $affiliateProfessionalId = trim((string) $order->affiliate_professional_id);
        if ($brandProfessionalId === '' || $affiliateProfessionalId === '') {
            return;
        }

        $ids = collect([$brandProfessionalId, $affiliateProfessionalId])->unique()->values()->all();
        $professionals = DB::table('core.professionals')
            ->whereIn('id', $ids)
            ->get(['id', 'display_name', 'handle'])
            ->mapWithKeys(fn ($row): array => [
                (string) $row->id => trim((string) ($row->display_name ?: $row->handle ?: 'Account')),
            ]);

        $affiliateName = (string) ($professionals[$affiliateProfessionalId] ?? 'Partner');
        $orderLabel = trim((string) ($order->order_name ?? ''));
        if ($orderLabel === '') {
            $shopifyOrderId = trim((string) ($order->shopify_order_id ?? ''));
            $orderLabel = $shopifyOrderId !== '' ? '#'.$shopifyOrderId : 'Order';
        }

        $currencyCode = strtoupper(trim((string) ($order->currency_code ?? 'AUD')));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        $netCents = max(0, (int) ($order->net_cents ?? 0));
        $amountLabel = $this->formatMoneyFromCents($netCents, $currencyCode);
        $ctaForUser = '/account/store?section=analytics&order='.$order->id;
        $ctaForBrand = '/account/commerce?section=analytics&order='.$order->id;

        $this->publisher->publish(
            professionalId: $affiliateProfessionalId,
            frontendType: 'Success',
            category: 'analytics_milestones',
            title: 'New sale received',
            body: "{$orderLabel} generated {$amountLabel} in sales.",
            dedupeKey: 'store-sale:affiliate:'.$order->id,
            ctaUrl: $ctaForUser,
            retentionConfigKey: 'analytics_milestones',
        );

        $this->publisher->publish(
            professionalId: $brandProfessionalId,
            frontendType: 'Info',
            category: 'analytics_milestones',
            title: 'New affiliate sale',
            body: "{$affiliateName} generated {$amountLabel} in sales ({$orderLabel}).",
            dedupeKey: 'store-sale:brand:'.$order->id,
            ctaUrl: $ctaForBrand,
            retentionConfigKey: 'analytics_milestones',
        );

        $this->notifySalesMilestonesForProfessional($affiliateProfessionalId, false);
        $this->notifySalesMilestonesForProfessional($brandProfessionalId, true);
    }

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

            $amountLabel = $this->formatMoneyFromCents($amountPaidCents, $currencyCode);
            $serviceLabel = $serviceName !== '' ? $serviceName : 'Service';
            $customerLabel = $customerName !== '' ? $customerName : 'Customer';
            $eventKey = $eventId !== '' ? $eventId : ($bookingId !== '' ? $bookingId : Str::uuid()->toString());

            $affiliateName = (string) (DB::table('core.professionals')
                ->where('id', $professionalId)
                ->value(DB::raw("COALESCE(NULLIF(display_name, ''), NULLIF(handle, ''), 'Partner')")));

            $ctaUrl = '/account/booking?section=analytics&event='.$eventKey;
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

    private function notifySalesMilestonesForProfessional(string $professionalId, bool $isBrand): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $column = $isBrand ? 'brand_professional_id' : 'affiliate_professional_id';
        $totals = DB::table('retail.orders')
            ->where($column, $professionalId)
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(net_cents), 0) as revenue_cents')
            ->first();

        $ordersCount = max(0, (int) ($totals->orders_count ?? 0));
        $revenueCents = max(0, (int) ($totals->revenue_cents ?? 0));
        $basePath = $isBrand ? '/account/commerce?section=analytics' : '/account/store?section=analytics';

        $orderMilestone = $this->latestReachedThreshold($ordersCount, self::SALES_ORDER_MILESTONES);
        if ($orderMilestone !== null) {
            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'analytics_milestones',
                title: 'Sales milestone reached',
                body: "You have reached {$orderMilestone} total sales.",
                dedupeKey: ($isBrand ? 'brand' : 'user').':sales:orders:'.$orderMilestone,
                ctaUrl: $basePath,
                retentionConfigKey: 'analytics_milestones',
            );
        }

        $revenueMilestoneCents = $this->latestReachedThreshold($revenueCents, self::SALES_REVENUE_MILESTONES_CENTS);
        if ($revenueMilestoneCents !== null) {
            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'analytics_milestones',
                title: 'Revenue milestone reached',
                body: 'Total sales revenue reached '.$this->formatMoneyFromCents($revenueMilestoneCents, 'AUD').'.',
                dedupeKey: ($isBrand ? 'brand' : 'user').':sales:revenue:'.$revenueMilestoneCents,
                ctaUrl: $basePath,
                retentionConfigKey: 'analytics_milestones',
            );
        }
    }

    private function notifyBookingMilestonesForProfessional(string $professionalId): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $totals = DB::table('analytics.booking_events')
            ->where('professional_id', $professionalId)
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(amount_paid_cents), 0) as total_spent_cents')
            ->first();

        $bookingsCount = max(0, (int) ($totals->bookings_count ?? 0));
        $totalSpentCents = max(0, (int) ($totals->total_spent_cents ?? 0));

        $bookingMilestone = $this->latestReachedThreshold($bookingsCount, self::BOOKING_COUNT_MILESTONES);
        if ($bookingMilestone !== null) {
            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'analytics_milestones',
                title: 'Booking milestone reached',
                body: "You have reached {$bookingMilestone} total bookings.",
                dedupeKey: 'booking:count:'.$bookingMilestone,
                ctaUrl: '/account/booking?section=analytics',
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
                body: 'Bookings revenue reached '.$this->formatMoneyFromCents($revenueMilestone, 'AUD').'.',
                dedupeKey: 'booking:revenue:'.$revenueMilestone,
                ctaUrl: '/account/booking?section=analytics',
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

    private function formatMoneyFromCents(int $cents, string $currencyCode): string
    {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        $prefix = match ($currencyCode) {
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'AUD' => 'A$',
            default => $currencyCode.' ',
        };

        return $prefix.number_format($cents / 100, 2, '.', ',');
    }
}
