# Professional Data Export — Design Spec

**Date:** 2026-04-25
**Status:** Approved (brainstorm complete, awaiting plan)

## Overview

Self-service and staff-triggered data export for professionals. Async job assembles a zip containing `data.json` (full machine-readable payload) plus per-table CSVs (customers, bookings, enquiries, payouts), uploads to Cloudflare R2 under `exports/{professional_id}/`, and emails a 7-day signed URL to the recipient. Mirrors the existing Shopify-driven `ExportCustomerDataJob` pattern but scoped to a whole professional account.

This fills the GDPR-portability gap that the 2026-04-19 account-deletion spec deferred ("Data export before deletion — revisit post-beta"). Both endpoints exist; staff endpoint adds a `send_to` query parameter so support/compliance can fetch the data themselves when justified.

---

## Architecture

```
                                    ┌────────────────────────────────────┐
                                    │  redis_gdpr queue (660s timeout)   │
  POST /me/data-export      ┌──────▶│  ExportProfessionalDataJob         │
  (self-service)            │       │  ──────────────────────────────── │
                            │       │  1. Build payload (service)        │
                            │       │  2. Stream JSON + CSVs to temp zip │
                            │       │  3. SHA-256 the file               │
  POST /staff/.../          │       │  4. Upload to R2 exports/{prof}/   │
   data-export?send_to=...  │       │  5. Generate 7d signed URL         │
  (staff)                   │       │  6. Send email to recipient        │
                            │       │  7. Mark audit row complete        │
                            │       └────────────────────────────────────┘
                            │                       │
                            │                       ▼
                            │       ┌────────────────────────────────────┐
                            │       │ R2: exports/{prof_id}/{id}.zip     │
                            │       │ Lifecycle rule: 30d auto-delete    │
                            │       └────────────────────────────────────┘
                            │
                            ▼
                  Returns 202 Accepted +
                  { export_id, status: "queued" }
```

**Key calls:**
- Both endpoints dispatch the same `ExportProfessionalDataJob`. Only the resolved `recipient_email` differs.
- Audit row is written **before** dispatch with `status='queued'`, so a queue failure is still recorded.
- Heavy work happens in the job; controller returns 202 immediately.

---

## API Surface

### Self-service endpoint

```
POST /api/professional/me/data-export
```

**Middleware stack:**
- `supabase.jwt`
- `current.pro`
- `EnforcePendingDeletionReadOnly` — **explicitly exempt.** A professional in their grace period must be able to export their data; that's the GDPR portability point.
- `throttle:1,1440` (1 per 24h per authenticated professional, scoped by `auth_user_id`)

**Request body:** none.

**Response (202 Accepted):**
```json
{
  "export_id": "01HF...",
  "status": "queued",
  "message": "Your data export is being prepared. You'll receive an email at jane@example.com within a few minutes with a download link valid for 7 days.",
  "recipient_email": "jane@example.com"
}
```

**Errors:**
| Code | Trigger |
|------|---------|
| `429` | 24h rate limit; `Retry-After` header set |
| `409` | Existing audit row for this professional with `status IN ('queued','processing')` created in the last 30 minutes |
| `422` | No valid recipient email on file |
| `403` | Status is `suspended` or `disabled` (existing `LoadCurrentProfessional` middleware) |
| `503` | Mail config broken at dispatch (rare; transient) |

### Staff endpoint

```
POST /api/staff/professionals/{professional}/data-export
```

**Middleware stack:**
- `supabase.jwt`
- `staff`
- `throttle:staff` (existing limiter)

**Query params:**
- `send_to` — `professional` (default) or `staff`. `send_to=staff` requires `PartnaStaff::role === 'admin'`; non-admins get `403`.

**Response (202 Accepted):**
```json
{
  "export_id": "01HF...",
  "status": "queued",
  "recipient_email": "jane@example.com",
  "send_to": "professional",
  "professional": { "id": "...", "handle": "jane-doe" }
}
```

**Errors:**
| Code | Trigger |
|------|---------|
| `403` | `send_to=staff` and staff role isn't `admin` |
| `409` | Same 30-min dedup as self-service (staff cannot bypass) |
| `404` | Professional doesn't exist or is hard-deleted |
| `429` | Per-staff rate limit (5/hour) — independent of professional 24h limit |

The professional 1/24h rate limit does **not** apply to staff-triggered exports. The 30-min dedup window applies to both sources.

### No GET status endpoint

The email is the status signal. Staff can inspect `data_export_audit` directly when needed. This avoids building a polling endpoint for a fire-and-forget feature.

---

## Data Model

