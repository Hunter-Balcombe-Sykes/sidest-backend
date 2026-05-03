<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm your account deletion</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; }
        h2 { margin-top: 0; font-size: 20px; color: #111; }
        p { color: #444; line-height: 1.6; }
        .btn { display: inline-block; margin-top: 16px; padding: 12px 24px; background: #c0392b; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .warn { color: #888; font-size: 13px; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Confirm your account deletion</h2>
        <p>Hi {{ $displayName }},</p>
        <p>We received a request to delete your Side St account. To confirm, click the button below.</p>
        <a href="{{ $confirmationUrl }}" class="btn">Confirm deletion</a>
        <p class="warn">This link expires in 24 hours. If you did not request this, ignore this email and your account will remain active.</p>
    </div>
</body>
</html>
