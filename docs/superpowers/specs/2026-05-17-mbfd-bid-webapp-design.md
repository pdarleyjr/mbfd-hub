# MBFD Bid Web App — Design Spec

> **Status**: DRAFT — pending user approval
> **Author**: Claude Opus 4.7 (1M context)
> **Date**: 2026-05-17
> **Project**: MBFD Hub — Annual Shift Bid platform
> **Locked decisions** (from user, 2026-05-17):
> 1. Auth via Employee Portal API (`/verify-credentials`)
> 2. Cloudflare full-stack (Pages + Workers + Durable Objects + D1 + R2 + AI Gateway)
> 3. Sequential A-Day flow (Phase 2 after Position bid)
> 4. Multi-year reusable platform
> 5. Frontend: Next.js 15 App Router on Cloudflare Pages
> **Design language**: MBFD `.impeccable.md` — Red-700 brand, Slate-850 admin dark, Stone-* warm neutrals, Plus Jakarta Sans + Source Sans 3, tabular-nums, professional easing, NO bouncy motion.

---

## 1. Purpose & Success Criteria

Replace the legacy `MASTER Bid Positions Selection.xlsx` workflow with a **live,
multi-user web application** that runs the annual MBFD shift bid for 2026 and
every subsequent year.

### Success Criteria (must all be true)

| # | Criterion | How verified |
|---|-----------|--------------|
| S1 | A bid event of ~280 members across ~230 positions completes in a single sitting without manual reconciliation | End-to-end staging rehearsal 2 weeks pre-event |
| S2 | Every bid action is auditable; the audit log is tamper-evident | Hash-chained R2 JSONL + D1 audit table |
| S3 | A member can complete their pick on a phone in under 60 seconds | 95p of measured turn duration in staging |
| S4 | The bid survives a Durable Object crash without data loss | Staging "kill the DO" drill, RPO = 0, RTO < 5s |
| S5 | Admin can pause/force/skip/admin-bid-for-member and resume without state corruption | Staging rehearsal scripts |
| S6 | AI advisory provides correct, concise guidance to admin during live event | Hand-validated against 2025 audit log |
| S7 | Members can only act on their own behalf; admin can act on anyone's | Pen-test scope item |
| S8 | The platform is reusable for 2027+ without code changes (data-driven rules + positions) | Schema review — all year-scoped tables carry `bid_year` |

### Non-goals (explicit)

- **Not** an HR system. Member roster + cert data is imported from the credentials PDF/CSV; the app does not replace the source-of-truth credentialing system.
- **Not** a long-term roster manager. After the bid completes, output PDFs/CSVs are exported to existing scheduling systems.
- **Not** publicly accessible. PIN-gated; not indexed.
- **Not** a chat/messaging platform. AI advisory is structured, not conversational.

---

## 2. Users & Use Cases

### Member (firefighter, lieutenant, captain, division chief)
- **Pre-bid (week before)**: Log in, verify own credentials, view 2025 assignment, view eligible 2026 positions, watch order of bid
- **Live bid (on the day)**: Watch the draft board update in real time; when their turn arrives, make a position pick (Phase 1) and later an A-Day pick (Phase 2) within a configurable timer
- **Post-bid**: View confirmed assignment, print roster sheet, export to calendar

### Admin (Fire Chief, Deputy Chief, Operations Division Chief, designated officer-in-charge)
- **Pre-bid (weeks before)**: Import members + certs (CSV); review/edit rules; pre-lock positions (if any); set timer, A-Day mode, exclusion list
- **Live bid**: Start/pause/resume; force a member; skip a member with reason; admin-bid-for-member; consult AI advisory; export interim state
- **Post-bid**: Generate roster PDFs; export audit log; archive session

### Public viewer (optional, future)
- Read-only board view (no auth). Not in v1.

---

## 3. Domain Architecture

```
                                    bid.mbfdhub.com
                                          │
            ┌─────────────────────────────┼─────────────────────────────┐
            │                             │                             │
       PIN gate                       Member portal               Admin console
   (Cloudflare edge mw)         /  /lobby  /draft  /me        /admin/*
            │                        ▲                              ▲
            └────►  cookie-required after correct PIN ──────────────┘
                                          │
                                          ▼
                               Cloudflare Workers API
                                          │
            ┌─────────────────────────────┼─────────────────────────────┐
            ▼                             ▼                             ▼
    Durable Object              D1 (relational)             R2 (uploads/exports)
   (one per bid_session         members, certs, positions   member CSV imports
    holds live state +          rules (versioned), bids,    cert PDFs
    WebSocket fanout)           audit_log, ai_advisories    audit_log JSONL chunks
                                          │
                                          ▼
                              AI Gateway → Anthropic
                                          │
                            Sonnet 4.6 (hot path)
                            Opus 4.7  (deep analyses)
                            prompt cache on rulebook + roster
```

### Subdomains / paths

| Path | Audience | Notes |
|------|----------|-------|
| `bid.mbfdhub.com` | Public hitting the URL | PIN form (`2300`) is the only thing rendered; sets `mbfd_pin` HTTP-only cookie on success |
| `bid.mbfdhub.com/login` | After PIN gate | Employee ID + password form |
| `bid.mbfdhub.com/lobby` | Logged-in member, pre-bid | Profile card + bid order list |
| `bid.mbfdhub.com/draft` | Logged-in member, live | Main draft board |
| `bid.mbfdhub.com/me` | Logged-in member | Profile + A-Day picker (Phase 2) |
| `bid.mbfdhub.com/admin` | Logged-in admin | Dashboard |
| `bid.mbfdhub.com/admin/bid` | Logged-in admin, live | Live bid console + AI panel |
| `bid.mbfdhub.com/admin/members` | Logged-in admin | Member + cert import / edit |
| `bid.mbfdhub.com/admin/rules` | Logged-in admin | Rule editor |
| `bid.mbfdhub.com/admin/audit` | Logged-in admin | Audit log viewer |

---

## 4. Tech Stack (locked)

| Layer | Choice | Rationale |
|-------|--------|-----------|
| Frontend framework | **Next.js 15 App Router** | Mature on Cloudflare via `@opennextjs/cloudflare`; best Radix/shadcn fit |
| UI components | **shadcn/ui** (Radix primitives) | Accessible, themeable, no-fork ownership |
| Styling | **Tailwind CSS** + design tokens from `.impeccable.md` | Already aligned with MBFD Hub system |
| Client state | **Zustand** (draft board, modal state) + **TanStack Query** (server data) | Lightweight, fits the fantasy-draft live UX |
| Realtime | **Cloudflare Durable Objects + WebSockets** | Single source of truth for live bid state |
| API | **Cloudflare Workers** + **Hono** framework | Edge-fast, OpenAPI-native, typed |
| Validation | **Zod** + **drizzle-zod** | Single schema source for DB ↔ API ↔ client |
| ORM | **Drizzle ORM** on D1 | Type-safe SQL, migration-friendly |
| Database | **Cloudflare D1** (SQLite) | Sufficient scale (~300 members, ~250 bids); zero ops |
| Object storage | **Cloudflare R2** | Audit log JSONL chunks, member CSV uploads, generated PDFs |
| KV cache | **Cloudflare KV** | Session JWTs, feature flags, eligibility bitmap snapshots |
| AI | **AI Gateway → Anthropic** (Claude Sonnet 4.6 + Opus 4.7) | Highest-quality LLM; prompt caching reduces cost ~90% |
| Auth | **Employee Portal API** (`POST /verify-credentials`) → **session JWT** | Per user decision |
| PIN gate | **Cloudflare Worker edge middleware** with bcrypt'd PIN | Cheapest hide-from-public mechanism |
| Hosting | **Cloudflare Pages** (frontend) + **Workers** (API) | One vendor; edge-native |
| Observability | **Cloudflare Logpush** → **R2** (raw) + **Logflare** (queryable) | Free tier sufficient |
| CI/CD | **GitHub Actions** → **wrangler deploy** | Industry standard |
| Testing | **Vitest** (unit), **Playwright** (E2E), **MSW** (API mocks) | Standard React-on-Cloudflare toolkit |

---

## 5. System Architecture

### 5.1 Cloudflare topology

