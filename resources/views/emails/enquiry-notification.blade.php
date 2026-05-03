<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>New enquiry</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">New enquiry from {{ $enquiry->name }}</h2>

    <p style="margin: 0 0 8px;"><strong>Subject:</strong> {{ $enquiry->subject }}</p>
    <p style="margin: 0 0 8px;"><strong>From:</strong> {{ $enquiry->name }} &lt;{{ $enquiry->email }}&gt;</p>

    @if ($enquiry->phone)
        <p style="margin: 0 0 8px;"><strong>Phone:</strong> {{ $enquiry->phone }}</p>
    @endif

    <p style="margin: 0 0 8px;"><strong>Submitted:</strong> {{ $enquiry->created_at->format('j M Y H:i') }} UTC</p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0 0 8px;"><strong>Message:</strong></p>
    <p style="white-space: pre-wrap; margin: 0 0 16px;">{{ $enquiry->message }}</p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0; color: #666; font-size: 12px;">
        <a href="{{ $dashboardUrl }}" style="color: #0066cc;">View in your dashboard</a>
    </p>
</body>
</html>