### New table: `core.data_export_audit`

```sql
CREATE TABLE core.data_export_audit (
    id                            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id               uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
    professional_handle_snapshot  text NOT NULL,
    professional_email_snapshot   text,
    triggered_by                  text NOT NULL CHECK (triggered_by IN ('self', 'staff')),
    triggered_by_staff_id         uuid REFERENCES core.sidest_staff(id) ON DELETE SET NULL,
    recipient_email               text NOT NULL,
    send_to                       text CHECK (send_to IN ('professional', 'staff')),
    status                        text NOT NULL CHECK (status IN ('queued','processing','completed','failed')),
    file_path                     text,
    file_size_bytes               bigint,
    file_sha256                   text,
    record_counts                 jsonb,
    error_message                 text,
    created_at                    timestamptz NOT NULL DEFAULT now(),
    completed_at                  timestamptz
);

CREATE INDEX data_export_audit_professional_status_created_idx
  ON core.data_export_audit (professional_id, status, created_at DESC);

CREATE INDEX data_export_audit_triggered_by_staff_idx
  ON core.data_export_audit (triggered_by_staff_id) WHERE triggered_by_staff_id IS NOT NULL;
```

Design notes:
- `professional_id ON DELETE SET NULL` + `professional_handle_snapshot NOT NULL` — audit survives a professional's hard-delete (mirrors `professional_deletion_audit` precedent). Staff can still answer "who exported this account's data" after the account is gone.
- `record_counts` is `jsonb` — captures `{customers: 142, bookings: 1830, ...}` at export time so you can prove what was in the export later, and SQL-query for unusually large pulls.
- `file_path` is null until the job uploads successfully. `status='failed'` rows tell you which exports broke.
- `triggered_by_staff_id ON DELETE SET NULL` — survives staff offboarding.

### Why a new table (not extending `professional_deletion_audit`)

Different lifecycles (deletion is one-shot; exports are recurring), different columns (file SHA-256, R2 path, recipient email, file size). Reusing the deletion table would force nullables across the board and conflate two unrelated concerns. The cost of a separate table is a single ~10-line migration.

---

## Payload Shape

### `data.json` inside the zip

```json
{
  "metadata": {
    "professional_id": "01HF...",
    "professional_handle": "jane-doe",
    "exported_at": "2026-04-25T14:32:00Z",
    "export_id": "01HF...",
    "schema_version": 1,
    "triggered_by": "self",
    "notes": "This export contains personally identifiable information (PII) you collected from your customers via Partna (booking history, enquiries, email subscriptions). Handle in accordance with applicable privacy law."
  },
  "profile": {
    "professional": { /* full professionals row, minus auth_user_id, deletion_token_hash */ },
    "brand_profile": { /* if applicable */ },
    "brand_partner_links": [ /* if applicable */ ]
  },
  "site": {
    "site": { },
    "blocks": [ ],
    "sections": [ ],
    "theme": { },
    "social_links": [ ],
    "subdomain_aliases": [ ]
  },
  "media": {
    "site_media": [
      { "id": "...", "pool": "...", "purpose": "...", "path": "...", "width": 0, "height": 0, "caption": "...", "alt_text": "...", "created_at": "..." }
    ]
  },
  "integrations": [
    { "provider": "shopify", "shop_domain": "...", "connected_at": "...", "last_sync_at": "..." }
  ],
  "customers": [ /* full customers rows */ ],
  "services": [ ],
  "service_categories": [ ],
  "enquiries": [ ],
  "email_subscriptions": [ ],
  "bookings": {
    "booking_events": [ /* no raw_payload */ ],
    "lead_submissions": [ ]
  },
  "billing": {
    "subscription": { /* current row */ },
    "subscription_history": [ ],
    "commission_ledger_entries": [ ],
    "commission_payouts": [ ],
    "commission_payout_items": [ ],
    "brand_commission_topups": [ ]
  },
  "audit": {
    "deletion_audit": [ /* rows where professional_id matches */ ],
    "data_export_audit": [ /* prior exports */ ]
  }
}
```

### CSVs in the zip

Tables a non-technical user would actually open in Excel:
- `customers.csv` — `id, email, phone, full_name, source, notes, created_at`
- `bookings.csv` — flattened `booking_events`
- `enquiries.csv` — site contact form submissions
- `commission_payouts.csv` — earnings history (when present)

Everything else lives only in `data.json`.

### Deliberately excluded