```
┌──────────────────────────────────────────────────────────────────────────┐
│                          bid.mbfdhub.com                                  │
│                          (Cloudflare DNS)                                 │
└──────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  Cloudflare Pages — Next.js 15 (App Router) — static + edge SSR          │
│  Routes: / (PIN gate), /login, /lobby, /draft, /me, /admin/*              │
└──────────────────────────────────────────────────────────────────────────┘
                                  │
                  REST  +  WebSocket upgrade
                                  ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  Cloudflare Worker — Hono API                                             │
│  - PIN check middleware                                                   │
│  - JWT session middleware                                                 │
│  - Role-based authz (member|admin)                                        │
│  - Routes WS upgrades to the BidSession DO                                │
│  - REST routes for non-live work (member CRUD, cert upload, AI calls)     │
└──────────────────────────────────────────────────────────────────────────┘
                  │                              │
        WS upgrade                            REST
                  ▼                              ▼
┌──────────────────────────┐      ┌──────────────────────────────────────┐
│  BidSession Durable      │      │  D1 — relational DB                  │
│  Object (one per         │◄─────│  members, certs, positions,          │
│  active bid_session_id)  │      │  rules, bids, audit_log,             │
│                          │      │  ai_advisories                       │
│  - WebSocket fanout      │      └──────────────────────────────────────┘
│  - Bid queue cursor      │                      │
│  - Position fills bitmap │                      ▼
│  - Eligibility bitmap    │      ┌──────────────────────────────────────┐
│  - Turn timer            │      │  R2 — object storage                 │
│  - Persisted to DO       │◄─────│  audit_log JSONL chunks (hash-chained)│
│    storage every event   │      │  member CSV imports                  │
│                          │      │  generated PDFs (roster, audit)      │
└──────────────────────────┘      └──────────────────────────────────────┘
                  │                              │
                  └──────────────┬───────────────┘
                                 ▼
              ┌──────────────────────────────────────┐
              │  AI Gateway → Anthropic              │
              │  Sonnet 4.6 hot path                 │
              │  Opus 4.7 deep analysis              │
              │  Prompt cache on rulebook + roster   │
              └──────────────────────────────────────┘

           ─── External integrations (outbound from Worker) ───
                                 │
            ┌────────────────────┴────────────────────┐
            ▼                                          ▼
┌────────────────────────┐         ┌──────────────────────────────────┐
│  Employee Portal       │         │  Cloudflare Queue                │
│  POST /verify-creds    │◄────────│  `portal-writebacks`             │
│  (inbound — auth)      │         │  Consumer Worker: POST to        │
│                        │         │  portal /members/:emp/bid-assign │
│  POST /members/:emp/   │◄────────│  Exp backoff retry up to 24h    │
│       bid-assignment   │         │                                  │
│  (outbound — portal    │         │  Picks remain durable in D1+R2   │
│   action card)         │         │  even if portal is down          │
└────────────────────────┘         └──────────────────────────────────┘
```

### 5.2 Durable Object boundary — **one DO per bid_session**

Bidding is strictly serial — there is only ever one active bidder across all
shifts. Splitting state across multiple DOs would re-introduce the race
conditions DOs exist to prevent.

**State held in DO memory** (transient, rebuilt from storage on restart):
- `queue_cursor` — index into the ordered bid_order list
- `current_bidder_id`
- `current_phase` — `position_bid` | `a_day_bid` | `paused` | `complete`
- `position_fills_map` — `Map<position_id, member_id | null>`
- `eligibility_bitmap` — `Uint8Array(member_count × position_count)` — deterministic per-rule eligibility, recomputed on every pick
- `turn_started_at_ms` (timer base)
- `last_event_seq` — monotonic event sequence number for client reconciliation
- `connected_clients` — `Map<client_id, WebSocket>` for fanout

**Persisted to DO storage** (`state.storage.put`) **synchronously before
broadcasting**:
- `queue_cursor`, `current_bidder_id`, `current_phase`, `position_fills_map`,
  `turn_started_at_ms`, `last_event_seq`

**NOT held in DO**: full member/cert/rule data — that lives in D1 and is
queried lazily.

### 5.3 Real-time event flow (canonical "member submits a pick" path)

```
1. Member clicks "Submit pick" in /draft on their phone
2. Client WS message → BidSession DO: { type: "submit_pick", position_id, a_day, idempotency_key }
3. DO validates:
   - Sender's JWT.member_id == current_bidder_id  (cannot bid for someone else)
   - Position not already filled
   - Member is eligible for position (eligibility_bitmap check)
   - Idempotency key not seen before
4. DO atomic state update:
   - position_fills_map[position_id] = member_id
   - eligibility_bitmap column for position_id zeroed
   - queue_cursor++
   - last_event_seq++
5. DO writes to:
   a. DO storage (state.storage.put — synchronous, before ACK)
   b. D1 audit_log INSERT (async, fire-and-forget but queued)
   c. R2 audit_log JSONL append (async, hash-chained)
6. DO broadcasts to all connected_clients:
   { type: "pick_made", seq, member_id, position_id, a_day,
     next_member_id, next_member_name, turn_started_at_ms }
7. Clients update local Zustand store on receipt
8. If next_member_id has a pending Opus pre-fetch (eligibility explanation), serve it from KV
```

### 5.4 DO recovery path (the SPOF mitigation)

If the BidSession DO crashes or is evicted mid-bid:
1. Next WS connection or REST call to the DO triggers `state.storage.get()` rehydration
2. DO replays state from storage into memory
3. DO broadcasts `{ type: "RESYNC", last_event_seq, full_state }` to all reconnecting clients
4. Clients reconcile their local store with the canonical state
5. Bidders see a brief "Reconnecting…" overlay (sub-2-second in practice)
6. The D1 + R2 audit log is the legal record — DO storage is just the live cursor

Mitigations layered on top:
- **Shadow snapshot**: A Cloudflare Workflow takes a JSON snapshot of DO state every 5s and writes to D1 `bid_session_snapshots`. If DO storage corrupts, we fall back to the snapshot.
- **Print-the-queue fallback**: Pre-bid, admin prints the seniority queue + position template to a chief's laptop. If everything else fails, the bid continues offline and is re-entered.
- **Staging "kill the DO" drill**: 2 weeks pre-event, intentionally crash the DO mid-staging-bid and verify recovery.

---

## 6. Data Model

### 6.1 D1 schema (Drizzle TypeScript shown; actual is SQLite DDL)

```ts
// bid_year — top-level partition key; same app runs 2026, 2027, 2028…
bid_years: {
  year: integer primary key,
  status: enum('configuring','live','paused','complete','archived'),
  position_template_version: text references position_templates.version,
  rule_book_version: text references rule_books.version,
  config_json: text,  // a_day_mode, turn_timer_seconds, allowed_shifts, etc.
}

members: {
  id: integer primary key autoincrement,
  employee_id: text unique,
  first_name: text,
  last_name: text,
  rank: enum('FF','LT','CPT','DC','DEP_CHIEF','CHIEF'),
  bid_category: enum('OFC','FF','EXCLUDED'),
  rsc_seniority: integer,    // dept-wide seniority number
  rank_seniority: integer,   // tie-break
  hired_at: date,
  promoted_at: date,
  is_probationary: boolean,
}

credentials: {
  id: integer primary key autoincrement,
  name: text unique,           // e.g. "Driver Engineer Qualified"
  fy_points_default: integer,  // can be overridden per bid_year
}

member_credentials: {
  member_id integer references members,
  credential_id integer references credentials,
  start_date: date,
  expiration_date: date,
  primary key (member_id, credential_id),
}

position_templates: {
  version: text primary key,            // e.g. "2026.1"
  effective_year: integer,
}

positions: {
  id: text primary key,                 // e.g. "A101"
  template_version: text references position_templates,
  shift: enum('A','B','C','D'),
  station: text,                        // "1","2","3","4","6","Float","UnionPres"
  division: enum('Combat','Rescue','Prevention','Training','Support Services'),
  unit: text,                           // "Ladder 1", "Engine 4", "Fire Boat"
  rank_required: enum('FF','LT','CPT','DC'),
  position_name: text,                  // "Firefighter #1 INV", "Marine Deckhand"
  is_floating: boolean,
  is_vacant_by_design: boolean,         // true for XX215
  is_excluded_from_count: boolean,      // true for A711 Union President
}

rule_books: {
  version: text primary key,            // e.g. "2026.1"
  effective_year: integer,
}

position_rules: {                       // one row per (template_version, position_id, rank?)
  id: integer primary key autoincrement,
  rule_book_version: text references rule_books,
  position_id: text references positions,
  required_criteria: text,              // JSON: {credentials:[...], rank:[...], custom:[...]}
  points_preference: text,              // JSON: {max:18, items:[{points, credential, gating:"operations"}]}
  tie_break_chain: text,                // JSON: ["points","so_points","mo_points","rsc_seniority","rank_seniority"]
  notes: text,
}

bid_sessions: {
  id: text primary key,                 // ulid
  bid_year: integer references bid_years,
  started_at: timestamp,
  paused_at: timestamp nullable,
  completed_at: timestamp nullable,
  current_phase: enum('config','position_bid','a_day_bid','paused','complete'),
  current_bidder_id: integer nullable references members,
  current_turn_started_at: timestamp nullable,
  // Live-adjustable timer config
  turn_timer_seconds: integer default 180,         // 3 min default; admin can change live
  // Multi-day support (§11.7)
  expected_duration_days: integer default 2,
  scheduled_resume_at: timestamp nullable,         // set by /day-end, cleared by /day-start
  day_count: integer default 0,                    // increments on each day-start
}

bid_order: {                            // computed pre-bid, immutable
  bid_session_id: text references bid_sessions,
  ordinal: integer,                     // 1..N
  member_id: integer references members,
  pool: enum('OFC','FF'),
  primary key (bid_session_id, ordinal),
}

bids: {                                 // one row per pick
  id: text primary key,                 // ulid
  bid_session_id: text references bid_sessions,
  ordinal: integer references bid_order,
  member_id: integer references members,
  position_id: text references positions,
  a_day: text,                          // "G1".."G4" or "MON".."SUN"; null if Phase 1 only
  picked_at: timestamp,
  forced: boolean,                      // true if admin-forced
  admin_actor_id: integer nullable references members,
  reason: text nullable,                // forced/skipped/admin-bid-for-member
  idempotency_key: text unique,
  // Portal write-back tracking (§11.8)
  portal_sync_status: enum('pending','synced','failed','superseded') default 'pending',
  portal_synced_at: timestamp nullable,
  portal_sync_attempts: integer default 0,
  portal_last_error: text nullable,
}

portal_writeback_queue: {               // outbound retry queue for portal action-card sync
  id: text primary key,
  bid_id: text references bids,
  enqueued_at: timestamp,
  next_attempt_at: timestamp,
  attempts: integer,
  status: enum('queued','in_flight','done','failed'),
  payload_json: text,
  last_error: text nullable,
}

audit_log: {
  id: text primary key,                 // ulid
  bid_session_id: text references bid_sessions,
  seq: integer,                         // monotonic per session
  actor_type: enum('member','admin','system','ai'),
  actor_id: integer nullable,
  action: enum('pick','forced_pick','pause','resume','skip','override_rule',
               'override_cert','lock_position','unlock_position',
               'grant_extension','admin_bid_for_member','session_start',
               'session_complete'),
  target_kind: text nullable,            // "position", "member", "rule", etc.
  target_id: text nullable,
  before_state: text nullable,           // JSON
  after_state: text nullable,            // JSON
  reason: text nullable,
  ai_advisory_id: text nullable,         // FK to ai_advisories
  client_meta: text,                     // JSON: ip_hash, ua, fresh_auth_at
}

ai_advisories: {
  id: text primary key,                 // ulid
  bid_session_id: text references bid_sessions,
  member_id: integer nullable,           // member the advisory pertains to
  position_id: text nullable,
  triggered_by: enum('turn_start','admin_request','periodic_forecast','override_check'),
  model: text,                           // "claude-sonnet-4-6" | "claude-opus-4-7"
  prompt_hash: text,
  response_json: text,                   // structured advisory
  rendered_markdown: text,               // pre-rendered for admin UI
  latency_ms: integer,
  cost_cents: integer,                   // estimated
  cache_hit_ratio: real,                 // 0..1 from Anthropic cache stats
}

bid_session_snapshots: {                 // shadow replicas every 5s
  bid_session_id: text references bid_sessions,
  snapshot_at: timestamp,
  state_json: text,
  primary key (bid_session_id, snapshot_at),
}
```

