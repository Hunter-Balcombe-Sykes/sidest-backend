# Brand Catalog v2

> **Audience:** Backend, frontend (brand dashboard), and Hydrogen storefront developers.
> **Status:** Active. This is the canonical model for brand → affiliate product control.
> **See also:** [api.md](./api.md) for the full endpoint reference.

---

## 1. Overview

In v2, **all brand-controlled product configuration lives in Shopify product metafields** under the `sidest.*` namespace. Side St does not store a local mirror of the brand's catalog. Every read goes to Shopify; every write updates a Shopify metafield.

Why this design:
- Shopify is already the source of truth for products, variants, prices, and inventory.
- Brand-controlled overrides (commission, discount, photo permissions, variant restrictions) are co-located with the product they affect.
- Zero schema drift: adding a new per-product control = one new metafield, no migration.
- Affiliates inherit everything from the brand — there is no per-affiliate variant or pricing override at this layer (deferred; see [§7](#7-deferred--out-of-scope)).

---

## 2. Conceptual Model

### 2.1 Three actors

| Actor | What they do | Where their data lives |
|-------|-------------|------------------------|
| **Brand** | Connects Shopify, configures per-product settings via the brand catalog UI | Shopify product metafields (`sidest.*`) |
| **Affiliate** | Picks a subset of the brand's products to feature on their mini-site | `commerce.affiliate_product_selections` (Postgres) — stores only product GIDs + sort order |
| **Storefront (Hydrogen)** | Renders products + handles checkout for affiliates | Reads from the internal Hydrogen API; falls back to Shopify Storefront API for product detail |

### 2.2 The "missing metafield = default" pattern

Every `sidest.*` metafield is **optional**. When the metafield is missing, the system applies a sensible default. When the metafield is present, it overrides that default.

This matters because:
- Brands don't have to configure every product — only the ones that need an override.
- Defaults are dynamic. Example: with no `enabled_variant_gids` set, *all* of the product's current Shopify variants are offered — including any new variants the brand adds later. Restrictions are only "frozen" once the brand opts in.
- Clearing a setting (sending `null` or `[]`) **deletes the metafield** rather than storing an empty value. This keeps Shopify clean and preserves the dynamic-default behaviour.

### 2.3 What lives in Postgres vs Shopify

| Data | Store | Why |
|------|-------|-----|
| Brand-controlled product config (active flag, commission, discount, custom photos, variant restrictions) | Shopify metafields | Co-located with the product; no schema drift |
| Affiliate's list of selected products (GIDs + sort order) | `commerce.affiliate_product_selections` | Affiliate-specific, not a brand property |
| Affiliate's per-affiliate brand override (e.g. `BrandPartnerLink.custom_photos_enabled`) | `core.brand_partner_links` | Affiliate-specific override on a brand-level toggle |
| Brand store-level settings (default commission rate, design tokens, custom photo position) | `retail.brand_store_settings` + `site.settings` + Shopify shop metafields | Mix of local DB (for fast reads) and Shopify (for the storefront) |

---

## 3. The `sidest.*` Metafield Reference

All metafields are scoped to a single Shopify product unless noted otherwise. The namespace is `sidest`.

| Key | Type | Default (when missing) | Meaning | Write semantics |
|-----|------|------------------------|---------|------------------|
| `active` | `boolean` | `false` | Whether the product is offered to affiliates at all. False = hidden from the affiliate catalog entirely. | `null` → unset → default. `true`/`false` → explicit value. |
| `commission_override` | `number_decimal` | Falls through to `BrandStoreSettings.default_commission_rate` (or 15%) | Per-product commission rate (% of order value paid to affiliate). | `null` → delete metafield → revert to brand default. Numeric → set. |
| `affiliate_discount_pct` | `number_decimal` | No discount | Per-product discount the affiliate's customers receive. Enforced at checkout by the `sidest-affiliate-discount` Shopify Function and surfaced in Hydrogen as the product's *only* displayed price. Access: `PUBLIC_READ`. | `null` → delete. Numeric (0-100) → set. |
| `custom_photos_enabled` | `boolean` | Falls through to brand-level `provider_metadata.custom_photos_enabled` (default `true`) | Whether affiliates can upload their own lifestyle photos for this product. | `null` → delete → fall through. `true`/`false` → explicit override. |
| `enabled_variant_gids` | `json` (array of variant GIDs) | All variants enabled (dynamic) | **Deprecated** — kept only for legacy reads while stores migrate. Superseded by per-variant `sidest.enabled` (see §3.2). | `null` or `[]` → delete → all variants enabled. Non-empty array → only these variants are offered. |
| `has_enabled_variants` | `boolean` | `null` (unwritten) | **Derived.** True when the product has at least one variant with `sidest.enabled != false` (or no variants at all). Written automatically by `BrandCatalogService::setVariantEnabledStates` whenever variant states change; backfilled by `sidest:backfill-has-enabled-variants` for pre-existing stores. Used as a smart-collection condition so products with every variant disabled automatically drop out of the Active Products collection without the brand having to flip `sidest.active`. | Never set by the brand directly; always computed server-side. |

### 3.2 Per-variant metafields (`ownerType: PRODUCTVARIANT`)

| Key | Type | Default (missing) | Meaning |
|-----|------|--------------------|---------|
| `enabled` | `boolean` | `true` | Whether this specific variant is offered to affiliates. Only `false` hides the variant — missing or `true` keeps it available. `PUBLIC_READ` so Hydrogen reads directly via the Storefront API. |

### 3.3 "Active Products" smart collection condition

The Active Products smart collection rules are ANDed (`appliedDisjunctively: false`):

1. `sidest.active = true`
2. `sidest.has_enabled_variants = true`

A brand can't accidentally ship a product where every variant has been disabled — the collection condition catches it automatically.

### 3.1 Permission resolution: 3-level hierarchy (custom photos)

`custom_photos_enabled` is the only metafield with a 3-level fallback:

1. **Per-affiliate override** — `core.brand_partner_links.custom_photos_enabled` (highest priority)
2. **Per-product metafield** — `sidest.custom_photos_enabled` on the product
3. **Brand-level toggle** — `provider_metadata.custom_photos_enabled` on the brand's Shopify integration record

The first non-null value wins. Implemented in [`CustomPhotoPermissionService`](../app/Services/Store/CustomPhotoPermissionService.php).

---

## 4. Endpoints

### 4.1 Brand-facing (`/api/brand/catalog`)

All routes require Supabase JWT auth + a brand-type professional account.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/brand/catalog` | Full catalog with all `sidest.*` metafields merged in. Used by the brand catalog UI. |
| `GET` | `/api/brand/catalog/all` | Same shape but includes draft/archived Shopify products. |
| `PATCH` | `/api/brand/catalog/{productGid}/metafields` | **Bulk update**: set/clear any combination of `active`, `commission_override`, `affiliate_discount_pct`, `custom_photos_enabled`, `enabled_variant_gids` in one request. |
| `PATCH` | `/api/brand/catalog/{productGid}/active` | Shortcut for toggling `active` only. |
| `PATCH` | `/api/brand/catalog/{productGid}/commission` | Shortcut for `commission_override` only. |
| `PATCH` | `/api/brand/catalog/{productGid}/discount` | Shortcut for `affiliate_discount_pct` only. |

**Use the bulk endpoint** for any UI that lets the brand edit multiple settings on the same product in one save action — it batches into a single Shopify GraphQL `metafieldsSet` call.

#### Bulk PATCH request body

All fields are optional. Send only the fields you're changing. Use `null` (or `[]` for the array) to clear an override.

```json
{
  "active": true,
  "commission_override": 25.0,
  "affiliate_discount_pct": 10.0,
  "custom_photos_enabled": true,
  "enabled_variant_gids": [
    "gid://shopify/ProductVariant/123",
    "gid://shopify/ProductVariant/456"
  ]
}
```

#### Bulk PATCH response

```json
{ "updated": true }
```

Validation errors return 422 with a single human-readable message.

#### Catalog GET response (per product)

```json
{
  "gid": "gid://shopify/Product/111",
  "title": "Hydrating Hair Gel",
  "handle": "hydrating-hair-gel",
  "status": "ACTIVE",
  "featured_image": { "url": "...", "altText": null },
  "price_range": {
    "min": { "amount": "10.00", "currencyCode": "AUD" },
    "max": { "amount": "20.00", "currencyCode": "AUD" }
  },
  "variants": [
    { "gid": "gid://shopify/ProductVariant/123", "title": "200ml", "available_for_sale": true, "price": { "amount": "10.00", "currencyCode": "AUD" } },
    { "gid": "gid://shopify/ProductVariant/456", "title": "500ml", "available_for_sale": true, "price": { "amount": "20.00", "currencyCode": "AUD" } }
  ],
  "metafields": {
    "active": true,
    "commission_override": 25.0,
    "affiliate_discount_pct": null,
    "custom_photos_enabled": null,
    "enabled_variant_gids": ["gid://shopify/ProductVariant/123"]
  }
}
```

The brand UI uses `variants[]` (the full set from Shopify) **and** `metafields.enabled_variant_gids` (the current restriction) to render the variant selector — typically all variants shown, with the ones in `enabled_variant_gids` ticked.

> **Note:** the catalog query fetches `variants(first: 5)` from Shopify. Products with more than 5 variants will only surface the first 5 in the brand UI. This is a pre-existing limit shared by the affiliate catalog. Bump if hair-care brands ship products with 6+ size/scent combos.

### 4.2 Affiliate-facing (`/api/affiliate/products`)

The affiliate catalog merges three sources:
1. The brand's **active** product collection from the Shopify Storefront API (full variant list + base prices).
2. The `sidest.*` metafields from the Admin API (commission, discount, **enabled variant restrictions**).
3. The affiliate's own selection state from `commerce.affiliate_product_selections`.

**Server-side filtering** happens in [`AffiliateProductCatalogService::getCatalogWithSelections`](../app/Services/Store/AffiliateProductCatalogService.php): if `enabled_variant_gids` is set on a product, the `variants[]` array is filtered down to only those GIDs **before** it reaches the affiliate. Affiliates never see disabled variants — there is no client-side filtering.

### 4.3 Hydrogen internal endpoint (`/api/internal/hydrogen/affiliate-products`)

Server-to-server endpoint consumed by the Hydrogen storefront. Auth via `hydrogen.key` middleware (shared secret).

**Query params:** `affiliate_id` (uuid, required)

**Response shape:**

```json
{
  "gids": ["gid://shopify/Product/111", "gid://shopify/Product/222"],
  "source": "affiliate_selections",
  "custom_photo_position": "after",
  "custom_photos": {
    "gid://shopify/Product/111": [
      { "url": "...", "alt_text": "..." }
    ]
  },
  "enabled_variants": {
    "gid://shopify/Product/111": [
      "gid://shopify/ProductVariant/123"
    ]
  }
}
```

**`enabled_variants` contract:**
- A product GID **only** appears in the map when it has a non-empty `enabled_variant_gids` metafield (i.e. an active restriction).
- When a product GID is **absent** from the map, the storefront should offer all variants Shopify returns — there is no restriction.
- When present, the storefront **must** filter the variant picker and reject add-to-cart attempts for any variant GID not in the list.

Hydrogen's responsibility is enforcement at the UI layer + at the cart layer. The backend already filters the affiliate-facing catalog, but Hydrogen fetches product detail directly from Shopify and so needs the explicit list.

---

## 5. Variant Gating: Behaviour by Scenario

This is the most subtle metafield. Scenarios:

| # | Brand action | Stored value | Affiliate sees | Hydrogen sees | Auto-tracks new Shopify variants? |
|---|--------------|-------------|----------------|---------------|------------------------------------|
| 1 | Does nothing | Metafield missing | All variants | `enabled_variants` key omitted → all variants | ✅ Yes |
| 2 | Ticks all variants then saves | Frontend should send `[]` → metafield deleted | All variants | Key omitted → all variants | ✅ Yes |
| 3 | Picks a subset (e.g. 2 of 3) | `["gid_1", "gid_2"]` | Only those 2 | Map includes those 2 GIDs | ❌ New variants are excluded until brand explicitly opts them in |
| 4 | Picks one ("standalone product" mode) | `["gid_1"]` | Only that 1 — UI should hide the variant picker | Map includes that 1 GID | ❌ |
| 5 | Submits an invalid GID (UI bug, manual API call) | — | — | — | Backend rejects 422; nothing written |
| 6 | Restricts a product, then adds a NEW variant in Shopify | Saved list unchanged | New variant **not** visible | New variant **not** visible | Brand UI will show the new variant unticked on next load — drop control is intentional |
| 7 | Restricts a product, then **deletes** an enabled variant in Shopify | Stale GID in saved list | Variant disappears from `variants[]` (intersection-style filter is implicit because Shopify just stops returning it) | Map still contains the stale GID, but the product detail won't return it | Stale GID is harmless until next save, when validation will reject it |
| 8 | Clears the restriction ("reset to all") | Frontend sends `null` or `[]` → metafield deleted | All variants | Key omitted | ✅ Back to dynamic default |
| 9 | Two affiliates linked to the same brand | — | Both see the **same** filtered set | Both get the same map | — |

### 5.1 The dynamic-default rule

> **No restriction → Shopify is the source of truth.**
> **Restriction → the saved list is the source of truth.**

This is the single most important property of the variant gating model. It means brands get sensible auto-tracking by default, but can opt into strict drop control when they need it (limited editions, regional restrictions, out-of-stock flavours).

### 5.2 Strict write-path validation

When a brand submits `enabled_variant_gids`, the backend:
1. Fetches the product's actual current variants from Shopify (separate GraphQL call).
2. Verifies every submitted GID is in that list.
3. Rejects 422 if any GID is invalid (typo, deleted variant, wrong product).
4. Rejects 422 if the product has zero variants ("nothing to restrict").
5. Only on full success: writes the `sidest.enabled_variant_gids` metafield as a JSON array.

This prevents stale or fabricated GIDs from polluting the metafield.

---

## 6. Frontend Behaviour Expectations

### 6.1 Brand Dashboard (catalog UI)

- **Variant selector affordance:** for products with multiple variants, render a dropdown/expand button. Show all variants with checkboxes, **all preselected by default** (matching the dynamic-default state). The current `enabled_variant_gids` (if any) determines which are checked.
- **"Pick one" affordance:** offer a separate "promote a single variant" flow that's just syntactic sugar for selecting one checkbox and clearing the rest. Both flows POST to the same field.
- **Clearing the restriction:** if the brand checks all variants OR explicitly clicks "reset", send `enabled_variant_gids: null` (or `[]`) to delete the metafield. Don't send the full list — that would freeze it and break dynamic auto-tracking when new variants are added.
- **Bulk save:** if the same UI lets the brand edit commission, discount, etc., use the bulk PATCH endpoint to update everything in one call.
- **Optimistic UI:** the response is `{ "updated": true }`, not the new product state. Reconcile from your local state or refetch the catalog.

### 6.2 Hydrogen storefront

- Read `enabled_variants` from the Hydrogen affiliate-products endpoint.
- For each product, when fetching detail from Shopify Storefront API, filter the variant array client-side using the map.
- If a product GID isn't in the map → show all variants.
- Cart enforcement: reject add-to-cart for any variant GID not in the allowed list. This is the last line of defence — backend validates on selection writes, but the cart is the final boundary.

### 6.3 Affiliate dashboard

- The affiliate catalog endpoint (`GET /api/affiliate/products`) already returns pre-filtered `variants[]`. **Do not filter again on the frontend.** If you don't see a variant, the brand has restricted it.

---

## 7. Deferred / Out of Scope

Decided against (for now):

- **Featured/default variant separate from enabled.** Picking one variant *is* the standalone mode. There is no separate "this is the default but others are also available" concept.
- **Variant-level commission/discount overrides.** Commission and discount remain product-level only.
- **Reconciliation jobs for stale GIDs.** Stale GIDs (e.g. a brand-saved variant that gets deleted from Shopify) are tolerated until the next save. No background job cleans them up.

### 7.2 Side St Price enforcement (shipped)

`sidest.affiliate_discount_pct` is no longer just a UI number — it's enforced end-to-end:

- **Checkout** — the `sidest-affiliate-discount` Shopify Function (see `Sidest-Embedded/extensions/sidest-affiliate-discount/`) reads each cart line's parent product metafield and applies a matching percentage product discount. The function gates on the cart attribute `_sidest_affiliate_id`; brand-direct customers pay the Shopify sticker price.
- **Hydrogen display** — the products engine fetches the metafield via Storefront API (PUBLIC_READ) and exposes the *post-discount* price on `StorefrontProduct.priceRange`. Affiliate sitepages render a **single clean price** — no strike-through, no "was $X now $Y". The Shopify sticker price is invisible to the customer.
- **Cart attribution** — Hydrogen's `$affiliateSlug.tsx` action passes `affiliate.id` as the `_sidest_affiliate_id` cart attribute on `cartCreate`. **First-touch** semantics: if a visitor browses two affiliates in one session, the cart retains whichever affiliate claimed it first. Prevents last-click hijacking.
- **Auto-install** — on Shopify OAuth, `CreateShopifyAffiliateDiscountJob` runs after the collections job and calls `discountAutomaticAppCreate` to activate the function as a store-wide automatic app discount. Idempotent. State tracked on `provider_metadata.sidest_discount_state` (`registered` | `pending` | `failed`). Backfill existing brands with `php artisan sidest:install-affiliate-discount [--brand=<uuid>]`.
- **Commission accounting** — `ProcessShopifyOrderWebhookJob` now computes commission on **post-discount line totals** (`line.price × quantity − line.total_discount`). `calculation_metadata` preserves both pre- and post-discount figures for audit.

Metafield access flip rollout: the existing definition with `access: []` is delete+recreated with `PUBLIC_READ` by `CreateShopifyMetafieldsJob` on its next run per store. Underlying values survive (`deleteAllAssociatedMetafields: false`).

### 7.1 Per-affiliate variant curation (shipped)

Previously deferred, now implemented. Affiliates can narrow a product selection to a subset of the brand's currently-enabled variants:

- Column `commerce.affiliate_product_selections.selected_variant_gids jsonb` — NULL = show every brand-enabled variant (default), populated = the affiliate has narrowed.
- `POST /api/affiliate/selections` and `PATCH /api/affiliate/selections/{productGid}/variants` validate picks against the brand's currently-enabled variant set (intersection of Shopify variants ∩ `sidest.enabled != false`).
- Brand disables always win: the read path in `AffiliateProductCatalogService::getCatalogWithSelections` intersects brand-enabled variants with the affiliate's explicit subset, so a brand-side disable removes a variant from the affiliate storefront even if the affiliate had picked it.
- Stale selections (product archived, every chosen variant disabled) are filtered from the affiliate's read view and surfaced through `GET /api/affiliate/selections/stale` for UI cleanup prompts.

---

## 8. Implementation Pointers

| Concern | File |
|---------|------|
| Read path (catalog + variants + metafields) | [`BrandCatalogService::queryAdminCatalog`](../app/Services/Store/BrandCatalogService.php) |
| Write path (set/delete metafields) | [`BrandCatalogService::setProductMetafields`](../app/Services/Store/BrandCatalogService.php) + [`deleteProductMetafield`](../app/Services/Store/BrandCatalogService.php) |
| Strict variant validation | [`BrandCatalogService::fetchProductVariantGids`](../app/Services/Store/BrandCatalogService.php) |
| Hydrogen variant map | [`BrandCatalogService::fetchEnabledVariantsMap`](../app/Services/Store/BrandCatalogService.php) |
| Brand controller | [`BrandCatalogController::updateMetafields`](../app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php) |
| Affiliate-side variant filter | [`AffiliateProductCatalogService::getCatalogWithSelections`](../app/Services/Store/AffiliateProductCatalogService.php) |
| Hydrogen controller | [`HydrogenAffiliateProductsController`](../app/Http/Controllers/Api/Internal/HydrogenAffiliateProductsController.php) |
| Form validation | [`UpdateProductMetafieldsRequest`](../app/Http/Requests/Api/Professional/Store/UpdateProductMetafieldsRequest.php) |
| Custom photo permission resolution | [`CustomPhotoPermissionService`](../app/Services/Store/CustomPhotoPermissionService.php) |
| Brand resource shape | [`BrandCatalogProductResource`](../app/Http/Resources/BrandCatalogProductResource.php) |
| Affiliate resource shape | [`AffiliateProductResource`](../app/Http/Resources/AffiliateProductResource.php) |
| Test coverage | [`tests/Feature/Store/BrandCatalogControllerTest.php`](../tests/Feature/Store/BrandCatalogControllerTest.php), [`tests/Feature/Store/AffiliateProductPhotoTest.php`](../tests/Feature/Store/AffiliateProductPhotoTest.php) |

---

## 9. Performance Notes

- **No catalog caching** on the affiliate path. Every catalog fetch hits the Shopify Storefront API + the Admin API for metafields. Brand changes propagate immediately.
- **Hydrogen `enabled_variants` map** triggers a Shopify Admin API call per Hydrogen catalog fetch (~200ms). If this becomes a hotspot, add a short Redis TTL keyed by `(brand_id, product_gids hash)` and bust on metafield write.
- **Write path** also triggers a separate Shopify call to fetch current variants for validation. Acceptable cost for a per-write operation; not on the hot read path.

If you need to optimise: cache invalidation already lives in [`BrandCatalogService::bustCatalogCaches`](../app/Services/Store/BrandCatalogService.php) — extend it to bust the new variant map cache when adding one.
