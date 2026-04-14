<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Jobs\Analytics\RebuildBookingDailyAggregatesJob;
use App\Jobs\Analytics\RebuildBookingHourlyAggregatesJob;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Customers\ContactCaptureService;
use App\Services\Notifications\CommerceNotificationService;
use App\Services\Public\PublicSiteResolver;
use App\Services\Square\SquareApiClient;
use App\Services\Square\SquareApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// V2: Public booking flow (config, services, availability, checkout via Square). Booking integration — unrelated to V2 commerce.
class PublicBookingController extends ApiController
{
    use ResolvesSubdomainFromHost;

    public function __construct(
        private readonly PublicSiteResolver $siteResolver,
        private readonly SquareApiClient $squareApiClient,
        private readonly CommerceNotificationService $commerceNotifications,
        private readonly ContactCaptureService $contactCapture,
    ) {}

    /**
     * Handle common booking error scenarios with consistent error responses.
     */
    private function handleBookingError(\Throwable $e, ?Site $site = null, ?Professional $professional = null, string $context = 'booking operation'): JsonResponse
    {
        if ($e instanceof DecryptException) {
            Log::warning("Public {$context} failed (decrypt)", [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'message' => $e->getMessage(),
            ]);
            return $this->error('Booking integration credentials could not be read. Please reconnect your booking integration.', 409);
        }

        if ($e instanceof SquareApiException) {
            Log::warning("Public {$context} failed", [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'message' => $e->getMessage(),
            ]);
            return $this->error(ucfirst($context) . ' temporarily unavailable. Please try again shortly.', 502);
        }

        Log::warning("Public {$context} failed unexpectedly", [
            'site_id' => $site?->id,
            'professional_id' => $professional?->id,
            'message' => $e->getMessage(),
        ]);
        return $this->error(sprintf('%s currently unavailable. (%s)', ucfirst($context), $this->diagnosticCode($e)), 409);
    }

