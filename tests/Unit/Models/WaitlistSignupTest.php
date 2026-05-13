<?php

use App\Models\Core\Waitlist\WaitlistSignup;

it('uses the core.waitlist_signups table', function () {
    expect((new WaitlistSignup)->getTable())->toBe('core.waitlist_signups');
});

it('uses UUID keys', function () {
    $model = new WaitlistSignup;

    expect($model->incrementing)->toBeFalse();
    expect($model->getKeyType())->toBe('string');
});

it('hides PII and consent telemetry from default serialisation', function () {
    // Forensic-style: build the model from raw attributes (not a real insert) so
    // we exercise toArray() in isolation of any DB connection.
    $model = (new WaitlistSignup)->forceFill([
        'id' => 'wl-uuid',
        'name' => 'Jane Visitor',
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'phone' => '+61400000000',
        'consent_ip_hash' => 'hash',
        'consent_user_agent' => 'Mozilla/5.0',
        'applicant_type' => 'brand',
    ]);

    $array = $model->toArray();
    $hiddenKeys = ['name', 'email', 'email_lc', 'phone', 'consent_ip_hash', 'consent_user_agent'];
    $leaked = array_intersect($hiddenKeys, array_keys($array));

    expect($leaked)->toBe([], 'PII keys leaked through toArray(): '.implode(',', $leaked));

    // Non-PII fields should still serialise so the model stays useful for analytics.
    expect($array)->toHaveKey('applicant_type');
});
