/**
 * Partna subdomain router — Cloudflare Worker.
 *
 * Reads a per-subdomain routing entry from Workers KV and either:
 *   - Passes the request through to origin (brand storefronts hosted on Shopify), or
 *   - Returns a 301 redirect to the affiliate's site_url (brand.partna.au/handle).
 *
 * KV format (JSON values keyed by lowercase subdomain handle):
 *   { "type": "brand" }                                  // pass-through to origin
 *   { "type": "affiliate", "redirect": "https://..." }   // 301 redirect
 *
 * Backend (Laravel) keeps the KV in sync via SyncSubdomainToKvJob,
 * dispatched by Eloquent observers on handle / brand-link / site / domain changes.
 *
 * Reserved subdomains (api, www, admin, etc.) are passed through without a KV lookup.
 */

const PARTNA_DOMAIN = "partna.au";

// Mirrors `reserved_subdomains` in config/partna.php — these never go to KV.
const RESERVED = new Set([
  "www",
  "api",
  "admin",
  "app",
  "staff",
  "dashboard",
  "support",
  "help",
  "billing",
  "static",
  "cdn",
  "assets",
  "auth",
  "docs",
  "status",
  "comet",
  "sidest",
  "partna",
]);

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const hostname = url.hostname.toLowerCase();

    // Apex and non-partna.au requests pass through untouched.
    if (
      hostname === PARTNA_DOMAIN ||
      !hostname.endsWith("." + PARTNA_DOMAIN)
    ) {
      return fetch(request);
    }

    const subdomain = hostname.slice(0, -1 * (PARTNA_DOMAIN.length + 1));

    // Multi-level subdomains and reserved labels pass through.
    if (subdomain === "" || subdomain.includes(".") || RESERVED.has(subdomain)) {
      return fetch(request);
    }

    let entry = null;
    try {
      entry = await env.SUBDOMAIN_KV.get(subdomain, { type: "json" });
    } catch (err) {
      // KV transient failure — fail open so brand traffic keeps working.
      console.error("KV lookup failed", { subdomain, err: String(err) });
      return fetch(request);
    }

    if (!entry) {
      return new Response("Not Found", {
        status: 404,
        headers: { "Content-Type": "text/plain", "Cache-Control": "no-store" },
      });
    }

    if (entry.type === "affiliate" && typeof entry.redirect === "string") {
      // Drop incoming path/query — Hydrogen only has $affiliateSlug.tsx (no nested
      // affiliate routes), so preserving paths produces 404s. Redirect cleanly to
      // the affiliate's brand-side page.
      return new Response(null, {
        status: 301,
        headers: {
          Location: entry.redirect,
          // Without this, browsers cache 301s indefinitely. A primary-brand swap
          // would leave stale redirects in client caches until users manually clear.
          "Cache-Control": "max-age=0, must-revalidate",
        },
      });
    }

    // type === "brand" or anything else: pass through to the origin defined by DNS.
    return fetch(request);
  },
};
