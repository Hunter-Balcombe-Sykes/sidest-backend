# Stores Feature Plan

> **Status: Planned / Not yet implemented**
> Discussed 2026-04-18. See also `AI_CONTEXT.md` for platform overview.

Stores (salons, barbershops, spas) as a first-class entity type — sitting between individual professionals and brands in the relationship graph.

```
Professional ──── works at ────► Store
Store        ──── stocks/carries ► Brand
```

---

## Why

- Brands want to affiliate with store *locations*, not just individuals
- Professionals want to show they belong to a store (discoverable via the store)
- Stores can stock brand products and earn a cut of affiliate commissions

---

## Data Model

Follows existing "profile extension" pattern — stores are `core.professionals` with `professional_type` of `barbershop` or `salon`, extended by a new profile table.

### New Tables (supabase/migrations/)

#### `core.store_profiles`
| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| professional_id | uuid FK → core.professionals (1:1) | |
| store_type | enum: salon, barbershop, spa, clinic | |
| chair_count | int | for discovery filters |
| service_types | jsonb | e.g. ["colouring", "barbering"] |
| affiliate_visibility | enum: public, invite_only | mirrors brand pattern |
| lat | numeric | for geo search |
| lng | numeric | for geo search |

#### `core.professional_store_memberships`
| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| professional_id | uuid FK → core.professionals | the employee/contractor |
| store_professional_id | uuid FK → core.professionals | the store |
| role | enum: owner, employee, contractor | |
| status | enum: active, pending, inactive | token invite flow |
| store_commission_rate | numeric(5,2) nullable | store's cut of professional's commissions |

Unique: `(professional_id, store_professional_id)` WHERE active

#### `brand.brand_store_links`
| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| store_professional_id | uuid FK → core.professionals | |
| brand_professional_id | uuid FK → core.professionals | |
| slot | int 0-3 | 0 = primary brand |
| custom_photos_enabled | bool | |

Unique: `(store_professional_id, brand_professional_id)`, `(store_professional_id, slot)`

---

## Features Required

### 1. Discovery & Search
- `/api/discover?type=store&lat=X&lng=Y&radius=10km` endpoint
- Geocoding on `store_profiles` save (address → lat/lng)
- PostGIS or bounding-box query for radius search

### 2. Store Site
- Stores already get a site via the 1:1 `professional → site` model
- Need a new "roster" site block listing member professionals with their headshots/services
- Store site template distinct from professional site template

### 3. Commission Split (3-way payouts)
- `store_commission_rate` on `professional_store_memberships`
- When a professional earns commission, split: professional gets their rate minus store cut
- Store owner receives their cut via their own Stripe Connect account
- `commerce.commission_ledger_entries` needs a `store_professional_id` column + store payout line

### 4. Store Stripe Connect
- Store owner onboards their own Stripe Connect account (same flow as professionals)
- Required before any store commission split can pay out

### 5. Booking (Store-level)
- "Book at this salon" flow: pick store → pick professional + service → book
- Aggregated availability view across all store members
- Routing: match customer's desired service to professionals who offer it at this store

### 6. Analytics
- `analytics.daily_store_metrics` VIEW aggregating across all member professionals
- Brands want store-level performance (which stores drive most sales)

### 7. Notifications
- Store owner notified when: professional joins/leaves, professional makes a sale, brand connection changes

### 8. Roster Management
- Token-based invite flow (same as `brand_affiliate_invites` pattern)
- Role-based access: owner manages brand connections; staff visible on roster only

---

## What Doesn't Change
- Auth/JWT — store owner is just a professional with `professional_type = barbershop|salon`
- Shopify integration — stays brand-level, each professional at a store has their own affiliate link
- Existing `brand_partner_links` — professionals still connect to brands directly; store→brand is additive

---

## Implementation Order (Suggested)

1. `store_profiles` table + `StoreProfile` model + store registration flow
2. `professional_store_memberships` + invite/accept flow
3. Store discovery endpoint with geo search
4. Store site roster block (Tobias/frontend)
5. `brand_store_links` + store→brand connection flow
6. Commission split + store Stripe Connect
7. Store-level analytics
8. Store-level booking aggregation
