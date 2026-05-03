<?php

use App\Http\Requests\Api\Professional\Site\UpdateSiteRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateSiteRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function validateAgainstRequest(string $requestClass, array $payload): array
{
    $request = Request::create('/test', 'PATCH', $payload);
    $formRequest = $requestClass::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UpdateSiteRequest rejects settings.booking_mode=smart when smart_booking flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    $result = validateAgainstRequest(UpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'smart'],
    ]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.booking_mode');
});

it('UpdateSiteRequest accepts settings.booking_mode=smart when smart_booking flag is on', function () {
    config()->set('sidest.features.smart_booking', true);

    $result = validateAgainstRequest(UpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'smart'],
    ]);

    expect($result['errors'] ?? [])->not->toHaveKey('settings.booking_mode');
});

it('UpdateSiteRequest always accepts settings.booking_mode=manual', function () {
    config()->set('sidest.features.smart_booking', false);

    $result = validateAgainstRequest(UpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'manual'],
    ]);

    expect($result['errors'] ?? [])->not->toHaveKey('settings.booking_mode');
});

it('StaffUpdateSiteRequest rejects settings.booking_mode=smart when smart_booking flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    $result = validateAgainstRequest(StaffUpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'smart'],
    ]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.booking_mode');
});
