<?php

namespace App\Services\Store;

use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\CheckoutSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class ShopifyOrderCreationService
{
    use NormalizesShopDomain;

    private const DRAFT_ORDER_CREATE_MUTATION = <<<'GRAPHQL'
mutation DraftOrderCreate($input: DraftOrderInput!) {
  draftOrderCreate(input: $input) {
    draftOrder {
      id
      name
      invoiceUrl
      totalPrice
      currencyCode
      lineItems(first: 50) {
        edges {
          node {
            id
            title
            quantity
            originalTotal
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    private const DRAFT_ORDER_COMPLETE_MUTATION = <<<'GRAPHQL'
mutation DraftOrderComplete($id: ID!) {
  draftOrderComplete(id: $id) {
    draftOrder {
      id
      name
      order {
        id
        name
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    public function createPaidOrderFromCheckoutSession(
        CheckoutSession $checkoutSession,
        Professional $brand,
        array $contextSnapshot,
        array $paymentMetadata = [],
    ): array {
        $integration = $this->resolveShopifyIntegration($brand);
        $lineItems = $this->buildDraftOrderLineItems($contextSnapshot);
        if ($lineItems === []) {
            throw new \RuntimeException('Stripe checkout session is missing Shopify line items.');
        }

        $draftInput = [
            'lineItems' => $lineItems,
            'email' => $this->resolveEmail($contextSnapshot),
            'note' => $this->buildOrderNote($checkoutSession, $paymentMetadata),
        ];

        $shippingAddress = $this->buildShippingAddress($contextSnapshot);
        if ($shippingAddress !== null) {
            $draftInput['shippingAddress'] = $shippingAddress;
        }

        $createResult = $this->queryShopify(
            $integration,
            self::DRAFT_ORDER_CREATE_MUTATION,
            ['input' => $draftInput]
        );

        $createErrors = Arr::get($createResult, 'draftOrderCreate.userErrors', []);
        if (is_array($createErrors) && $createErrors !== []) {
            throw new \RuntimeException('Draft order creation failed: '.$this->joinUserErrors($createErrors));
        }

        $draftOrderId = trim((string) Arr::get($createResult, 'draftOrderCreate.draftOrder.id', ''));
        if ($draftOrderId === '') {
            throw new \RuntimeException('Draft order creation returned no draft order id.');
        }

        $completeResult = $this->queryShopify(
            $integration,
            self::DRAFT_ORDER_COMPLETE_MUTATION,
            ['id' => $draftOrderId]
        );

        $completeErrors = Arr::get($completeResult, 'draftOrderComplete.userErrors', []);
        if (is_array($completeErrors) && $completeErrors !== []) {
            throw new \RuntimeException('Draft order completion failed: '.$this->joinUserErrors($completeErrors));
        }

        $orderId = trim((string) Arr::get($completeResult, 'draftOrderComplete.draftOrder.order.id', ''));
        if ($orderId === '') {
            throw new \RuntimeException('Draft order completion returned no Shopify order id.');
        }

        return [
            'draft_order_id' => $draftOrderId,
            'order_id' => $orderId,
            'order_name' => trim((string) Arr::get($completeResult, 'draftOrderComplete.draftOrder.order.name', '')),
            'shop_domain' => $this->resolveShopDomain($integration),
        ];
    }

    private function resolveShopifyIntegration(Professional $brand): ProfessionalIntegration
    {
        $integration = ProfessionalIntegration::query()
            ->provider(ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('professional_id', $brand->id)
            ->first();

        if (! $integration) {
            throw new \RuntimeException('Brand does not have a connected Shopify store.');
        }

        $shopDomain = $this->resolveShopDomain($integration);
        $accessToken = trim((string) ($integration->access_token ?? ''));

        if ($shopDomain === '' || $accessToken === '') {
            throw new \RuntimeException('Shopify integration is missing shop domain or access token.');
        }

        return $integration;
    }

    private function resolveShopDomain(ProfessionalIntegration $integration): string
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        return $this->normalizeShopDomain((string) Arr::get($metadata, 'shop_domain', ''));
    }

    private function resolveApiVersion(): string
    {
        return trim((string) config('services.shopify.version', '2025-01'));
    }

    private function queryShopify(ProfessionalIntegration $integration, string $query, array $variables = []): array
    {
        $shopDomain = $this->resolveShopDomain($integration);
        $endpoint = "https://{$shopDomain}/admin/api/{$this->resolveApiVersion()}/graphql.json";
        $accessToken = trim((string) ($integration->access_token ?? ''));

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($endpoint, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException("Shopify order sync failed (HTTP {$response->status()}).");
        }

        $payload = $response->json() ?? [];
        $errors = Arr::get($payload, 'errors', []);
        if (is_array($errors) && $errors !== []) {
            $message = (string) Arr::get($errors, '0.message', 'Shopify GraphQL returned errors.');
            throw new \RuntimeException($message);
        }

        $data = Arr::get($payload, 'data', []);
        return is_array($data) ? $data : [];
    }

    private function buildDraftOrderLineItems(array $contextSnapshot): array
    {
        $lineItems = [];
        $snapshotLineItems = Arr::get($contextSnapshot, 'line_items', []);
        if (! is_array($snapshotLineItems)) {
            return [];
        }

        foreach ($snapshotLineItems as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $variantId = trim((string) ($lineItem['shopify_variant_id'] ?? ''));
            $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
            if ($variantId === '') {
                continue;
            }

            $lineItems[] = [
                'variantId' => $variantId,
                'quantity' => $quantity,
            ];
        }

        return $lineItems;
    }

    private function resolveEmail(array $contextSnapshot): string
    {
        $email = trim((string) Arr::get($contextSnapshot, 'customer.email', ''));
        if ($email === '') {
            throw new \RuntimeException('Stripe checkout session is missing customer email.');
        }

        return strtolower($email);
    }

    private function buildShippingAddress(array $contextSnapshot): ?array
    {
        $customer = Arr::get($contextSnapshot, 'customer', []);
        if (! is_array($customer)) {
            return null;
        }

        $address1 = trim((string) ($customer['address1'] ?? ''));
        $city = trim((string) ($customer['city'] ?? ''));
        $country = trim((string) ($customer['country'] ?? ''));
        $zip = trim((string) ($customer['zip'] ?? ''));
        if ($address1 === '' || $city === '' || $country === '' || $zip === '') {
            return null;
        }

        $fullName = trim((string) ($customer['name'] ?? ''));
        $parts = preg_split('/\s+/', $fullName) ?: [];
        $firstName = trim((string) ($parts[0] ?? 'Customer'));
        $lastName = trim((string) implode(' ', array_slice($parts, 1)));

        return [
            'firstName' => $firstName !== '' ? $firstName : 'Customer',
            'lastName' => $lastName,
            'address1' => $address1,
            'address2' => trim((string) ($customer['address2'] ?? '')) ?: null,
            'company' => trim((string) ($customer['company'] ?? '')) ?: null,
            'city' => $city,
            'province' => trim((string) ($customer['province'] ?? '')),
            'country' => $country,
            'zip' => $zip,
            'phone' => trim((string) ($customer['phone'] ?? '')) ?: null,
        ];
    }

    private function buildOrderNote(CheckoutSession $checkoutSession, array $paymentMetadata): string
    {
        $parts = [
            'comet_session:'.$checkoutSession->token,
            'comet_payment_mode:stripe_direct',
        ];

        $stripeCheckoutSessionId = trim((string) ($paymentMetadata['stripe_checkout_session_id'] ?? ''));
        if ($stripeCheckoutSessionId !== '') {
            $parts[] = 'stripe_checkout_session:'.$stripeCheckoutSessionId;
        }

        $stripePaymentIntentId = trim((string) ($paymentMetadata['stripe_payment_intent_id'] ?? ''));
        if ($stripePaymentIntentId !== '') {
            $parts[] = 'stripe_payment_intent:'.$stripePaymentIntentId;
        }

        return implode(' | ', $parts);
    }

    private function joinUserErrors(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }
            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages !== [] ? implode('; ', $messages) : 'Unknown Shopify error.';
    }
}