### 6.2 KV usage

| Key pattern | Value | TTL | Purpose |
|-------------|-------|-----|---------|
| `pin:hash` | bcrypt hash of "2300" | none | PIN check |
| `session:{jwt_id}` | { member_id, role, fresh_auth_at } | 8h | session metadata |
| `eligibility_snapshot:{session_id}` | full bitmap (binary) | 5m | UI hint cache |
| `pre_fetch:{member_id}` | Opus advisory result | 1h | Pre-warm AI for on-deck member |
| `feature_flag:{name}` | bool/string | none | runtime flags |

### 6.3 R2 usage

| Bucket | Object pattern | Notes |
|--------|----------------|-------|
| `bid-audit` | `{year}/{session_id}/chunks/{chunk_seq}.jsonl` | Hash-chained, immutable, signed |
| `bid-imports` | `{year}/members_{timestamp}.csv` | Admin uploads |
| `bid-imports` | `{year}/credentials_{timestamp}.pdf` | Source PDF |
| `bid-exports` | `{year}/{session_id}/A_Shift_{timestamp}.pdf` | Generated rosters |
| `bid-exports` | `{year}/{session_id}/audit_full.csv` | Full audit export |

### 6.4 Audit log integrity (hash chain)

Each R2 JSONL chunk header includes:
```json
{
  "chunk_seq": 12,
  "prev_chunk_sha256": "abc123…",
  "events_in_chunk": 100,
  "min_seq": 1100,
  "max_seq": 1199,
  "signature": "ed25519:…"
}
```
Worker-held signing key. Any post-hoc tampering breaks the chain and is
detectable via `verify-chain` admin endpoint.

---

## 7. API Surface (Hono on Workers, OpenAPI-typed via Zod)

> All routes carry `X-Bid-Year` header (defaults to current `bid_year.status='live'`).

### 7.1 Public (no auth, behind PIN gate cookie)

| Method | Path | Body / Query | Returns |
|--------|------|--------------|---------|
| `POST` | `/api/pin` | `{ pin }` | `204` + sets `mbfd_pin` cookie OR `401` |
| `POST` | `/api/auth/login` | `{ employee_id, password }` | `{ jwt, role, member }` (calls portal `/verify-credentials`) |
| `POST` | `/api/auth/logout` | — | `204` |

### 7.2 Member-scoped (auth: member or admin)

| Method | Path | Body | Returns |
|--------|------|------|---------|
| `GET` | `/api/me` | — | current member profile incl. `bid_status`, `prev_year_bid`, `cert_summary` |
| `GET` | `/api/me/eligibility` | — | array of `{ position_id, eligible, reason?, points, so_pts, mo_pts }` |
| `GET` | `/api/board` | — | current draft board state (positions + fills) for client SSR fallback |
| `GET` | `/api/bid-order` | — | full bid order list with current cursor |
| `WS` | `/api/ws/session` | — | Live event stream from BidSession DO |

WS message types (client → server):
- `submit_pick { position_id, a_day?, idempotency_key }`
- `heartbeat`

WS message types (server → client):
- `state_snapshot` (on connect, on reconnect)
- `pick_made { seq, member_id, position_id, a_day?, next_member_id, … }`
- `phase_changed { from, to }`
- `turn_started { member_id, ends_at_ms }`
- `paused { by_admin_id, reason }`
- `resumed`
- `RESYNC { last_event_seq, full_state }` (after DO restart)

### 7.3 Admin-scoped (auth: admin, step-up auth on writes)

| Method | Path | Body | Step-up? | Returns |
|--------|------|------|----------|---------|
| `GET` | `/api/admin/dashboard` | — | no | counts, status, AI alerts |
| `POST` | `/api/admin/members/import` | multipart CSV | no | `{ imported, errors }` |
| `POST` | `/api/admin/credentials/import` | multipart PDF/CSV | no | `{ imported, errors }` |
| `PATCH` | `/api/admin/members/:id` | partial | no | updated member |
| `POST` | `/api/admin/positions/clone-from-year/:src_year` | `{ target_version }` | no | cloned template |
| `PATCH` | `/api/admin/rules/:rule_id` | partial | **yes** | updated rule |
| `POST` | `/api/admin/bid-session` | `{ bid_year, config }` | **yes** | new session in `config` phase |
| `POST` | `/api/admin/bid-session/:id/start` | — | **yes** | session → `position_bid` |
| `POST` | `/api/admin/bid-session/:id/pause` | `{ reason }` | **yes** | session → `paused` |
| `POST` | `/api/admin/bid-session/:id/resume` | — | **yes** | session → previous phase |
| `POST` | `/api/admin/bid-session/:id/force-pick` | `{ member_id, position_id, reason }` | **yes** + AI dissent log | pick recorded with `forced=true` — **may override eligibility** |
| `POST` | `/api/admin/bid-session/:id/skip` | `{ member_id, reason }` | **yes** | member skipped (no pick recorded) |
| `POST` | `/api/admin/bid-session/:id/bid-for-member` | `{ member_id, position_id, a_day?, reason }` | **yes** | pick recorded with `admin_actor_id` set; **must satisfy eligibility** (proxy bid only) |

> **force-pick vs bid-for-member**: These are different intents.
> - **`bid-for-member`** = admin clicks on the member's behalf because the member is unreachable (phone died, etc.) but the chosen position IS eligible for them. Eligibility checks run. Audit records `admin_actor_id`.
> - **`force-pick`** = admin overrides the rules (typically reverse-seniority forcing in the final picks for a credentialed slot). Eligibility check is bypassed. Audit records `forced=true` + reason + AI dissent if applicable.
| `POST` | `/api/admin/bid-session/:id/lock-position` | `{ position_id, member_id }` | **yes** | pre-bid lock |
| `GET` | `/api/admin/ai/advise-current` | — | no | structured advisory for current bidder |
| `POST` | `/api/admin/ai/advise-deep` | `{ question }` | no | Opus deep-dive response |
| `GET` | `/api/admin/audit` | `?from=&to=&actor=&action=` | no | paginated audit events |
| `GET` | `/api/admin/audit/verify-chain` | — | no | chain integrity report |
| `GET` | `/api/admin/exports/:format` | — | no | signed R2 URL |

