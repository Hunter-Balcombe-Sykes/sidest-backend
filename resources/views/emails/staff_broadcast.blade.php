<!doctype html>
<html>
<body>
<h2>{{ $notification->title }}</h2>

<div style="white-space: pre-wrap; line-height: 1.5;">
    {{ $notification->body }}
</div>

@if(!empty($notification->cta_url))
    <p><a href="{{ $notification->cta_url }}">Open link</a></p>
@endif

<hr>
<p style="font-size: 12px; color: #666;">
    Don’t want these emails? <a href="{{ $unsubscribeUrl }}">Unsubscribe</a>
</p>
</body>
</html>
