<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Public\PublicSiteResolver;
use App\Services\Square\SquareApiClient;
use App\Services\Square\SquareApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PublicBookingController extends ApiController
{
    use ResolvesSubdomainFromHost;

    public function __construct(
        private readonly PublicSiteResolver $siteResolver,
        private readonly SquareApiClient $squareApiClient
    ) {}

    public function config(Request $request): JsonResponse
    {
        try {
            [$site, $professional, $errorResponse] = $this->resolveSquareContext($request);
            if ($errorResponse) {
                return $errorResponse;
            }

            $location = $this->resolvePrimaryLocation($professional);
        } catch (SquareApiException $e) {
            Log::warning('Public booking config failed', [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'message' => $e->getMessage(),
            ]);

            return $this->error('Online booking is temporarily unavailable. Please try again shortly.', 502);
        } catch (\Throwable $e) {
            Log::warning('Public booking config failed unexpectedly', [
                'message' => $e->getMessage(),
            ]);

            return $this->error('Online booking is currently unavailable. Please reconnect your booking integration.', 409);
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
        } catch (SquareApiException $e) {
            Log::warning('Public booking services failed', [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'message' => $e->getMessage(),
            ]);

            return $this->error('Services are temporarily unavailable. Please try again shortly.', 502);
        } catch (\Throwable $e) {
            Log::warning('Public booking services failed unexpectedly', [
                'message' => $e->getMessage(),
            ]);

            return $this->error('Services are currently unavailable. Please reconnect your booking integration.', 409);
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

    public function availability(Request $request): JsonResponse
    {
        try {
            [$site, $professional, $errorResponse] = $this->resolveSquareContext($request);
            if ($errorResponse) {
                return $errorResponse;
            }

            $validated = Validator::make($request->all(), [
                'date' => ['required', 'date_format:Y-m-d'],
                'serviceVariationId' => ['required', 'string', 'max:120'],
                'locationId' => ['nullable', 'string', 'max:120'],
            ])->validate();

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

            return $this->error('Available times are currently unavailable. Please reconnect your booking integration.', 409);
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

    public function checkout(Request $request): JsonResponse
    {
        try {
            [$site, $professional, $errorResponse] = $this->resolveSquareContext($request);
            if ($errorResponse) {
                return $errorResponse;
            }

            $validated = Validator::make($request->all(), [
                'serviceVariationId' => ['required', 'string', 'max:120'],
                'serviceVariationVersion' => ['required', 'integer', 'min:1'],
                'teamMemberId' => ['required', 'string', 'max:120'],
                'durationMinutes' => ['nullable', 'integer', 'min:1'],
                'startAt' => ['required', 'string', 'max:80'],
                'locationId' => ['nullable', 'string', 'max:120'],
                'paymentMethod' => ['nullable', 'string', 'in:apple_pay,card'],
                'sourceId' => ['nullable', 'string', 'max:255'],
                'customer' => ['required', 'array'],
                'customer.firstName' => ['required', 'string', 'max:120'],
                'customer.lastName' => ['required', 'string', 'max:120'],
                'customer.email' => ['required', 'email', 'max:190'],
                'customer.phone' => ['nullable', 'string', 'max:60'],
                'customer.note' => ['nullable', 'string', 'max:1000'],
            ])->validate();

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
        } catch (\Throwable $e) {
            Log::warning('Public booking checkout failed unexpectedly', [
                'site_id' => $site?->id,
                'professional_id' => $professional?->id,
                'message' => $e->getMessage(),
            ]);

            return $this->error('Booking could not be completed right now. Please try again.', 500);
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

        $site->loadMissing('professional');
        $professional = $site->professional;
        if (! $professional) {
            return [$site, null, $this->error('Booking is not available for this site.', 409)];
        }

        $settings = is_array($site->settings) ? $site->settings : [];
        $bookingMode = strtolower((string) ($settings['booking_mode'] ?? ''));
        $smartEnabled = (bool) ($settings['services_auto_sync_enabled'] ?? false) || $bookingMode === 'smart';

        if (! $smartEnabled) {
            return [$site, $professional, $this->error('Online booking is not enabled for this site.', 409)];
        }

        try {
            if (empty($professional->square_access_token) || empty($professional->square_merchant_id)) {
                return [$site, $professional, $this->error('Booking integration is not connected for this site.', 409)];
            }
        } catch (DecryptException) {
            return [$site, $professional, $this->error('Booking integration needs to be reconnected for this site.', 409)];
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
}