---

## 8. Authentication & Authorization

### 8.1 PIN gate (edge middleware)

```
Cloudflare Worker / Pages Function on /*:
  if cookie.mbfd_pin == sha256(pin_secret + "ok"):
    pass through
  else if path == "/" or path == "/api/pin":
    serve PIN form / accept POST
  else:
    302 → /
```

PIN value (`2300`) is bcrypt-hashed and held in KV. Rotatable by admin without
redeploy.

### 8.2 Employee Portal SSO

```
Login flow:
  POST /api/auth/login { employee_id, password }
    │
    ▼
  Cloudflare Worker:
    a. Fetch portal: POST https://portal.mbfdhub.com/api/v2/verify-credentials
       Body: { employee_id, password }
       Auth: Worker bears a static service-account token (in env)
    b. On 200: { member_id, name, role }
       i. Issue JWT (HS256, 8h):
          { sub: member_id, role: member|admin, fresh_auth_at: now }
       ii. Set HTTP-only cookie `mbfd_bid_jwt`
    c. On 401: return 401 to client
```

JWT verified per request in Hono middleware. `fresh_auth_at` re-checked on
step-up admin actions (must be <5 min old; if not, force re-login with password).

### 8.3 Role-based access

| Role | Determined by | Capabilities |
|------|---------------|--------------|
| `member` | Portal returns generic firefighter role | Read own profile; read board; submit own pick (if their turn) |
| `admin` | Portal returns `Fire Chief`, `Deputy Chief`, `Division Chief` rank, OR explicit `bid_admin = true` flag | All member capabilities + all admin endpoints |
| `viewer` (future) | Public, no auth | Read-only board |

### 8.4 Authorization checks

Every write checks:
- JWT valid + not expired
- Role permits the action
- For member self-actions: `JWT.sub == path-or-body member_id`
- For admin step-up: `now - fresh_auth_at < 5 min`
- For force/skip/bid-for-member: structured reason code AND AI dissent log entry

---

## 9. Member UI/UX

### 9.1 Routes

```
/                  → PIN form (Stone-50 bg, Plus Jakarta heading, red-700 submit)
/login             → Employee ID + password form (after PIN)
/lobby             → Pre-bid lobby (your profile, bid order, countdown to event)
/draft             → Live draft board (the fantasy-draft view)
/me                → Profile page + Phase 2 A-Day picker
/post              → Post-bid confirmation + roster sheet
```

### 9.2 Pre-bid lobby (`/lobby`)

```
┌─────────────────────────────────────────────────────────────────────────┐
│  MBFD Bid 2026     [Hi, Peter Darley — Lt #20731 — Logout]   🔴 LIVE 14:23│  ← slate-850 header
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌─────────────────────────┐  ┌─────────────────────────────────────┐   │
│  │ YOUR PROFILE            │  │ YOU ARE ON DECK — 7 picks away      │   │
│  │                         │  │                                      │   │
│  │ Lt. Peter Darley        │  │ Currently bidding:                   │   │
│  │ Bid # 75                │  │ Cash, C — Bid #71 (00:43 remaining) │   │
│  │ Rank Sr 20731           │  │                                      │   │
│  │ Pool: OFC               │  │ Up next:                             │   │
│  │                         │  │ 72. Bowman, T                        │   │
│  │ 2025 → Rescue 1 Lt (A)  │  │ 73. Miro, A                          │   │
│  │ A-Day Group 4           │  │ 74. Garcia, G                        │   │
│  │                         │  │ ► 75. YOU                            │   │
│  │ [View My Credentials]   │  │ 76. Antoine, D                       │   │
│  └─────────────────────────┘  └─────────────────────────────────────┘   │
│                                                                          │
│  ELIGIBLE POSITIONS FOR YOU (informational pre-look)                     │
│  Sta 1 Rescue 1 Lt   ●  open   12pts   Sta 4 Rescue 4 Lt   ●  open      │
│  Sta 1 Rescue 11 Lt  ●  open   9pts    Sta 4 Rescue 44 Lt  ●  open      │
│  Sta 3 Rescue 3 Lt   ●  taken          Float R1            ●  open      │
│  Sta 2 Rescue 2 Lt   ●  pts gate       Float R2            ●  taken     │
│                                                                          │
│  [I'll be ready] [Test my connection]                                    │
└─────────────────────────────────────────────────────────────────────────┘
```

### 9.3 Live draft view, desktop (`/draft`)

> Mockup data below (names, GR groups, point values) is **illustrative** —
> drawn from 2025 actuals for shape verification only; not pre-bid 2026 data.

```
┌─────────────────────────────────────────────────────────────────────────┐
│  MBFD Bid 2026 — Phase 1: Position Bid       [shift toggle: A B C D ●]  │  ← slate-850 header
├─────────────────────────────────────────────────────────────────────────┤
│  ▶ NOW BIDDING                                            00:43         │
│    #74 Garcia, G   (Lt — OFC pool)                       [████░░░░]     │  ← red-700 progress
├─────────────────────────────────────────────────────────────────────────┤
│ ┌──────────────┐ ┌──────────────────────────────────────────────────┐  │
│ │  ORDER       │ │  STATION 1                                       │  │
│ │              │ │  ┌──────────────────────────────────────────┐    │  │
│ │  ✓ 1 Sola J  │ │  │ Ladder 1                                 │    │  │
│ │  ✓ 2 Lopez   │ │  │ ● A101 Cpt   Cruzado L     GR2          │    │  │
│ │  …           │ │  │ ● A102 DE    Ingram M       GR3          │    │  │
│ │  ✓ 67 Wells  │ │  │ ● A103 FF#1  Coppo M        GR3          │    │  │
│ │  ► 74 Garcia │ │  │ ● A104 FF#2  Cento E        GR3          │    │  │
│ │  ◌ 75 YOU    │ │  └──────────────────────────────────────────┘    │  │
│ │  ◌ 76 Antoine│ │  Engine 1                                        │  │
│ │  …           │ │  ● A105 Lt   Wells C    GR2                     │  │
│ │  ◌ 230 …     │ │  ● A106 DE   Trentacosta GR3                    │  │
│ │              │ │  ○ A107 FF#1  ← open  (eligible to you)         │  │
│ │  Filter:     │ │  ○ A108 FF#2  ← open  (eligible to you)         │  │
│ │  [all][mine] │ │                                                  │  │
│ │  Phase: 1    │ │  Rescue 1                                        │  │
│ │              │ │  ○ A109 Lt   ← open  (eligible to you)          │  │
│ │              │ │  ○ A110 FF#1 ← open                              │  │
│ │              │ │  ○ A111 FF#2 ← open                              │  │
│ │              │ │                                                  │  │
│ └──────────────┘ │  Rescue 11                                       │  │
│                  │  ○ A112 Lt   ← open  (eligible to you)          │  │
│ ┌──────────────┐ │  ○ A113 FF#1 ← open                              │  │
│ │  YOUR CARD   │ │  ○ A114 FF#2 ← open                              │  │
│ │              │ │                                                  │  │
│ │ Peter Darley │ │  Float 1 (Combat)                                │  │
│ │ Lt #20731    │ │  ○ A115 DE   ← open                              │  │
│ │              │ │  ○ A116 FF#1 ← open                              │  │
│ │ 2025 picked: │ │  …                                               │  │
│ │ A109 Rescue1 │ └──────────────────────────────────────────────────┘  │
│ │ Lt — Grp 4   │                                                       │
│ │              │   [More stations: Sta 2 ▾ Sta 3 ▾ Sta 4 ▾ Sta 6 ▾   │
│ │ 2026 pick:   │    Rescue Float Pool ▾]                              │
│ │ (not yet)    │                                                       │
│ └──────────────┘                                                       │
└─────────────────────────────────────────────────────────────────────────┘
```

**Legend** (cards in the position grid):
- ● filled, showing assignee + A-Day Group suffix
- ○ open, eligible to me — Stone-50 background, red-200 border on hover
- ▢ open, NOT eligible — Stone-100 background, muted; tooltip shows reason
- ⊘ position locked or vacant-by-design

### 9.4 Live draft view, mobile

Three vertical tabs at bottom of viewport (44px touch targets):

