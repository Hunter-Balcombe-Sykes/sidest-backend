@extends('mail.layouts.partna')

@section('preheader', 'You\'ve been invited to join Partna.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        You're invited to Partna
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        Hi,
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        You've been invited to join Partna at {{ $recipientEmail }}. Tap the button below to accept the invite and finish setting up your account.
    </p>

    <x-mail.button :href="$verifyUrl">Accept invite</x-mail.button>

    <p class="body-text text-secondary" style="margin: 32px 0 8px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        This invite expires in 7 days. If you weren't expecting it, you can safely ignore this email.
    </p>

    <p class="body-text text-secondary" style="margin: 0; font-size: 13px; line-height: 1.5; color: #86868b; word-break: break-all;">
        Button not working? Paste this URL into your browser:<br>
        <a href="{{ $verifyUrl }}" style="color: #3a6efc; text-decoration: none;">{{ $verifyUrl }}</a>
    </p>
@endsection

@section('footer_note', 'You\'re receiving this because someone invited you to Partna.')
