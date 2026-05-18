@extends('mail.layouts.partna')

@section('preheader', "{$brandName} wants you on their team. Accept to start sharing their products.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        You're invited to Partna
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        {{ $recipientFirstName ? "Hi {$recipientFirstName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        <strong style="font-weight: 600;">{{ $brandName }}</strong> invited you to be one of their Partna affiliates. Accept the invite and you'll be linked to {{ $brandName }} on Partna — ready to share their products with your audience and earn on every sale.
    </p>

    <x-mail.button :href="$acceptUrl">Accept invite</x-mail.button>

    <p class="body-text text-secondary" style="margin: 32px 0 8px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        @if ($expiresInDays !== null)
            This invite expires in {{ $expiresInDays }} day{{ $expiresInDays === 1 ? '' : 's' }}.
        @endif
        If you weren't expecting this email, you can safely ignore it.
    </p>

    <p class="body-text text-secondary" style="margin: 0; font-size: 13px; line-height: 1.5; color: #86868b; word-break: break-all;">
        Button not working? Paste this URL into your browser:<br>
        <a href="{{ $acceptUrl }}" style="color: #3a6efc; text-decoration: none;">{{ $acceptUrl }}</a>
    </p>
@endsection

@section('footer_note', "You're receiving this because {$brandName} invited {$recipientEmail} to Partna.")
