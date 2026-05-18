{{-- Generic invites-category notification email. Renders Notification
     model data (title / body / cta_url / primary_action_label) inside the
     universal Partna layout. Used for "Invite accepted" and "Invite
     declined" notifications sent to brands. --}}
@extends('mail.layouts.partna')

@section('preheader', $notification->title)

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        {{ $notification->title }}
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        {{ $notification->body }}
    </p>

    @if ($notification->cta_url)
        <x-mail.button :href="$notification->cta_url">
            {{ $notification->primary_action_label ?? 'View' }}
        </x-mail.button>
    @endif
@endsection
