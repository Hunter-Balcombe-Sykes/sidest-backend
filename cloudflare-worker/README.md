# Partna Subdomain Router (Cloudflare Worker)

Routes every `*.partna.au` request:

- **Brand subdomain** → pass through to origin (Shopify Hydrogen storefront)
- **Affiliate subdomain** → `301` redirect to `brand.partna.au/{handle}`
- **Reserved / unknown** → 404 or pass-through

The routing table lives in Cloudflare Workers KV. The Laravel backend keeps it in sync via `SyncSubdomainToKvJob` (dispatched by observers on handle / brand-link / site / domain changes).

---

## One-time setup

### 1. Install Wrangler

```bash
npm install
npx wrangler login
```

### 2. Create the KV namespace

```bash
npx wrangler kv:namespace create SUBDOMAIN_KV
npx wrangler kv:namespace create SUBDOMAIN_KV --preview
```

Each command prints a namespace `id`. Paste them into `wrangler.toml`:

```toml
[[kv_namespaces]]
binding = "SUBDOMAIN_KV"
id = "<production id from first command>"
preview_id = "<preview id from second command>"
```

### 3. Get account & namespace IDs for Laravel

In the Cloudflare dashboard → right sidebar → **Account ID** (copy this).

The KV namespace ID is the production `id` from step 2.

Set on Laravel Cloud (and locally in `.env`):

```
CLOUDFLARE_ACCOUNT_ID=<account id>
CLOUDFLARE_KV_NAMESPACE_ID=<production namespace id>
CLOUDFLARE_API_TOKEN=<token with Workers KV: Edit + DNS: Edit perms>
```

### 4. Add a wildcard DNS record

Cloudflare dashboard → DNS → Records → Add record:

- **Type:** `A`
- **Name:** `*`
- **IPv4 address:** `192.0.2.1` (RFC 5737 documentation address — the Worker intercepts before reaching it)
- **Proxy status:** Proxied (orange cloud) — **required** for the Worker to run

Specific brand CNAMEs (created by `EmbeddedSetupController` for Shopify Hydrogen) take precedence over the wildcard, so brand storefronts continue to resolve to `shops.myshopify.com`.

### 5. Deploy the Worker

```bash
npm run deploy
```

The Worker is now live at `*.partna.au/*` (route configured in `wrangler.toml`).

### 6. Smoke test

After Laravel pushes some KV entries (create a professional, connect them to a brand), test:

```bash
# Affiliate redirect
curl -I https://jane.partna.au
# → HTTP/2 301
# → location: https://evostudio.partna.au/jane

# Brand pass-through
curl -I https://evostudio.partna.au
# → HTTP/2 200 (Shopify storefront)

# Unknown subdomain
curl -I https://does-not-exist.partna.au
# → HTTP/2 404
```

You can also seed KV manually for testing:

```bash
npx wrangler kv:key put --binding SUBDOMAIN_KV "jane" '{"type":"affiliate","redirect":"https://evostudio.partna.au/jane"}'
```

---

## How it stays in sync

Laravel's `SyncSubdomainToKvJob` writes one KV entry per professional. It's dispatched by:

- `ProfessionalObserver` — when `handle` changes (the KV key itself changes)
- `BrandPartnerLinkObserver` — when an affiliate joins or leaves a brand
- `SiteObserver` — when a site is created or its subdomain changes (cascades to all linked affiliates if the site belongs to a brand)
- `BrandStoreSettingsObserver` — when a brand's custom domain changes (cascades to affiliates)

KV reads at the edge are typically <10 ms and cached locally for ~60 s, so the Worker scales effortlessly to high traffic.

---

## Cost (Cloudflare 2025 pricing)

| Tier | Worker requests / day | KV reads / day | KV writes / day | Storage |
|------|-----------------------|----------------|-----------------|---------|
| Free | 100,000               | 100,000        | 1,000           | 1 GB    |
| Workers Paid ($5/mo) | 10,000,000 | 10,000,000 | 1,000,000 | 1 GB included |

Partna's volume sits comfortably in the free tier until well past launch.

---

## Local development

```bash
npm run dev
```

`wrangler dev` runs the Worker locally with the preview KV namespace, against the live `*.partna.au` zone routing pattern. Use `wrangler kv:key put --preview --binding SUBDOMAIN_KV ...` to seed the preview namespace.