- OAuth tokens, refresh tokens, webhook signing secrets (security)
- `raw_payload` columns (third-party PII; may include staff/other-customer data)
- `auth_user_id` (Supabase internal)
- `deletion_token_hash` and any password material (security)
- R2 binaries themselves — manifest only; user can re-download via media URLs in the JSON
- Other professionals' data, even if related (a brand's affiliates' customer lists are **not** in the brand's export — only the brand's own customers)

### Scope rings (from brainstorm)

- **Ring 1 (always include):** profile + brand profile, site config, media manifest, integration metadata (no tokens), subscription + billing history, commission ledger + payouts referencing this professional, audit log entries.
- **Ring 2 (always include):** customers, enquiries, booking_events, lead_submissions, email_subscriptions — PII the professional collected from their customers via Partna. Justification matches `ExportCustomerDataJob` precedent and is disclaimed in `metadata.notes`.
- **Ring 3 (always exclude):** other professionals' data, raw third-party API payloads, Stripe webhook payloads, internal staff notes.

Same scope for self-service and staff endpoints.

---

## Components & File Layout

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/Professional/ProfessionalDataExportController.php` | `store()` — self-service trigger. Thin: rate-limit check → dedup check → insert audit row → dispatch job → 202. |
| `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffDataExportController.php` | `store()` — staff trigger. Resolves `send_to`, role-gates `send_to=staff`, otherwise same flow. |
| `app/Http/Requests/Professional/RequestDataExportRequest.php` | Empty-body validation; placeholder for future filters. |
| `app/Http/Requests/Staff/RequestStaffDataExportRequest.php` | Validates `send_to` query param. |
| `app/Services/Professional/DataExportService.php` | **Core.** `dispatch(string $professionalId, string $triggeredBy, ?string $staffId, string $sendTo): DataExportAudit`. Inserts audit row, dispatches job, returns audit. |
| `app/Services/Professional/DataExportPayloadBuilder.php` | Pure builder: `build(string $professionalId): array` — assembles the full JSON payload. No I/O beyond DB reads. Unit-testable. |
| `app/Services/Professional/DataExportZipWriter.php` | Streams payload + CSVs into a temp `.zip`. Returns `['path' => ..., 'sha256' => ..., 'size' => ..., 'record_counts' => [...]]`. |
| `app/Jobs/Gdpr/ExportProfessionalDataJob.php` | Orchestrator. `handle()`: load audit → mark `processing` → build payload → write zip → upload to R2 → generate signed URL → send mail → mark `completed`. Implements `failed()` for terminal failure. |
| `app/Mail/Gdpr/ProfessionalDataExportMail.php` | Mailable parallel to `CustomerDataExportMail`. Conditional staff vs professional banner. |
| `resources/views/emails/gdpr/professional-data-export.blade.php` | Email body template. |
| `app/Models/Core/Gdpr/DataExportAudit.php` | Eloquent model. Mirrors `GdprRequest` shape (`markCompleted()`, `markFailed($reason)`). |
| `supabase/migrations/{ts}_create_data_export_audit.sql` | Schema above. |
| `routes/api/professional.php` (extend) | One new route — `POST /me/data-export` with throttle. |
| `routes/api/staff.php` (extend) | One new route — `POST /professionals/{professional}/data-export`. |
| `config/sidest.php` (extend) | `data_export.signed_url_ttl_days = 7`, `data_export.r2_retention_days = 30`, `data_export.dedup_window_minutes = 30`. The job uses the existing `sidest.gdpr.queue` value — no new queue config. |

### Why `PayloadBuilder` and `ZipWriter` are separate

The builder is pure (DB → array). The writer is impure (streams to disk, computes hashes, allocates temp files). Splitting them lets us unit-test the payload shape with fixture data and zero filesystem touches, and integration-test the writer with a known-fixed input. Conflating them produces code that's hard to test six months from now.

`DataExportService::dispatch()` is the single public entry point; both controllers call it identically. This is also the seam where a future CLI command (`php artisan sidest:export-professional {id}`) could plug in for ad-hoc support work.

### Dispatch flow

```
Controller
  └─▶ DataExportService::dispatch($profId, 'self'|'staff', $staffId?, $sendTo)
        ├─ Check 30-min dedup window (SELECT FROM data_export_audit ... FOR UPDATE on professional row)
        ├─ Insert DataExportAudit row, status='queued'
        ├─ Resolve recipient_email (professional's primary_email or staff's email)
        └─ Dispatch ExportProfessionalDataJob($auditId) onto redis_gdpr queue