```
┌─────────────────────────────────────────┐
│ MBFD Bid 2026                      ⊕    │ ← slate-850
├─────────────────────────────────────────┤
│ ▶ #74 Garcia, G   ████░░  00:43         │
│                                          │
│ [tab: Board ● ] [Order] [Me]            │
├─────────────────────────────────────────┤
│ Shift:  A ●  B   C   D                  │
│                                          │
│ Station 1                                │
│  Ladder 1                                │
│  ● A101 Cpt   Cruzado L                 │
│  ● A102 DE    Ingram M                  │
│  ● A103 FF#1  Coppo M                   │
│  ● A104 FF#2  Cento E                   │
│  Engine 1                                │
│  ● A105 Lt    Wells C                   │
│  ● A106 DE    Trentacosta               │
│  ○ A107 FF#1  open (eligible)           │
│  ○ A108 FF#2  open (eligible)           │
│  Rescue 1                                │
│  ○ A109 Lt    open (eligible) →          │  ← tap to expand pick UI
│  …                                       │
└─────────────────────────────────────────┘
```

Mobile shows ONE pane at a time. Order/Me are bottom tabs. Tapping an
eligible open position on Board pre-stages the pick; the Your-Turn modal
takes over when the member's turn arrives.

### 9.5 Your-Turn modal (when member's ordinal == current_bidder)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                       IT'S YOUR TURN, LT. DARLEY                         │
│                       ████░░░░░░░░  02:43 remaining                      │
├─────────────────────────────────────────────────────────────────────────┤
│  YOU ARE BIDDING FOR (Phase 1: Position)                                 │
│                                                                          │
│  Most-recommended (highest points, eligible):                            │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │ ● Sta 3 Rescue 3 Lt (B309)                          12 pts ✓   │    │
│  │ ● Sta 4 Rescue 4 Lt (B405)                          11 pts     │    │
│  │ ● Sta 1 Rescue 1 Lt (B109)                          10 pts     │    │
│  │   …                                                              │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  All eligible (114 positions): [show all]                                │
│                                                                          │
│  Selected: [Sta 3 Rescue 3 Lt — B309]      [Change]   [Confirm pick]   │
│                                                                          │
│  Phase 2 A-Day pick will run after Phase 1 completes.                    │
└─────────────────────────────────────────────────────────────────────────┘
```

Confirmation triggers a 5-second "undo" toast before the pick locks.

### 9.6 Phase 2 A-Day picker (`/me` after Phase 1 complete)

```
┌─────────────────────────────────────────────────────────────────────────┐
│  PHASE 2 — Choose your A-Day                                             │
│                                                                          │
│  Your assignment: Sta 3 Rescue 3 Lt (B309), B Shift                      │
│                                                                          │
│  Available A-Day Groups for B Shift Rescue:                              │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐                │
│  │ Group 1  │  │ Group 2  │  │ Group 3  │  │ Group 4  │                │
│  │  18 / 19 │  │  19 / 19 │  │  17 / 19 │  │  19 / 19 │                │
│  │   ●      │  │ ○ FULL   │  │   ●      │  │ ○ FULL   │                │
│  │ 5 OFC ✓  │  │ 5 OFC ✓  │  │ 5 OFC ✓  │  │ 5 OFC ✓  │                │
│  │ Select   │  │  (full)  │  │ Select   │  │  (full)  │                │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘                │
└─────────────────────────────────────────────────────────────────────────┘
```

D-Shift members see weekdays instead of Groups.

### 9.7 Component tree (member side)

```
app/
├── (auth)/
│   ├── page.tsx                   ← PIN form
│   └── login/page.tsx             ← Employee ID + password
├── lobby/page.tsx
├── draft/
│   ├── page.tsx                   ← Server-render initial state, hydrate WS
│   ├── _components/
│   │   ├── DraftHeader.tsx        ← top status bar
│   │   ├── CurrentTurnBanner.tsx  ← who's up, timer
│   │   ├── OrderSidebar.tsx       ← scrollable order list
│   │   ├── ProfileCard.tsx        ← member's card (2025 vs 2026)
│   │   ├── StationBoard.tsx       ← center grid
│   │   ├── ShiftToggle.tsx        ← A/B/C/D segmented control
│   │   ├── PositionCell.tsx       ← single position (●/○/▢/⊘)
│   │   └── YourTurnModal.tsx      ← takeover modal when up
├── me/page.tsx                    ← Phase 2 A-Day picker + read-only profile
└── post/page.tsx                  ← Post-bid roster sheet
```

Client state stores (Zustand):
- `useDraftStore` — full board state, mirrored from WS
- `useMeStore` — member's own pick state
- `useUiStore` — modal open, shift toggle, sidebar filter

Server state (TanStack Query):
- `me`, `eligibility`, `bid-order` — query keys per bid_session_id

---

## 10. Admin UI/UX

### 10.1 Routes

```
/admin                      → Dashboard (status + AI alerts + quick actions)
/admin/members              → Member roster + cert import
/admin/rules                → Per-position rule editor
/admin/positions            → Position template editor (clone from prior year)
/admin/bid-session/new      → Configure + start a new session
/admin/bid                  → Live console (active bid)
/admin/audit                → Audit log viewer + chain verifier
/admin/exports              → Generated PDFs / CSVs
```

### 10.2 Dashboard

```
┌─────────────────────────────────────────────────────────────────────────┐
│ ADMIN — MBFD Bid 2026                       Chief: D. Abello [Logout]   │ ← slate-850
├─────────────────────────────────────────────────────────────────────────┤
│ STATUS:  ● LIVE  Phase 1: Position Bid           Started 09:00          │
│ 74 / 230 picks complete   ETA 14:25   Avg turn: 02:18                   │
│                                                                          │
│ ┌──────────────────────────────┐  ┌──────────────────────────────────┐  │
│ │ CURRENT BIDDER (#74)         │  │ AI ADVISORY (Sonnet, 1.2s)       │  │
│ │ Garcia, G    Lt  OFC         │  │                                   │  │
│ │ Eligibility: 18 positions    │  │ Recommended action: NONE          │  │
│ │ Recommended (points):        │  │ Eligibility nominal.              │  │
│ │  1. Sta 3 Rescue 3 Lt        │  │                                   │  │
│ │  2. Sta 4 Rescue 4 Lt        │  │ FORECAST                          │  │
│ │  3. Sta 1 Rescue 1 Lt        │  │ ⚠ 4 picks until Air Tech (XX203) │  │
│ │ Time remaining: 00:43        │  │   needs a qualified bidder.       │  │
│ │                              │  │ Last 3 AT-qualified in queue:     │  │
│ │ [Pause] [Force] [Skip]       │  │   #87 Frazier, #103 Mederos,      │  │
│ │ [Bid for member]             │  │   #114 Vinuela. If Frazier picks │  │
│ │                              │  │   elsewhere, AT will need force.  │  │
│ └──────────────────────────────┘  └──────────────────────────────────┘  │
│                                                                          │
│ Quick filters: [show stuck (>3min)]  [show forced]  [show skipped]      │
└─────────────────────────────────────────────────────────────────────────┘
```

### 10.3 Member/cert import (`/admin/members`)

- Drag-drop CSV upload (members) or PDF upload (credentials)
- Worker parses, validates against Zod schema, returns row-by-row diff
- Admin reviews → applies → audit_log entry

CSV schemas defined in §13.4 of the build plan.

### 10.4 Rule editor (`/admin/rules`)

Tree view: `[Bid Year 2026] → [Rule Book v2026.1] → [Position XX212 Capt 5]`
shows the JSON-backed `required_criteria`, `points_preference`, `tie_break_chain`
in a structured form editor. Every edit requires step-up auth + reason
text + writes to audit_log.

### 10.5 Live bid console (`/admin/bid`)

Same layout as the member draft view PLUS:
- Side panel: AI advisory (always visible, auto-refreshes per turn)
- Top action bar: Pause / Resume / Force / Skip / Bid-for-member
- Right rail: Recent audit events (last 20, live)
- Disagree-with-AI marker on any forced action whose AI dissent flag is set

### 10.6 AI advisory panel detail

```
┌──────────────────────────────────────────────────────────┐
│ AI ADVISORY                              Sonnet · 1.2s   │
│                                                           │
│ For #74 Garcia, G (Lt — OFC):                            │
│                                                           │
│ ✓ ELIGIBLE: 18 positions across A/B/C shifts             │
│   Highest-point matches (5+ pts):                         │
│     Sta 3 Rescue 3 Lt — 12 pts                            │
│     Sta 4 Rescue 4 Lt — 11 pts                            │
│     Sta 1 Rescue 1 Lt — 10 pts                            │
│                                                           │
│ ✗ INELIGIBLE: 6 positions                                 │
│   Sta 2 Rescue 2 Lt — missing Paramedic + 6 Ops gate     │
│   Float Cpt — wrong rank (needs Captain)                  │
│   …                                                       │
│                                                           │
│ FORECAST after this pick:                                 │
│   ⚠ Air Tech (3 shifts) — only 4 AT-qualified left in    │
│      queue. If first 2 don't choose AT, force window      │
│      opens at pick #105.                                  │
│   ⚠ Marine Deckhand (Station 6, all 3 shifts) — 7        │
│      MMC-qualified FFs left; nominal cushion.             │
│                                                           │
│ [Ask deeper question]  [Pre-fetch on-deck advisory]      │
└──────────────────────────────────────────────────────────┘
```

### 10.7 Audit log viewer

- Reverse-chrono table, filterable by actor, action, target, ai_advisory_id
- Chain-verify button calls `/api/admin/audit/verify-chain`; renders ✓ or ✗ per chunk
- Export to CSV → signed R2 URL

---

## 11. AI Integration

### 11.1 Two model tiers, two paths

| Tier | Model | Used for | Latency budget |
|------|-------|----------|----------------|
| Hot | **claude-sonnet-4-6** | Per-turn advisory, eligibility narratives, force-check dissent | <2s |
| Deep | **claude-opus-4-7** | Admin "Ask deeper question", scenario simulation, post-bid analysis | <8s |

### 11.2 Eligibility is NOT an AI call

This is the key architectural call. Eligibility = `required_criteria.satisfied_by(member)`
+ `points_preference.score(member)` + `tie_break_chain.compare(...)`. All
deterministic. We compute this in TypeScript inside the DO + Worker.

Eligibility output (per member, per position) is a struct:
```ts
{
  eligible: boolean,
  reasons_eligible: string[],         // ["required:rank=LT", "required:paramedic"]
  reasons_ineligible: string[],       // ["missing:paramedic", "operations gate not met"]
  points: number,
  so_points: number,
  mo_points: number,
}
```

This struct is what the AI receives in its prompt — it doesn't recompute,
it explains and forecasts.

### 11.3 Prompt structure

```
SYSTEM (cached, ~10K tokens, refreshed yearly):
  - Bid mechanics primer (markdown of 2026_Bid_Process.md)
  - Rule book (markdown of 2026_Rules_and_Points.md)
  - Position template (markdown of 2026_Position_Template.md)
  - Output format spec: must return JSON matching Advisory schema

USER MESSAGE BLOCK 1 (cached, refreshed per session start, ~30K tokens):
  - Full member roster (~280) with rank, seniority, certs, prior-year bid
  - Full eligibility matrix (precomputed)

USER MESSAGE BLOCK 2 (uncached, per turn, ~2K tokens):
  - Current bid state: phase, current_bidder_id, queue, position_fills
  - "Question": "Advise on member <id>'s upcoming pick" OR free-form admin Q

EXPECTED OUTPUT (structured JSON):
  {
    "summary": "1-2 sentence overview",
    "eligible_recommendations": [ { "position_id", "points", "why" } ],
    "ineligible_top_picks": [ { "position_id", "why_ineligible" } ],
    "forecast": {
      "warnings": [ { "level": "info|warn|critical", "text", "affected_positions" } ]
    },
    "force_recommended": boolean,
    "force_reasoning"?: string
  }
```

### 11.4 Caching strategy

Anthropic's prompt caching:
- **Breakpoint 1**: after system message → rulebook cached for the year
- **Breakpoint 2**: after USER BLOCK 1 → roster + eligibility cached for the session

Expected cost profile per bid event:
- System + roster: 1 cache write (~$0.50)
- Per turn: ~2K input tokens uncached + ~1K output → ~$0.02/turn
- ~250 turns → **<$10 total** in Anthropic costs for the whole event

### 11.5 When the AI is invoked

| Trigger | Model | Async? |
|---------|-------|--------|
| Member becomes the current bidder | Sonnet | Sync (must be visible on admin panel within 2s) |
| Member on-deck (next in queue) | Sonnet | Async pre-fetch into KV; served from cache when their turn arrives |
| Admin clicks "Ask deeper question" | Opus | Sync |
| Every 10 picks | Sonnet | Async — periodic forecast refresh |
| Admin clicks Force/Skip | Sonnet | Sync — dissent check; logged whether or not AI agrees |
| Member tries to pick a position the system says is ineligible | (none) | UI shows deterministic reason; no AI call |

### 11.6 What about hallucination?

- The AI never invents an eligibility decision — it explains the deterministic struct it received.
- The AI's `force_recommended` flag is a recommendation; the deterministic engine independently flags when force is mathematically required (last N qualified bidders for a slot).
- If AI output JSON fails Zod parse → fall back to deterministic-only display + log the parse failure.

---

## 11.7 Multi-Day Session Handling (the bid is rarely one-day)

The bid is run live in front of the membership for as long as feasible each
day (often 6–10 hours) and **paused at the chief's discretion** when energy
flags or schedules require. Typical bids span **1–3 calendar days**. The app
must persist state seamlessly across overnight pauses.

### 11.7.1 Day-end / Day-start primitives

Two new admin actions on top of pause/resume:

| Endpoint | Body | Effect |
|----------|------|--------|
| `POST /api/admin/bid-session/:id/day-end` | `{ resume_at?: ISO datetime, reason }` | Sets `current_phase = paused`; sets `bid_sessions.scheduled_resume_at`; broadcasts `day_ended { resume_at }` to all clients; persists DO storage; takes a forced `bid_session_snapshots` row marked `day_end_snapshot=true`; uploads a day-end audit JSONL chunk to R2 |
| `POST /api/admin/bid-session/:id/day-start` | — | Validates session in `paused`; restores DO from storage + verifies against latest snapshot; broadcasts `day_started`; current_phase ← previous phase; turn_started_at_ms ← `now` (member's timer restarts fresh) |

These are first-class actions (not "just" pause/resume) so audit reports can
clearly segment pea-day activity.

### 11.7.2 Member experience across days

When `day_ended` broadcast arrives:
- The Your-Turn modal closes (if open) with toast "Bid paused for today, resuming HH:MM tomorrow"
- Draft view stays loaded but switches to a **frozen-state read-only view**: all picks made today are shown; current-bidder banner shows "Paused — resumes HH:MM"
- Members can sign out and return the next day

When member returns next day (post-`day_started`):
- PIN gate already passed if cookie still valid (7-day TTL)
- JWT expired (8h TTL) → silent redirect to `/login`
- After login, lands directly on `/draft` and is reconnected to the live DO session
- Their pick (if made yesteaday) is visible on their Profile card

### 11.7.3 Implications on state design

Already covered by the existing design — multi-day is essentially N consecutive
paused intervals, and **all the persistence guarantees already in §5.3–5.4
apply unchanged**:
- DO storage holds canonical state across day boundaries (Cloudflare guarantees DO storage durability indefinitely)
- D1 + R2 audit log records every action including `day_end` / `day_start`
- The `bid_session_snapshots` shadow replicas continue every 5s while bid is live; once `paused`, a single snapshot at day-end is sufficient

One config change worth noting:

| Setting | Default | Notes |
|---------|---------|-------|
| `bid_sessions.expected_duration_days` | 2 | Admin sets at session create; AI uses this to pace forecasts |
| Cookie TTL `mbfd_pin` | 7 days | survives a multi-day event without re-PIN-ing |
| JWT TTL `mbfd_bid_jwt` | 8 hours | members re-login each day; admins re-login + step-up before every override |

### 11.7.4 Each pick is durably saved (no "lost progress" risk)

A pick is durable the instant it commits because the DO writes to D1 + R2
audit log inline with the broadcast (see §5.3 step 5). Even if every other
system fails, the JSONL chunk in R2 — hash-chained and signed — is the legal
record of every pick made. Multi-day pauses introduce no new failure modes.

---

## 11.8 Employee Portal Write-Back (action card on member profile)

The bid app **pushes** every confirmed pick to the existing MBFD Employee
Portal so each member's portal profile (alongside uniform selections and
bunker gear info) gains a **Bid Assignment action card**.

### 11.8.1 Card content (per user spec)

```
┌────────────────────────────────────────────────┐
│  2026 SHIFT BID ASSIGNMENT                      │
├────────────────────────────────────────────────┤
│  Name:           Peter Darley                   │
│  Position:       Lieutenant                     │
│  Station:        Station 1                      │
│  Shift:          A Shift                        │
│  Unit Bid:       Rescue 1                       │
│  A-Day Group:    Group 4   (pending Phase 2 …) │
│  Picked:         2026-09-22 14:23               │
└────────────────────────────────────────────────┘
```

### 11.8.2 Field derivation rules

| Card field | Source | Example |
|------------|--------|---------|
| Name | Portal-side from `members` table | "Peter Darley" |
| Position | Bid app sends `positions.rank_required` mapped to label | LT → "Lieutenant" |
| Station | Bid app sends `positions.station`, label-prefixed with "Station " | "Station 1" — *exception: Rescue Float Pool sends "Float", Union President sends "Union President"* |
| Shift | Bid app sends `positions.shift` + " Shift" | "A Shift", "B Shift", "C Shift", "D Shift (Days)" |
| Unit Bid | Bid app sends `positions.unit` verbatim | "Rescue 1", "Engine 4", "Fire Boat", "Float 2" |
| A-Day Group | Bid app sends `bids.a_day` once Phase 2 complete; else "Pending Phase 2" | "Group 4", "Friday" (D-shift), or "Pending Phase 2" |
| Picked timestamp | Bid app sends `bids.picked_at` | ISO 8601 |

### 11.8.3 API contract (bid app → portal, outbound)

**Endpoint** (portal-side, to be built/exposed by the portal team in Phase 0):

```
POST https://portal.mbfdhub.com/api/v2/members/:employee_id/bid-assignment
Authorization: Bearer <service-account-token-bid-writer>
Content-Type: application/json

{
  "bid_year": 2026,
  "bid_session_id": "01HF3...",
  "rank_label": "Lieutenant",
  "station_label": "Station 1",
  "shift_label": "A Shift",
  "unit_label": "Rescue 1",
  "a_day_label": "Group 4" | "Pending Phase 2",
  "position_id": "A109",
  "picked_at": "2026-09-22T14:23:00-04:00",
  "idempotency_key": "<bids.id>",
  "is_forced": false,
  "admin_actor_employee_id": null
}

Response: 200 OK { stored: true, card_url: "...". }
         | 409   { reason: "already_recorded" }    ← idempotent
         | 5xx   { reason: ... }                   ← queued for retry
```

The portal team owns the read-side (rendering the card on the existing
profile page near uniform/bunker info).

### 11.8.4 Write-back mechanics in the bid app

```
On pick committed (DO step 6 in §5.3):
  1. Enqueue portal_writeback message to Cloudflare Queue `portal-writebacks`
  2. Continue broadcasting to WS clients (the pick is durable in D1+R2 regardless)
  3. Consumer Worker reads queue, POSTs to portal
     - On 200 / 409: mark bids.portal_sync_status = "synced"
     - On 5xx / timeout: retry with exp backoff up to 24 attempts over 24h
     - After 24h: mark bids.portal_sync_status = "failed"; alert admin
```

### 11.8.5 Failure handling + reconciliation

| Scenario | Handling |
|----------|----------|
| Portal down during bid | Picks still commit; queue absorbs them; replays on portal recovery |
| Portal returns 409 | Already recorded — log + mark synced; idempotency working |
| Pick later REVERSED (admin uses force-pick to override an earlier pick) | New POST to portal **supersedes** the prior assignment; portal must show only the latest per (bid_year, employee_id) |
| Bid event cancelled / re-run | Admin endpoint `POST /api/admin/portal-clear-year` removes all assignments for a bid_year before re-run |

### 11.8.6 Schema additions for write-back

```ts
// Adds to existing bids table:
bids: {
  …,
  portal_sync_status: enum('pending','synced','failed','superseded') default 'pending',
  portal_synced_at: timestamp nullable,
  portal_sync_attempts: integer default 0,
  portal_last_error: text nullable,
}

// New table:
portal_writeback_queue: {
  id: text primary key,
  bid_id: text references bids,
  enqueued_at: timestamp,
  next_attempt_at: timestamp,
  attempts: integer,
  status: enum('queued','in_flight','done','failed'),
  payload_json: text,
  last_error: text nullable,
}
```

### 11.8.7 Admin visibility

- `/admin/exports` page shows portal sync status per pick: 5 columns (Member, Position, Picked, Portal Status, Last Attempt)
- Failed syncs surface a banner on the admin dashboard: "12 picks pending portal sync — investigate"
- Manual retry button per row

---

## 12. Security Model

### 12.1 Trust boundaries

| Boundary | Trust | Verification |
|----------|-------|--------------|
| Public internet → Cloudflare | low | TLS, WAF, rate-limit, PIN gate |
| Logged-in member | medium | JWT, role=member, can only act on self |
| Admin | high | JWT, role=admin, step-up for writes |
| BidSession DO ↔ Worker | high | Same Cloudflare account, internal |
| Worker ↔ Anthropic | medium | Service token in env, never client-visible |
| Worker ↔ Employee Portal | medium | Service-account bearer token |

### 12.2 Required mitigations

- **PIN gate** before login form is rendered
- **JWT (8h TTL)** with `fresh_auth_at` claim
- **Step-up re-auth** on admin writes (`fresh_auth_at` <5 min)
- **Reason code** (enum) + free-text justification on every admin write
- **AI dissent log** entry whenever AI's `force_recommended` ≠ admin action
- **Dual-chief mode** (optional, configurable): high-impact overrides require a second admin's confirm within 60s
- **Hash-chained R2 audit** — tamper-evident
- **Rate limiting** per-IP and per-member on WS submit_pick and REST writes
- **CSRF**: SameSite=strict cookies; double-submit token on POST
- **Idempotency keys** on every state-changing client request
- **Logged IP hash + UA** on every auth event (no raw IP stored after 30 days)
- **Scoped secrets**: per-env (dev/staging/prod) Wrangler secrets; no plaintext keys in repo
- **Backup**: D1 daily snapshot to R2; admin can restore via Wrangler

### 12.3 Threat model (most likely → least likely)

| Threat | Likelihood | Mitigation |
|--------|-----------|------------|
| Public discovers URL pre-event | high | PIN gate, no public links, robots.txt disallow |
| Member tries to pick another member's slot | medium | JWT.sub == current_bidder check, server-side |
| Member tries an ineligible position | medium | Deterministic engine rejects, displays reason |
| Member duplicates a pick (network retry) | medium | Idempotency key |
| Admin makes contested override | medium | Step-up + reason + AI dissent log + dual-chief mode |
| DO crashes mid-bid | low | DO storage persistence + 5s shadow snapshot + RESYNC protocol |
| D1 regional outage | low | R2 JSONL is canonical record; D1 replayable from R2 |
| Compromised admin credentials | low | Step-up auth, audit log, hash chain |
| Insider edits audit log post-hoc | very low | Hash chain detects tampering; signing key in env |

---

## 13. Phased Build Plan

> **Constraint**: bid event date is the hard deadline. Suggest target dates after
> user confirms event date. All time estimates are calendar weeks for a single
> full-time developer; parallel development with subagent help would compress.

### Build Phase 0 — Pre-work (1 week)
- Confirm event date(s) with chiefs (single day vs multi-day expectation; default `expected_duration_days = 2`)
- **Confirm portal `/verify-credentials` API contract** (inbound auth)
- **Confirm portal `/api/v2/members/:employee_id/bid-assignment` API contract** (outbound write-back; portal team builds the read-side action card on the existing member profile near uniform/bunker info)
- Issue & store **two** portal service-account tokens: `bid-reader` (for auth verify) and `bid-writer` (for bid-assignment writes)
- Confirm `bid.mbfdhub.com` DNS, Cloudflare account access
- Lock JWT signing key + PIN secret + portal tokens in Wrangler env
- Bidder-unreachable protocol — RESOLVED per §14 D1, documented in admin runbook

### Build Phase 1 — Foundation (2 weeks)
- Repo scaffolding: pnpm monorepo (apps/web, apps/worker, packages/shared)
- Next.js 15 + shadcn + Tailwind + design tokens from `.impeccable.md`
- Hono Worker + Drizzle on D1 + initial migrations
- PIN gate Worker middleware
- Employee portal mock + auth flow + JWT issuance
- Basic CI: Vitest + Playwright + wrangler deploy to staging

### Build Phase 2 — Data plane (2 weeks)
- Schema + migrations for `members`, `credentials`, `member_credentials`,
  `positions`, `position_rules`, `bid_years`, `bid_sessions`, `audit_log`
- CSV import for members + cert PDF parse pipeline (Python R2 batch job is fine for v1)
- Rule editor MVP (read-only)
- Member list + member detail page (read-only)

### Build Phase 3 — Eligibility engine (1.5 weeks)
- Deterministic rule evaluator (TypeScript) — full unit test coverage
- Eligibility bitmap computation
- Tie-break chain implementation
- Validation against 2025 actual bid data (golden test)

### Build Phase 4 — Live bid core (3 weeks)
- BidSession Durable Object
- WebSocket fanout
- Pick submission flow (member self-bid)
- DO storage persistence + RESYNC protocol
- Shadow snapshot Workflow
- Draft view, desktop + mobile
- Your-Turn modal
- Order sidebar
- Profile card with 2025↔2026 switch
- Shift toggle
- Bid Phase 1 (position bid) only — A-Day picker comes in Build Phase 7

### Build Phase 5 — Admin console (2 weeks)
- Admin dashboard
- Live console with all controls (Pause/Resume/Force/Skip/Bid-for-member, **Day-End/Day-Start**)
- **Timer config**: pre-bid in session config screen + live adjustment endpoint
  `PATCH /api/admin/bid-session/:id/config { turn_timer_seconds }` (step-up auth)
- Step-up auth + reason codes
- Audit log viewer + chain verifier
- Member/cert/rule editors live + audited
- Position-lock pre-bid feature
- Bidder-timer-expired banner with two actions (Pause / Force-pick after 2× timer)

### Build Phase 6 — AI integration (1.5 weeks)
- AI Gateway → Anthropic wiring + prompt cache breakpoints
- Sonnet hot-path turn advisory
- Opus deep-dive endpoint
- On-deck pre-fetch into KV
- Dissent log integration with admin Force/Skip
- Forecast generator (every 10 picks)

### Build Phase 7 — A-Day Bid (the bid's Phase 2) (1 week)
- A-Day phase state machine (sequential per user choice)
- A-Day picker UI for members
- Group capacity tracker (5 Officers / group invariant enforced)
- Admin A-Day overrides

### Build Phase 8 — Audit + Exports + Portal Write-Back (1.5 weeks)
- R2 hash-chained JSONL writer
- Audit chain verifier endpoint
- PDF generation via **Browserless** (Cloudflare-friendly hosted Chrome) — chosen over self-hosting Puppeteer-on-Workers because PDF generation runs <50× per event (post-bid roster export) and Browserless free tier covers it
- CSV export to signed R2 URLs
- **Cloudflare Queue `portal-writebacks` + Consumer Worker** — outbound POST to employee portal for the bid-assignment action card; retry-on-failure with exp backoff up to 24h; admin visibility of sync status
- Reconciliation endpoint `POST /api/admin/portal-resync-pending` (manual force retry of all failed writes)

### Build Phase 9 — Hardening + Rehearsal (2 weeks)
- Pen test (internal)
- Load test (simulated 280 concurrent clients on staging DO)
- "Kill the DO" drill in staging
- Full mock bid with 10 volunteers
- Documentation: admin runbook, member quickstart, on-call rotation

### Build Phase 10 — Live event (event day + 1)
- Real bid runs
- Post-event audit export
- Retrospective

**Total: ~16 weeks for v1**, compressible to ~10 weeks with parallel subagent
development on independent layers (UI ‖ rules engine ‖ AI ‖ admin).

---

## 14. Open Decisions / Questions

| # | Decision | Why it matters | Owner | Default if not answered |
|---|----------|----------------|-------|--------------------------|
| D1 | Bidder-unreachable protocol (member's phone drops mid-turn) | Affects timer semantics, force semantics, labor relations | **RESOLVED 2026-05-17** | **Per-member timer runs for `turn_timer_seconds` (default 180 = 3 min, admin-adjustable both in session config AND live during the bid). On expiry: admin sees a banner with TWO actions — (a) PAUSE the bid (default — admin investigates, calls the member, resumes when ready) OR (b) FORCE-PICK with mandatory reason (permitted only after 2× the per-member timer has elapsed since turn started; AI dissent log entry created).** |
| D2 | Dual-chief approval mode default | Reduces single-chief liability | Chiefs | Off by default, configurable |
| D3 | Public read-only viewer during event | Optics; transparency | Chiefs | Off in v1 |
| D4 | PDF generation provider | Affects Build Phase 8 estimate | Dev | **Decided: Browserless** (~$50/mo; <50 PDFs/event so free tier may suffice) |
| D5 | Use Cloudflare Queues for D1 audit writes? | Decouples critical path from DB latency | Dev | Skip in v1; D1 writes are <50ms |
| D6 | Probationary 25 FFs + 1 SWAT Medic + Paramedic students — auto-place pre-bid? | Match 2025 behavior | Chiefs | Auto-place via `lock-position` admin action with reason code |
| D7 | What happens if member loses both Phone and phone-tree backup? | Labor relations | Chiefs + Union | Same as D1; depends on union contract |
| D8 | AI advisory always-on vs admin-toggle? | Cost + cognitive load | Chiefs | Always-on; can mute in UI |
| D9 | When does the credential PDF get re-parsed? Per session start? Or on demand? | Stale data risk | Dev | Per session start (admin clicks "refresh creds") |
| D10 | Bid year 2026 = clone of 2025 with §13 deltas? Or built fresh? | Saves admin work | Chiefs | Clone 2025, admin applies delta in `/admin/positions` and `/admin/rules` |

**The most important open item is D1** — needs labor relations alignment before
any production code is written. The default I've proposed should be validated
with both chiefs and the union.

---

## 15. Risk Register

| Risk | Severity | Likelihood | Mitigation | Owner |
|------|----------|------------|------------|-------|
| Bidder-unreachable protocol not pre-agreed → labor grievance | **Critical** | Medium | D1 resolution; documented in admin runbook; consent form for members | Chiefs |
| Durable Object crash during live event | High | Low | DO storage persistence + shadow snapshot + drill | Dev |
| Employee portal API down during event | High | Low | Cached JWT keeps live members logged in 8h; new logins blocked; admin can manually issue token | Dev |
| D1 regional outage during event | High | Low | R2 JSONL canonical; replay-on-recovery script ready | Dev |
| AI gives incorrect advisory leading admin to wrong force | Medium | Low | AI is advisory only; deterministic engine independently flags force-required; chiefs trained that AI is a tool, not the decision | Chiefs |
| Member uses old browser without WebSocket | Medium | Low | Long-polling fallback (slow but works); UA detection on login | Dev |
| Admin makes contested forced pick | Medium | Medium | Step-up auth + AI dissent log + dual-chief mode option | Chiefs |
| Schema needs change mid-event (rare rule clarification) | Low | Medium | Rule overrides via `rule_overrides` admin endpoint, audited | Dev |
| AI cost overrun | Low | Low | Prompt caching + Sonnet hot-path + hard daily budget | Dev |

---

## 16. What's NOT in v1

Explicitly deferred to v1.1+:
- Public read-only board viewer
- Email/SMS notifications ("your turn in 5 min")
- iCal export for assignments
- Multi-language support
- Members can pre-rank preferences (auto-pick if their turn passes)
- "What-if" simulator for admin (what happens if I force X into Y?)
- Mobile app (React Native) — web is sufficient
- Integration with payroll/scheduling system (manual export to CSV → upload to existing systems)
- Historical bid data import (only 2025 baseline for the comparison feature)

---

## 17. Appendix — Mockup specifics

### A. Color application summary (from `.impeccable.md`)

| UI Element | Color |
|------------|-------|
| Page bg | `bg-stone-50` |
| Card bg | `bg-white` |
| Card border | `border-stone-200` |
| Card hover border | `hover:border-red-200` |
| Slate-850 header (admin authority) | `bg-slate-850 text-white` |
| Primary CTA / current bidder / brand | `bg-red-700 text-white`; hover `bg-red-600` |
| Status: open / available | `bg-stone-50 border-stone-200` |
| Status: filled | `bg-white border-stone-200` with member chip |
| Status: ineligible | `bg-stone-100 text-stone-400` cursor-not-allowed |
| Status: vacant by design | `bg-stone-100 text-stone-400` diagonal-stripe pattern |
| Status: locked pre-bid | `bg-stone-100 text-stone-700` 🔒 icon |
| Numeric (time, points, seniority) | `font-variant-numeric: tabular-nums` |
| Timer bar | `bg-red-700` filling, `bg-stone-100` track |
| Forecast WARN | `bg-amber-50 text-amber-700` |
| Forecast CRITICAL | `bg-red-50 text-red-700` |

### B. Typography application

| Element | Font + weight |
|---------|---------------|
| Page H1 | `Plus Jakarta Sans 700`, `clamp(1.75rem, 3vw, 2.5rem)` |
| Section H2 | `Plus Jakarta Sans 600` |
| Body | `Source Sans 3 400` |
| Tabular numbers (time, points, IDs) | `Source Sans 3 400 + tabular-nums` |
| Position IDs (A101, B309) | `JetBrains Mono 400` |
| Buttons | `Source Sans 3 600` |

### C. Motion summary

| Motion | Duration | Easing |
|--------|----------|--------|
| Modal in/out | 200ms | `cubic-bezier(0, 0, 0.2, 1)` |
| Position cell hover | 150ms | `cubic-bezier(0, 0, 0.2, 1)` |
| New pick reveal (other clients) | 300ms staggered | `ease-out` |
| Timer tick | 1s linear interval | (no animation, just text update) |
| Toast undo | 5s persistent + 200ms fade | `ease-out` |

All gated by `prefers-reduced-motion`.

### D. Accessibility

- ARIA-live region for "new pick made" announcements
- Focus-visible rings on every interactive element (red-700, 2px offset)
- Tab order: Order sidebar → Station board → Profile card → Modal
- Min 44×44px touch targets on mobile
- Color contrast: all text passes WCAG AA on its bg
- Screen-reader-only labels on icon-only buttons
- Reduced-motion = no transitions, opacity 1 directly

---

**End of Design Spec — awaiting user review before implementation plan.**
