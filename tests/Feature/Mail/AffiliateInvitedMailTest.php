<?php

use App\Mail\Affiliate\AffiliateInvitedMail;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.frontend_url', 'https://app.partna.au');
});

it('puts the brand name in the subject', function (): void {
    $m = new AffiliateInvitedMail(
        recipientEmail: 'nat@example.com',
        recipientFirstName: 'Nat',
        brandName: 'Side St',
        acceptUrl: 'https://app.partna.au/account/sign-up?invite=tok_abc',
        expiresInDays: 7,
    );
    $m->build();

    expect($m->subject)->toBe('Side St invited you to Partna');
});

it('renders the recipient first name, brand name, expiry, and accept URL in the body', function (): void {
    $m = new AffiliateInvitedMail(
        recipientEmail: 'nat@example.com',
        recipientFirstName: 'Nat',
        brandName: 'Side St',
        acceptUrl: 'https://app.partna.au/account/sign-up?invite=tok_abc',
        expiresInDays: 7,
    );

    $html = $m->render();

    expect($html)->toContain('Hi Nat,')
        ->and($html)->toContain('Side St</strong>')
        ->and($html)->toContain('expires in 7 days')
        ->and($html)->toContain('https://app.partna.au/account/sign-up?invite=tok_abc')
        ->and($html)->toContain('Accept invite');
});

it('falls back to a generic greeting when first name is missing', function (): void {
    $m = new AffiliateInvitedMail(
        recipientEmail: 'anon@example.com',
        recipientFirstName: null,
        brandName: 'Side St',
        acceptUrl: 'https://app.partna.au/account/sign-up?invite=t',
    );

    $html = $m->render();

    expect($html)->toContain('Hi,')
        ->and($html)->not->toContain('Hi ,');
});

it('omits the expiry sentence when no expiry is provided', function (): void {
    $m = new AffiliateInvitedMail(
        recipientEmail: 'a@example.com',
        recipientFirstName: 'A',
        brandName: 'Side St',
        acceptUrl: 'https://app.partna.au/account/sign-up?invite=t',
        expiresInDays: null,
    );

    $html = $m->render();

    expect($html)->not->toContain('expires in');
});

it('singularises "day" when there is exactly one day left', function (): void {
    $m = new AffiliateInvitedMail(
        recipientEmail: 'a@example.com',
        recipientFirstName: null,
        brandName: 'Side St',
        acceptUrl: 'https://app.partna.au/account/sign-up?invite=t',
        expiresInDays: 1,
    );

    $html = $m->render();

    expect($html)->toContain('expires in 1 day.')
        ->and($html)->not->toContain('expires in 1 days');
});

it('extends the universal Partna layout (logo + footer present)', function (): void {
    $m = new AffiliateInvitedMail(
        recipientEmail: 'a@example.com',
        recipientFirstName: null,
        brandName: 'Side St',
        acceptUrl: 'https://app.partna.au/account/sign-up?invite=t',
    );

    $html = $m->render();

    expect($html)->toContain('email-icon.png')
        ->and($html)->toContain('email-wordmark.png')
        ->and($html)->toContain('partna.au');
});