Job
  ├─ Load DataExportAudit, status → 'processing'
  ├─ DataExportPayloadBuilder->build($profId) → array
  ├─ DataExportZipWriter->write($payload) → temp file path + sha256 + size + counts
  ├─ Storage::disk(config('sidest.media_disk'))->putFileAs("exports/{$profId}", $tmpPath, "{$auditId}.zip")
  ├─ Generate signed URL (Laravel temporaryUrl, 7d expiry)
  ├─ Update audit: file_path, file_size_bytes, file_sha256, record_counts
  ├─ Mail::to($recipient)->send(new ProfessionalDataExportMail($signedUrl, $audit))
  ├─ Audit: status='completed', completed_at=now()
  └─ unlink($tmpPath)
```

### Implementation hints

- `tries = 3`, `backoff = [60, 300, 900]` — matches `ExportCustomerDataJob`.
- `timeout = 600` (under the 660s supervisor limit).
- `onQueue(config('sidest.gdpr.queue'))` — reuses existing supervisor.
- ZipArchive opens on disk and appends rows in chunks (cursor-based reads on the larger tables — `booking_events`, `customers`, `lead_submissions`) so memory stays bounded at ~50MB even for 50k-row tables.
- Build to `tempnam(sys_get_temp_dir(), 'export-')`, upload via `Storage::disk(config('sidest.media_disk'))->putFileAs()`, then `unlink()`. Compute SHA-256 with `hash_file('sha256', $tmpPath)` before upload.

---

## Operational setup (deploy-time, not in-code)

Two manual steps required before the feature works in any environment:

1. **R2 lifecycle rule.** Configure a lifecycle policy on the media bucket that expires any object under the `exports/` prefix after 30 days. Cloudflare dashboard → R2 → bucket → Settings → Object lifecycle rules. Without this, exports accumulate forever.
2. **Queue worker.** The existing `redis_gdpr` supervisor (660s timeout) already handles this job class — no new worker config. Verify it's running in the deployment environment.

---

## Email Shape

`App\Mail\Gdpr\ProfessionalDataExportMail` — new mailable parallel to `CustomerDataExportMail`.

**Self-service recipient:**
- Subject: `"Your Partna data export is ready"`
- Body: download link, "valid for 7 days", what's inside, support contact.

**Staff recipient (`send_to=staff`):**
- Subject: `"Partna data export — {professional_handle}"`
- Body: same link plus a banner reminding the staff member that this contains customer PII and must be handled per the data-handling SOP.

Same Blade template, conditional banner block.

---

## Error Handling & Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| Self-service: 2nd request within 24h | `429` with `Retry-After` |
| Self-service: 2nd request within 30 min (any source) | `409`, `{ message: "An export is already in progress.", existing_export_id: "..." }` |
| Staff: 6th request within 1h | `429` (per-staff rate limit) |
| Staff: `send_to=staff` but role != admin | `403`, `{ message: "Only admin staff can receive exports directly." }` |
| Staff: professional ID doesn't exist or hard-deleted | `404` |
| Self-service: professional in `pending_deletion` status | **Allowed** (exempt from `EnforcePendingDeletionReadOnly` — the GDPR portability point) |
| Self-service: professional in `suspended` / `disabled` | `403` (existing `LoadCurrentProfessional` blocks them); staff path can still export for them |
| Job: payload build throws (DB query fails) | `markFailed($error)`, Nightwatch error, no retry (data-shape errors unlikely transient) |
| Job: zip write fails (disk full / permissions) | `markFailed($error)` after retries; **DO retry** (transient) |
| Job: R2 upload fails | Retry per `tries=3, backoff=[60,300,900]`; `markFailed` on final attempt |
| Job: signed URL generation fails | Same as upload fail — retry |
| Job: mail send fails | Treated as full job failure (mark `failed`, R2 file gets garbage-collected by lifecycle). Simpler than a `completed_with_email_failure` status. Staff can re-trigger. |
| Job: timeout (>660s) | `failed()` method on the job marks the audit row `failed` with reason "job timeout/killed". Without this, the row stays `processing` indefinitely. |
| Pro hard-deleted while job is queued | Job loads audit, finds `professional_id` null (FK cascaded SET NULL). Marks `failed` with reason "professional deleted before export ran". |
| Recipient email is null/invalid | Caught at dispatch — controller returns `422`, `{ message: "No valid recipient email on file." }`. Audit row not created. |
| R2 lifecycle deletes file before signed URL expires | Theoretically impossible (lifecycle 30d, URL 7d). If it ever happens, recipient gets a generic Cloudflare 404. Acceptable — they email support, who can re-trigger. |
| Signed URL expires before user clicks it | Generic 404. Acceptable. Audit row tells staff which export it was; they re-trigger. |
| Concurrent staff dispatches racing the dedup check | Dedup check + insert wrapped in a transaction with `SELECT ... FOR UPDATE` on `core.professionals`. Second concurrent request sees the queued row and 409s. |

### Re-issuance of expired/lost signed URLs

**Not built in v1.** A pro who lost the email can re-trigger (data is current, takes 30s). Staff can re-trigger on their behalf. If support requests pile up, add `POST /staff/.../data-exports/{export}/re-issue` later — it's a 1-day add.

---

## Testing

**Locations:** `tests/Feature/Professional/DataExport/`, `tests/Feature/Staff/DataExport/`, `tests/Unit/Services/Professional/`, `tests/Feature/Jobs/`.

| Test file | Coverage |
|-----------|---------|
| `RequestSelfServiceExportTest` | Happy path (202 + audit row, `triggered_by='self'`, recipient = pro's email); 429 on 2nd request within 24h; 409 within 30-min dedup; allowed during `pending_deletion`; 403 when `suspended`/`disabled`; 422 when no recipient email; rate limit scoped per professional, not global |
| `RequestStaffExportTest` | Happy path with `send_to=professional` (any staff role); happy path with `send_to=staff` only when role=admin; 403 when `send_to=staff` for non-admin; 404 for missing/hard-deleted professional; 30-min dedup applies across self+staff (staff cannot bypass); per-staff 5/hour rate limit |
| `DataExportPayloadBuilderTest` (unit) | Builds full payload from fixtures; excludes `auth_user_id`, `deletion_token_hash`, OAuth tokens, `raw_payload`; includes ring-1 + ring-2; excludes other professionals' rows; `schema_version=1`; `metadata.notes` present; brand-only sections only present for brand professionals |
| `DataExportZipWriterTest` (unit) | Writes zip to temp; zip contains `data.json` + expected CSVs; `data.json` round-trips through `json_decode`; `customers.csv` row count matches `record_counts.customers`; SHA-256 matches re-hash of file; size matches; cleans up temp file on success and on exception |
| `ExportProfessionalDataJobTest` | Audit transitions queued→processing→completed; payload-build failure marks `failed` with reason; R2 upload fail (mocked) retries up to 3x with backoff; `failed()` method marks audit `failed` after retries exhausted; aborts gracefully if professional hard-deleted between dispatch and run; mail dispatched with signed URL containing `exports/{prof}/{audit}.zip`; `record_counts` matches actual data |
| `ProfessionalDataExportMailTest` | Renders correctly for `send_to=professional` (no staff banner); renders staff banner for `send_to=staff`; signed URL appears in body; subject line format correct |
| `AuditTrailTest` | Audit row survives professional hard-delete (FK SET NULL leaves snapshot intact); query "all exports for staff member X" works; `record_counts` queryable as JSONB |
| `RateLimitTest` | Self-service: 1/24h per professional, not per IP; staff: 5/hour per staff member; both throttle headers present; rate limits independent of each other |

### Mocks

- R2: `Storage::fake('r2')` for assertions on what was uploaded; `temporaryUrl()` returns a stub URL.
- Mail: `Mail::fake()` then `Mail::assertSent(ProfessionalDataExportMail::class)` with closure inspecting recipient + signed URL.
- Time: `Carbon::setTestNow()` for the 30-min dedup and 24h rate-limit windows.

### Manual verification before merge

- Upload a real zip to R2 staging bucket; verify lifecycle rule deletes it after 30 days.
- Send a real email to a test inbox; verify the signed URL works in a browser.

### Out of scope for v1 tests

- Performance/load (50k bookings, 10k customers) — deferred until we have a customer with that volume.
- Cross-region R2 latency — accept as Cloudflare's problem.

---

## Out of Scope (deferred)

- GET status endpoint for in-flight exports (the email is the status surface).
- Re-issuance endpoint for lost/expired signed URLs (re-trigger gives current data; re-issue is a future 1-day add).
- Filtered/partial exports ("just my customers, not my analytics") — request body is empty for now, the request class is in place to add filters later.
- Encryption-at-rest beyond R2's default (AES-256 server-side) — if a future regulator requires customer-managed keys, add it then.
- Performance test harness for very large accounts — wait for a real account that needs it.

---

## References

- Existing pattern: `app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php` + `app/Mail/Gdpr/CustomerDataExportMail.php` (customer-scoped GDPR data request).
- Audit table precedent: `core.professional_deletion_audit` (`docs/superpowers/specs/2026-04-19-account-deletion-design.md`).
- Queue: `redis_gdpr` connection and supervisor (660s timeout).
- Storage: Cloudflare R2 via `Storage::disk(config('sidest.media_disk'))` pattern.
