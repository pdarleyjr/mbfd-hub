# Public Operational API Hardening Report — MBFD Hub (Phase 2)

Date: 2026-06-06
Findings addressed: **H-01** (public apparatus inspection could force operational status) and **H-02** (public station APIs over-expose internal data).
Commit: `466c6ed1` (merged to `security/ecosystem-hardening-20260606`).

## H-01 — Unauthenticated users can no longer take an apparatus Out of Service

### Before
`POST /api/public/apparatuses/{apparatus}/inspections` (`ApparatusController@storeInspection`, unauthenticated, `throttle:60,1`) called `$apparatus->update(['status' => 'Out of Service'])` whenever a Missing/Damaged defect was reported. **Any anonymous internet user could directly take a fire apparatus out of service** — a real operational-integrity/availability risk for a fire department.

### After (pending-review workflow — mirrors the existing `StationInventoryV2` PIN/signed pattern)
- The public submission is **still recorded** (defects, signatures, meter readings preserved — the daily-checkout workflow is intact).
- A critical defect now sets the inspection's new **`review_status = 'pending_review'`** instead of mutating apparatus operational status.
- A new authenticated, authorized endpoint is the **only** path that applies the Out-of-Service hold:
  `POST /api/apparatus-inspections/{inspection}/approve` → `ApparatusController@approveInspection`, behind `auth:sanctum` + `admin.role:super_admin,admin,logistics_admin`.

### Schema change (safe, additive)
`database/migrations/2026_06_06_000001_add_review_status_to_apparatus_inspections_table.php`: adds `review_status` (string, `default('approved')`), idempotent (`hasColumn` guard), backfills existing rows to `'approved'`. Every legacy inspection keeps its prior behavior; nothing in the public flow breaks. Reversible (`down()` drops the column).

### Tests — `tests/Feature/Api/PublicApparatusInspectionGateTest.php`
1. Anonymous critical-defect submission → apparatus status **unchanged**, inspection `pending_review`.
2. Non-critical submission → `approved`, status unchanged.
3. Unauthenticated approve → **401**.
4. Authorized approve → 200 + apparatus set Out of Service.

## H-02 — Public station endpoints redacted

### Before
`/api/public/stations/*` (StationController) returned the same rich payloads as the authenticated dashboards — personnel/operator names & ranks, gas-meter **serial numbers**, project financials, equipment-request internals, apparatus VIN/notes/Snipe-IT ids, room-asset serials/prices, internal notes. Reconnaissance + social-engineering + operational-privacy risk.

### After
Nine dedicated public Eloquent API Resources under `app/Http/Resources/Public/*`, applied **only** on the `/api/public/*` path (detected via `$request->is('api/public/*')`). **Authenticated/admin responses are byte-for-byte unchanged** (allowlist resources are not used on the authed routes). Approach is allowlist (emit only what the public daily-checkout UI needs), not denylist.

Redacted from public responses:
- personnel: operator/rank, inspector, requester names
- gas-meter **serial numbers** (masked to last-4 `••••1234` so the existing `S/N:` UI line still renders; full serial never emitted — drop the field entirely from `PublicGasMeterResource` if full removal is preferred)
- capital / under-$25k project **budget/spend financials**
- apparatus **VIN/notes/Snipe-IT ids**
- room-asset serial / purchase price
- internal notes

### Tests — `tests/Feature/Api/PublicStationRedactionTest.php`
Eight contract tests, one per public station endpoint (`index`, `show`, `apparatus`, `gas-meters`, `equipment-requests`, `apparatus-inspections`, `station-inspections`, `room-assets`), each asserting the sensitive fields are **absent** and the safe UI fields are **present**.

## Verification (merged tree)
- `php artisan test` (sqlite `:memory:`, RefreshDatabase) for the four new test files **+** the H-04 storage tests: **20 tests / 113 assertions / 0 failures** (the "deprecated" markers are the unrelated PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` notice).
- `route:list`: `POST api/apparatus-inspections/{inspection}/approve` present; public routes intact.
- All changed PHP files lint clean.

## Bot/abuse controls layered on top
- CF **WAF scanner-block** + **login rate-limit** now live at the edge (see `CLOUDFLARE_LIVE_ROUTE_REVIEW.md`).
- Existing Laravel `throttle:10,1` on public write routes retained.
- Recommended (plan-gated): a CF rate-limit rule scoped to `POST /api/public/*` once the zone's rate-limiting entitlement is upgraded.

## Notes / follow-ups
- Pre-existing, out-of-scope bug flagged by the agent: `resources/views/pdf/station-inventory.blade.php` closure missing `use ($categories)` — left untouched.
- The public station read endpoints remain unauthenticated by design (daily-checkout kiosks); redaction + rate-limiting is the chosen control rather than full auth, to preserve the kiosk workflow.
