@extends('mail.layouts.partna')

@section('preheader', 'Your one-time Partna sign-in link.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        Sign in to Partna
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        Tap the button below to sign in to your Partna account ({{ $recipientEmail }}). You'll be taken straight to your dashboard — no password needed.
    </p>

    <x-mail.button :href="$verifyUrl">Sign in to Partna</x-mail.button>

    <p class="body-text text-secondary" style="margin: 32px 0 8px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        This link expires in 1 hour and can only be used once. If you didn't ask to sign in, you can safely ignore this email.
    </p>

    <p class="body-text text-secondary" style="margin: 0; font-size: 13px; line-height: 1.5; color: #86868b; word-break: break-all;">
        Button not working? Paste this URL into your browser:<br>
        <a href="{{ $verifyUrl }}" style="color: #3a6efc; text-decoration: none;">{{ $verifyUrl }}</a>
    </p>
@endsection

@section('footer_note', 'This is a transactional email related to your account access.')
