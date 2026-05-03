<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>GDPR customer data request</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #111; max-width: 640px; margin: 0 auto; padding: 24px;">
    <h2 style="margin-top: 0;">GDPR customer data request</h2>

    <p>Hi,</p>

    <p>A customer at your store <strong>{{ $shopDomain }}</strong> has invoked their GDPR right to access the personal data you hold about them (via Shopify's <code>customers/data_request</code> webhook).</p>

    <p>Attached is a JSON file containing every record Side St holds about <strong>{{ $customerEmail }}</strong> scoped to your store ({{ $recordCount }} records total).</p>

    <p><strong>What to do next:</strong> forward this file to the requesting customer within 30 days of their Shopify request. Shopify tracks compliance on the merchant side.</p>

    <p>If you believe this request was sent in error, or if you want help interpreting the contents, reply to this email and we'll assist.</p>

    <p>— Side St</p>

    <hr style="border: none; border-top: 1px solid #eee; margin: 32px 0 16px;">

    <p style="font-size: 12px; color: #666;">This is an automated message triggered by Shopify. You are receiving it because you are the registered owner of <strong>{{ $shopDomain }}</strong>.</p>
</body>
</html>