    public function config(Request $request): JsonResponse
    {
        try {
            [$site, $professional, $errorResponse] = $this->resolveSquareContext($request);
            if ($errorResponse) {
                return $errorResponse;
            }

            $location = $this->resolvePrimaryLocation($professional);
        } catch (\Throwable $e) {
            return $this->handleBookingError($e, $site ?? null, $professional ?? null, 'booking config');
        }

        $applicationId = trim((string) config('services.square.application_id', ''));
        if ($applicationId === '') {
            return $this->error('Online booking is not configured yet.', 503);
        }

        return $this->success([
            'booking_enabled' => true,
            'application_id' => $applicationId,
            'location' => $location,
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        try {
            [$site, $professional, $errorResponse] = $this->resolveSquareContext($request);
            if ($errorResponse) {
                return $errorResponse;
            }

            $fetched = $this->squareApiClient->fetchAppointmentServiceVariations($professional, null);
            $rows = is_array($fetched['services'] ?? null) ? $fetched['services'] : [];
        } catch (\Throwable $e) {
            return $this->handleBookingError($e, $site ?? null, $professional ?? null, 'booking services');
        }

        $services = collect($rows)
            ->filter(fn ($row) => is_array($row) && ! (bool) ($row['deleted'] ?? false))
            ->filter(fn ($row) => (bool) ($row['available_for_booking'] ?? false))
            ->filter(fn ($row) => trim((string) ($row['variation_id'] ?? '')) !== '')
            ->map(function (array $row): array {
                $itemName = trim((string) ($row['item_name'] ?? 'Service'));
                $variationName = $this->normalizeVariationName(
                    trim((string) ($row['variation_name'] ?? '')),
                    $itemName
                );

                return [
                    'id' => (string) ($row['item_id'] ?? ''),
                    'variationId' => (string) ($row['variation_id'] ?? ''),
                    'name' => $itemName !== '' ? $itemName : 'Service',
                    'variationName' => $variationName,
                    'description' => isset($row['item_description']) ? (string) $row['item_description'] : null,
                    'durationMinutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
                    'priceCents' => isset($row['price_cents']) ? (int) $row['price_cents'] : null,
                    'currency' => isset($row['currency_code']) ? (string) $row['currency_code'] : null,
                    'availableForBooking' => (bool) ($row['available_for_booking'] ?? false),
                    'category' => trim((string) ($row['square_category_name'] ?? '')) ?: 'Services',
                ];
            })
            ->values()
            ->all();

        return $this->success([
            'services' => $services,
            'count' => count($services),
        ]);
    }

    public function availability(\App\Http\Requests\Api\PublicSite\Booking\PublicBookingAvailabilityRequest $request): JsonResponse
    {
        try {
            [$site, $professional, $errorResponse] = $this->resolveSquareContext($request);
            if ($errorResponse) {
                return $errorResponse;
            }

            $validated = $request->validated();

            $location = $this->resolveLocation($professional, $validated['locationId'] ?? null);
            $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['date']);
            $startAt = $date->startOfDay()->toIso8601String();
            $endAt = $date->endOfDay()->toIso8601String();

            $response = $this->squareApiClient->request($professional, 'POST', '/v2/bookings/availability/search', [], [
                'query' => [
                    'filter' => [
                        'location_id' => $location['id'],
                        'start_at_range' => [
                            'start_at' => $startAt,
                            'end_at' => $endAt,
                        ],
                        'segment_filters' => [[
                            'service_variation_id' => (string) $validated['serviceVariationId'],
                        ]],
                    ],
                ],
            ]);
        } catch (DecryptException $e) {
            Log::warning('Public booking availability failed (decrypt)', [
                'message' => $e->getMessage(),
            ]);

            return $this->error('Booking integration credentials could not be read. Please reconnect your booking integration.', 409);
        } catch (SquareApiException $e) {
            Log::warning('Public booking availability failed', [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'message' => $e->getMessage(),
            ]);

            return $this->error('Available times could not be loaded. Please try another date.', 502);
        } catch (\Throwable $e) {
            Log::warning('Public booking availability failed unexpectedly', [
                'message' => $e->getMessage(),
            ]);

            return $this->error(sprintf('Available times are currently unavailable. (%s)', $this->diagnosticCode($e)), 409);
        }

        $availabilities = is_array($response['availabilities'] ?? null) ? $response['availabilities'] : [];
        $slots = [];

        foreach ($availabilities as $availability) {
            if (! is_array($availability)) {
                continue;
            }
            $segments = is_array($availability['appointment_segments'] ?? null) ? $availability['appointment_segments'] : [];
            foreach ($segments as $segment) {
                if (! is_array($segment)) {
                    continue;
                }
                $slots[] = [
                    'startAt' => (string) ($availability['start_at'] ?? ''),
                    'locationId' => (string) ($availability['location_id'] ?? ''),
                    'teamMemberId' => (string) ($segment['team_member_id'] ?? ''),
                    'serviceVariationId' => (string) ($segment['service_variation_id'] ?? ''),
                    'serviceVariationVersion' => (int) ($segment['service_variation_version'] ?? 0),
                    'durationMinutes' => (int) ($segment['duration_minutes'] ?? 0),
                ];
            }
        }

        return $this->success([
            'availabilities' => $slots,
            'count' => count($slots),
        ]);
    }

    public function checkout(\App\Http\Requests\Api\PublicSite\Booking\PublicBookingCheckoutRequest $request): JsonResponse
    {
        try {
            [$site, $professional, $errorResponse] = $this->resolveSquareContext($request);
            if ($errorResponse) {
                return $errorResponse;
            }

            $validated = $request->validated();

            // Non-blocking local CRM sync at checkout intent time.
            // Mirrors store behavior so contacts are captured even if payment later fails.
            $this->syncBookedCustomerContact($professional, $validated['customer'] ?? []);

            $location = $this->resolveLocation($professional, $validated['locationId'] ?? null);
            $service = $this->resolveBookableServiceVariation(
                $professional,
                (string) $validated['serviceVariationId']
            );
            $priceCents = (int) ($service['price_cents'] ?? 0);
            $currencyCode = strtoupper((string) ($service['currency_code'] ?? $location['currency'] ?? 'AUD'));
            $requiresPayment = $priceCents > 0;

            if ($requiresPayment && ! is_string($validated['sourceId'] ?? null)) {
                return $this->error('Please select a payment method to complete your booking.', 422);
            }

            $customerPayload = [
                'given_name' => (string) $validated['customer']['firstName'],
                'family_name' => (string) $validated['customer']['lastName'],
                'email_address' => (string) $validated['customer']['email'],
            ];
            if (! empty($validated['customer']['phone'])) {
                $customerPayload['phone_number'] = (string) $validated['customer']['phone'];
            }
            if (! empty($validated['customer']['note'])) {
                $customerPayload['note'] = (string) $validated['customer']['note'];
            }

            $customerResponse = $this->squareApiClient->request($professional, 'POST', '/v2/customers', [], $customerPayload);
            $customerId = (string) data_get($customerResponse, 'customer.id', '');
            if ($customerId === '') {
                return $this->error('Could not create booking profile. Please try again.', 502);
            }

            $preferredPaymentMethod = ($validated['paymentMethod'] ?? 'card') === 'apple_pay'
                ? 'Apple Pay'
                : 'Card';

            $customerNoteParts = array_values(array_filter([
                trim((string) ($validated['customer']['note'] ?? '')),
                'Preferred payment method: ' . $preferredPaymentMethod,
            ]));

            $bookingResponse = $this->squareApiClient->request($professional, 'POST', '/v2/bookings', [], [
                'booking' => [
                    'location_id' => $location['id'],
                    'start_at' => (string) $validated['startAt'],
                    'customer_id' => $customerId,
                    'customer_note' => ! empty($customerNoteParts) ? implode(' • ', $customerNoteParts) : null,
                    'appointment_segments' => [[
                        'service_variation_id' => (string) $validated['serviceVariationId'],
                        'service_variation_version' => (int) $validated['serviceVariationVersion'],
                        'team_member_id' => (string) $validated['teamMemberId'],
                        'duration_minutes' => isset($validated['durationMinutes']) ? (int) $validated['durationMinutes'] : null,
                    ]],
                ],
            ]);

            $bookingId = (string) data_get($bookingResponse, 'booking.id', '');
            $bookingVersion = (int) data_get($bookingResponse, 'booking.version', 0);

            if (! $requiresPayment) {
                $this->recordBookingAnalyticsAndNotify(
                    site: $site,
                    professional: $professional,
                    validated: $validated,
                    service: $service,
                    bookingId: $bookingId,
                    bookingStatus: (string) data_get($bookingResponse, 'booking.status', ''),
                    paymentId: null,
                    amountPaidCents: 0,
                    currencyCode: $currencyCode
                );

                return $this->success([
                    'success' => true,
                    'booking' => [
                        'id' => $bookingId,
                        'status' => (string) data_get($bookingResponse, 'booking.status', ''),
                    ],
                    'paid' => false,
                ], 201);
            }

            try {
                $paymentResponse = $this->squareApiClient->request($professional, 'POST', '/v2/payments', [], [
                    'idempotency_key' => (string) Str::uuid(),
                    'source_id' => (string) $validated['sourceId'],
                    'amount_money' => [
                        'amount' => $priceCents,
                        'currency' => $currencyCode !== '' ? $currencyCode : 'AUD',
                    ],
                    'autocomplete' => true,
                    'location_id' => $location['id'],
                    'customer_id' => $customerId,
                    'reference_id' => $bookingId !== '' ? $bookingId : null,
                    'note' => $service['item_name'] ?? 'Booking payment',
                ]);
            } catch (SquareApiException $paymentError) {
                // Revert booking if payment fails so customers are not left with unpaid confirmed bookings.
                if ($bookingId !== '' && $bookingVersion > 0) {
                    try {
                        $this->squareApiClient->request($professional, 'POST', '/v2/bookings/' . $bookingId . '/cancel', [], [
                            'booking_version' => $bookingVersion,
                        ]);
                    } catch (\Throwable) {
                        // Best effort only.
                    }
                }

                return $this->error(
                    $this->mapFriendlyPaymentError($paymentError),
                    422
                );
            }

            $paymentId = (string) data_get($paymentResponse, 'payment.id', '');
            $this->recordBookingAnalyticsAndNotify(
                site: $site,
                professional: $professional,
                validated: $validated,
                service: $service,
                bookingId: $bookingId,
                bookingStatus: (string) data_get($bookingResponse, 'booking.status', ''),
                paymentId: $paymentId !== '' ? $paymentId : null,
                amountPaidCents: $priceCents,
                currencyCode: $currencyCode
            );

            return $this->success([
                'success' => true,
                'booking' => [
                    'id' => $bookingId,
                    'status' => (string) data_get($bookingResponse, 'booking.status', ''),
                ],
                'payment' => [
                    'id' => (string) data_get($paymentResponse, 'payment.id', ''),
                    'status' => (string) data_get($paymentResponse, 'payment.status', ''),
                    'receiptUrl' => data_get($paymentResponse, 'payment.receipt_url'),
                ],
                'paid' => true,
            ], 201);
        } catch (SquareApiException $e) {
            Log::warning('Public booking checkout failed', [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            return $this->error('Booking could not be completed right now. Please try again.', 502);
        } catch (DecryptException $e) {
            Log::warning('Public booking checkout failed (decrypt)', [
                'message' => $e->getMessage(),
            ]);

            return $this->error('Booking integration credentials could not be read. Please reconnect your booking integration.', 409);
        } catch (\Throwable $e) {
            Log::warning('Public booking checkout failed unexpectedly', [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'message' => $e->getMessage(),
            ]);

            return $this->error(sprintf('Booking could not be completed right now. (%s)', $this->diagnosticCode($e)), 500);
        }
    }

    /**
     * @return array{0: Site|null, 1: Professional|null, 2: JsonResponse|null}
     */
    private function resolveSquareContext(Request $request): array
    {
        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return [null, null, $this->error('Missing site identifier.', 400)];
        }

        $site = $this->siteResolver->resolvePublishedSite($subdomain);
        if (! $site) {
            return [null, null, $this->error('Site not found.', 404)];
        }

        $professional = Professional::query()->find($site->professional_id);
        if (! $professional) {
            return [$site, null, $this->error('Booking is not available for this site.', 409)];
        }

        $settings = is_array($site->settings) ? $site->settings : [];
        $bookingMode = strtolower((string) ($settings['booking_mode'] ?? ''));
        $smartEnabled = (bool) ($settings['services_auto_sync_enabled'] ?? false) || $bookingMode === 'smart';

        if (! $smartEnabled) {
            return [$site, $professional, $this->error('Online booking is not enabled for this site.', 409)];
        }

        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
        $rawToken = trim((string) ($integration?->getRawOriginal('access_token') ?? ''));
        $merchantId = trim((string) ($integration?->external_account_id ?? ''));
        if ($rawToken === '' || $merchantId === '') {
            return [$site, $professional, $this->error('Booking integration is not connected for this site.', 409)];
        }

        return [$site, $professional, null];
    }

    private function resolveSiteSubdomain(Request $request): ?string
    {
        $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($fromHeader !== '') {
            return strtolower($fromHeader);
        }

        $fromQuery = trim((string) $request->query('slug', ''));
        if ($fromQuery !== '') {
            return strtolower($fromQuery);
        }

        $fromInput = trim((string) $request->input('slug', ''));
        if ($fromInput !== '') {
            return strtolower($fromInput);
        }

        $fromHost = $this->resolveSubdomainFromHost($request);
        if (is_string($fromHost) && $fromHost !== '') {
            return strtolower($fromHost);
        }

        return null;
    }

    /**
     * @return array{id:string,name:string,country:string,currency:string,status:string}
     */
    private function resolvePrimaryLocation(Professional $professional): array
    {
        $response = $this->squareApiClient->request($professional, 'GET', '/v2/locations');
        $locations = is_array($response['locations'] ?? null) ? $response['locations'] : [];

        $active = collect($locations)->first(function ($location) {
            return is_array($location) && strtoupper((string) ($location['status'] ?? '')) === 'ACTIVE';
        });
        $selected = is_array($active) ? $active : (is_array($locations[0] ?? null) ? $locations[0] : null);

        if (! $selected || empty($selected['id'])) {
            throw new SquareApiException('No Square locations found.');
        }

        return [
            'id' => (string) ($selected['id'] ?? ''),
            'name' => (string) ($selected['name'] ?? ''),
            'country' => strtoupper((string) ($selected['country'] ?? 'AU')),
            'currency' => strtoupper((string) ($selected['currency'] ?? 'AUD')),
            'status' => strtoupper((string) ($selected['status'] ?? 'ACTIVE')),
        ];
    }

    /**
     * @param  string|null  $locationId
     * @return array{id:string,name:string,country:string,currency:string,status:string}
     */
    private function resolveLocation(Professional $professional, ?string $locationId): array
    {
        $primary = $this->resolvePrimaryLocation($professional);
        $targetId = trim((string) ($locationId ?? ''));
        if ($targetId === '' || $targetId === $primary['id']) {
            return $primary;
        }

        $response = $this->squareApiClient->request($professional, 'GET', '/v2/locations');
        $locations = is_array($response['locations'] ?? null) ? $response['locations'] : [];
        foreach ($locations as $location) {
            if (! is_array($location)) {
                continue;
            }
            if ((string) ($location['id'] ?? '') !== $targetId) {
                continue;
            }

            return [
                'id' => (string) ($location['id'] ?? ''),
                'name' => (string) ($location['name'] ?? ''),
                'country' => strtoupper((string) ($location['country'] ?? 'AU')),
                'currency' => strtoupper((string) ($location['currency'] ?? 'AUD')),
                'status' => strtoupper((string) ($location['status'] ?? 'ACTIVE')),
            ];
        }

        return $primary;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBookableServiceVariation(Professional $professional, string $variationId): array
    {
        $fetched = $this->squareApiClient->fetchAppointmentServiceVariations($professional, null);
        $rows = is_array($fetched['services'] ?? null) ? $fetched['services'] : [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((bool) ($row['deleted'] ?? false)) {
                continue;
            }
            if ((string) ($row['variation_id'] ?? '') !== $variationId) {
                continue;
            }
            if (! (bool) ($row['available_for_booking'] ?? false)) {
                continue;
            }

            return $row;
        }

        throw new SquareApiException('Selected service is no longer available.', 422);
    }

    private function normalizeVariationName(string $variationName, string $itemName): string
    {
        if ($variationName === '') {
            return '';
        }

        $lower = mb_strtolower($variationName);
        if (in_array($lower, ['regular', 'default'], true)) {
            return '';
        }
        if (strcasecmp($variationName, $itemName) === 0) {
            return '';
        }

        return $variationName;
    }

    private function mapFriendlyPaymentError(SquareApiException $exception): string
    {
        $firstError = null;
        $errors = is_array($exception->payload['errors'] ?? null) ? $exception->payload['errors'] : [];
        if (isset($errors[0]) && is_array($errors[0])) {
            $firstError = $errors[0];
        }

        $code = strtoupper((string) ($firstError['code'] ?? ''));
        $detail = trim((string) ($firstError['detail'] ?? ''));

        return match ($code) {
            'INSUFFICIENT_FUNDS' => 'Payment method has insufficient funds.',
            'CARD_DECLINED', 'GENERIC_DECLINE', 'DO_NOT_HONOR' => 'Payment was declined. Please try another payment method.',
            'CVV_FAILURE', 'VERIFY_CVV_FAILURE' => 'Security code is incorrect. Please check your card details.',
            'ADDRESS_VERIFICATION_FAILURE' => 'Billing address could not be verified. Please check your details.',
            'EXPIRATION_FAILURE', 'INVALID_EXPIRATION', 'CARD_EXPIRED' => 'Card is expired. Please use another payment method.',
            'CARD_TOKEN_EXPIRED', 'TOKEN_EXPIRED', 'TOKEN_USED' => 'Payment session expired. Please try again.',
            'INVALID_CARD', 'INVALID_CARD_DATA' => 'Payment details are invalid. Please check and try again.',
            'PAYMENT_LIMIT_EXCEEDED' => 'This payment exceeds your card limit. Please try a different payment method.',
            'AMOUNT_TOO_LOW', 'AMOUNT_TOO_HIGH' => 'Payment amount is invalid. Please refresh and try again.',
            default => $this->fallbackFriendlyPaymentDetail($detail),
        };
    }

    private function fallbackFriendlyPaymentDetail(string $detail): string
    {
        $lower = mb_strtolower($detail);
        if (str_contains($lower, 'insufficient')) {
            return 'Payment method has insufficient funds.';
        }
        if (str_contains($lower, 'declin')) {
            return 'Payment was declined. Please try another payment method.';
        }
        if (str_contains($lower, 'cvv')) {
            return 'Security code is incorrect. Please check your card details.';
        }
        if (str_contains($lower, 'address')) {
            return 'Billing address could not be verified. Please check your details.';
        }
        if (str_contains($lower, 'expired')) {
            return 'Card is expired. Please use another payment method.';
        }

        return 'Payment could not be completed. Please try another payment method.';
    }

    /**
     * Upsert a local CRM contact after successful public booking checkout.
     * Delegates to ContactCaptureService, which is non-blocking: booking success
     * never fails if contact sync fails.
     *
     * @param  array<string, mixed>  $customerData
     */
    private function syncBookedCustomerContact(Professional $professional, array $customerData): void
    {
        $firstName = trim((string) ($customerData['firstName'] ?? ''));
        $lastName = trim((string) ($customerData['lastName'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);

        $this->contactCapture->captureContact((string) $professional->id, [
            'email' => (string) ($customerData['email'] ?? ''),
            'full_name' => $fullName !== '' ? $fullName : null,
            'phone' => (string) ($customerData['phone'] ?? ''),
            'source' => 'square_booking',
        ]);
    }

    /**
     * Persist booking analytics and trigger in-app notifications.
     * This is non-blocking and should never fail checkout.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $service
     */
    private function recordBookingAnalyticsAndNotify(
        Site $site,
        Professional $professional,
        array $validated,
        array $service,
        string $bookingId,
        string $bookingStatus,
        ?string $paymentId,
        int $amountPaidCents,
        string $currencyCode
    ): void {
        try {
            $professionalId = (string) $professional->id;
            $siteId = (string) $site->id;
            $bookingId = trim($bookingId);
            $paymentId = trim((string) ($paymentId ?? ''));
            $currencyCode = strtoupper(trim($currencyCode));
            if ($currencyCode === '') {
                $currencyCode = 'AUD';
            }

            $brandProfessionalIds = DB::table('core.brand_partner_links')
                ->where('affiliate_professional_id', $professionalId)
                ->pluck('brand_professional_id')
                ->map(static fn ($id): string => trim((string) $id))
                ->filter(static fn (string $id): bool => $id !== '')
                ->unique()
                ->values()
                ->all();

            $existingEventId = null;
            if ($bookingId !== '') {
                $existingEventId = DB::table('analytics.booking_events')
                    ->where('professional_id', $professionalId)
                    ->where('square_booking_id', $bookingId)
                    ->value('id');
            }

            $eventId = is_string($existingEventId) && trim($existingEventId) !== ''
                ? trim($existingEventId)
                : (string) Str::uuid();

            $serviceName = $this->resolveServiceName($service);
            $firstName = trim((string) ($validated['customer']['firstName'] ?? ''));
            $lastName = trim((string) ($validated['customer']['lastName'] ?? ''));
            $customerName = trim($firstName.' '.$lastName);
            $customerEmail = strtolower(trim((string) ($validated['customer']['email'] ?? '')));
            $customerPhone = trim((string) ($validated['customer']['phone'] ?? ''));
            $appointmentStartAt = $this->nullableTimestamp((string) ($validated['startAt'] ?? ''));
            $occurredAt = now();
            $normalizedStatus = strtolower(trim($bookingStatus));
            if (! in_array($normalizedStatus, ['accepted', 'pending', 'completed', 'cancelled', 'failed'], true)) {
                $normalizedStatus = 'completed';
            }
            $professionalTimezone = trim((string) ($professional->timezone ?? '')) ?: 'UTC';
            $localDay = $occurredAt->copy()->setTimezone($professionalTimezone)->toDateString();

            $attributes = [
                'professional_id' => $professionalId,
                'site_id' => $siteId,
                'brand_professional_id' => count($brandProfessionalIds) === 1 ? $brandProfessionalIds[0] : null,
                'occurred_at' => $occurredAt,
                'appointment_start_at' => $appointmentStartAt,
                'status' => $normalizedStatus,
                'source' => 'site_booking_checkout',
                'square_booking_id' => $bookingId !== '' ? $bookingId : null,
                'square_payment_id' => $paymentId !== '' ? $paymentId : null,
                'service_variation_id' => trim((string) ($validated['serviceVariationId'] ?? '')) ?: null,
                'service_name' => $serviceName !== '' ? $serviceName : null,
                'payment_method' => trim((string) ($validated['paymentMethod'] ?? '')) ?: null,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'customer_email' => $customerEmail !== '' ? $customerEmail : null,
                'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                'currency_code' => $currencyCode,
                'amount_paid_cents' => max(0, $amountPaidCents),
                'raw_payload' => [
                    'validated' => $validated,
                    'resolved_service' => $service,
                    'booking_status' => $bookingStatus,
                    'recorded_at' => now()->toIso8601String(),
                ],
                'updated_at' => now(),
            ];

            if ($existingEventId) {
                DB::table('analytics.booking_events')
                    ->where('id', $eventId)
                    ->update($attributes);
            } else {
                DB::table('analytics.booking_events')
                    ->insert(array_merge($attributes, [
                        'id' => $eventId,
                        'created_at' => now(),
                    ]));
            }

            RebuildBookingHourlyAggregatesJob::dispatch(
                $professionalId,
                $occurredAt->copy()->utc()->startOfHour()->toIso8601String()
            );
            RebuildBookingDailyAggregatesJob::dispatch(
                $professionalId,
                $localDay
            );

            $this->commerceNotifications->notifyBookingCompleted([
                'professional_id' => $professionalId,
                'brand_professional_ids' => $brandProfessionalIds,
                'booking_event_id' => $eventId,
                'booking_id' => $bookingId !== '' ? $bookingId : null,
                'service_name' => $serviceName !== '' ? $serviceName : null,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'amount_paid_cents' => max(0, $amountPaidCents),
                'currency_code' => $currencyCode,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Public booking analytics sync failed', [
                'site_id' => $site->id,
                'professional_id' => $professional->id,
                'booking_id' => $bookingId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function resolveServiceName(array $service): string
    {
        $itemName = trim((string) ($service['item_name'] ?? $service['name'] ?? ''));
        $variationName = trim((string) ($service['variation_name'] ?? ''));
        $normalizedVariationName = $this->normalizeVariationName($variationName, $itemName);

        if ($itemName !== '' && $normalizedVariationName !== '') {
            return $itemName.' - '.$normalizedVariationName;
        }

        if ($itemName !== '') {
            return $itemName;
        }

        return $normalizedVariationName;
    }

    private function diagnosticCode(\Throwable $exception): string
    {
        return class_basename($exception);
    }
}
