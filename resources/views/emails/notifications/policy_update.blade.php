<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $notification->title }}</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; }
        h2 { margin-top: 0; font-size: 20px; color: #111; }
        p { color: #444; line-height: 1.6; }
        .btn { display: inline-block; margin-top: 16px; padding: 12px 24px; background: #111; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>{{ $notification->title }}</h2>
        <p>{{ $notification->body }}</p>
        @if ($notification->cta_url)
            <a href="{{ $notification->cta_url }}" class="btn">
                {{ $notification->primary_action_label ?? 'Review' }}
            </a>
        @endif
    </div>
</body>
</html>
