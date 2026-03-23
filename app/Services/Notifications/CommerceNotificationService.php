<?php

namespace App\Services\Notifications;

use App\Models\Core\Notifications\Notification;
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

        $this->insertNotificationIfMissing(
            professionalId: $affiliateProfessionalId,
            frontendType: 'Success',
            title: 'New sale received',
            body: "{$orderLabel} generated {$amountLabel} in sales.",
            ctaUrl: $ctaForUser,
            dedupeKey: 'store-sale:affiliate:'.$order->id
        );

        $this->insertNotificationIfMissing(
            professionalId: $brandProfessionalId,
            frontendType: 'Info',
            title: 'New affiliate sale',
            body: "{$affiliateName} generated {$amountLabel} in sales ({$orderLabel}).",
            ctaUrl: $ctaForBrand,
            dedupeKey: 'store-sale:brand:'.$order->id
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

            $this->insertNotificationIfMissing(
                professionalId: $professionalId,
                frontendType: 'Success',
                title: 'New booking received',
                body: $body,
                ctaUrl: $ctaUrl,
                dedupeKey: 'booking:user:'.$eventKey
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

                $this->insertNotificationIfMissing(
                    professionalId: $brandProfessionalId,
                    frontendType: 'Info',
                    title: 'New partner booking',
                    body: $brandBody,
                    ctaUrl: '/account/commerce?section=analytics&booking='.$eventKey,
                    dedupeKey: 'booking:brand:'.$brandProfessionalId.':'.$eventKey
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
            $this->insertNotificationIfMissing(
                professionalId: $professionalId,
                frontendType: 'Success',
                title: 'Sales milestone reached',
                body: "You have reached {$orderMilestone} total sales.",
                ctaUrl: $basePath,
                dedupeKey: ($isBrand ? 'brand' : 'user').':sales:orders:'.$orderMilestone
            );
        }

        $revenueMilestoneCents = $this->latestReachedThreshold($revenueCents, self::SALES_REVENUE_MILESTONES_CENTS);
        if ($revenueMilestoneCents !== null) {
            $this->insertNotificationIfMissing(
                professionalId: $professionalId,
                frontendType: 'Success',
                title: 'Revenue milestone reached',
                body: 'Total sales revenue reached '.$this->formatMoneyFromCents($revenueMilestoneCents, 'AUD').'.',
                ctaUrl: $basePath,
                dedupeKey: ($isBrand ? 'brand' : 'user').':sales:revenue:'.$revenueMilestoneCents
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
            $this->insertNotificationIfMissing(
                professionalId: $professionalId,
                frontendType: 'Success',
                title: 'Booking milestone reached',
                body: "You have reached {$bookingMilestone} total bookings.",
                ctaUrl: '/account/booking?section=analytics',
                dedupeKey: 'booking:count:'.$bookingMilestone
            );
        }

        $revenueMilestone = $this->latestReachedThreshold($totalSpentCents, self::BOOKING_REVENUE_MILESTONES_CENTS);
        if ($revenueMilestone !== null) {
            $this->insertNotificationIfMissing(
                professionalId: $professionalId,
                frontendType: 'Success',
                title: 'Booking revenue milestone reached',
                body: 'Bookings revenue reached '.$this->formatMoneyFromCents($revenueMilestone, 'AUD').'.',
                ctaUrl: '/account/booking?section=analytics',
                dedupeKey: 'booking:revenue:'.$revenueMilestone
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

    private function insertNotificationIfMissing(
        string $professionalId,
        string $frontendType,
        string $title,
        string $body,
        string $ctaUrl,
        string $dedupeKey
    ): void {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            return;
        }

        $cta = $this->withDedupeKey($ctaUrl, $dedupeKey);
        $exists = DB::table('notifications')
            ->where('professional_id', $professionalId)
            ->where('cta_url', $cta)
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        $type = Notification::normalizeFrontendType($frontendType);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $professionalId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'cta_url' => $cta,
            'primary_action_label' => 'View',
            'secondary_action_label' => 'Dismiss',
            'secondary_action_url' => null,
            'severity' => Notification::severityForFrontendType($type),
            'starts_at' => $now,
            'ends_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function withDedupeKey(string $url, string $dedupeKey): string
    {
        $trimmedUrl = trim($url);
        if ($trimmedUrl === '') {
            $trimmedUrl = '/account/overview';
        }

        $join = str_contains($trimmedUrl, '?') ? '&' : '?';

        return $trimmedUrl.$join.'notif='.$dedupeKey;
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
