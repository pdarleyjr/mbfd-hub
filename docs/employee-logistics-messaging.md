# Employee ↔ Logistics Messaging

**Status:** implemented on `feat/employee-logistics-messaging-20261029` (base `main` 3b93e4db).
**Release lane:** Amber (new draft-bearing form; additive schema; no auth, worker or manifest change).

A private, in-Hub way for a member to message the Logistics team and for authorized Logistics
administrators to read and reply. It is **not** email, a chat room, a support ticket or an
inspection-issue thread, and it shares no table, route, permission or inbox with any of them
(Inbound/Outbound/Compose Email, 300 Command Communications, Hub Support Tickets, Daily Checkout
issue messages, personnel/station/uniform/EMS requests).

## 1. Surfaces

| Where | What | URL |
|---|---|---|
| Home (`/`) | **Message Logistics** button beside **Vehicle Checkout** (same `$showEmployeePortal` rule) | links to `/employee/messages?compose=1` |
| Employee Portal | **My Messages** page, nav group *My work* (sort 5); dashboard card | `/employee/messages`, `?compose=1`, `?thread={id}` |
| Admin Panel | **Employee Messages** inbox, nav group *Communications* (sort 35), unread badge | `/admin/employee-messages`, `?thread={id}` |
| Logistics Dashboard | **Employee Messages** card, full width, after the Station Operations board; hidden in `?display=1` | `/admin` |

## 2. Data (`2026_10_29_120000_create_employee_logistics_messaging_tables`)

`employee_logistics_threads` — one conversation per row, owned by `member_user_id` (canonical
`users.id`), with a snapshot of the member's name / employee number / rank / station
(`employee_profile_id` kept as a stable optional link). Server-maintained summary columns:
`subject`, `last_message_preview`, `last_message_side`, `last_message_at`, `message_count`,
`last_member_message_id`, `last_logistics_message_id`.

`employee_logistics_messages` — append-only, plain UTF-8 text (≤ 5,000 characters), `author_side`
(`member` | `logistics`, set by the server from the path used), `author_user_id`, `author_name`
snapshot, `client_request_id` with **unique (`author_user_id`, `client_request_id`)**.

Both tables are additive; prior images ignore them. `down()` refuses to run outside local/testing.
Code rollback never removes conversations; never restore an older database to "undo" messages.

## 3. Authorization (`App\Services\Security\EmployeeLogisticsMessagingAccess`)

| Actor | Member inbox | Logistics inbox (view) | Reply as Logistics |
|---|---|---|---|
| Active account, no pending password change, current linked personnel profile | own threads only | – | – |
| super_admin (with an active account) | own threads only | ✓ | ✓ |
| Admin Panel entitlement + `logistics_admin` role | own threads only | ✓ | ✓ |
| Admin Panel entitlement + direct `admin.employee_messages.reply` | own threads only | ✓ | ✓ |
| Admin Panel entitlement + direct `admin.employee_messages.view` | own threads only | ✓ | ✗ |
| Any other admin (incl. `admin.communications.*` only), display devices, guests | own threads only / none | ✗ | ✗ |

* The migration **creates** the two permissions and grants them to nobody (deploy gate: no role or
  permission change on established accounts). Additional responders are granted in the Admin
  capability editor (audited, password-confirmed): *Employee messages — view the Logistics inbox* /
  *Employee messages — view and reply as Logistics*.
* Every service method re-checks access with the signed-in actor. Member reads are scoped to
  `member_user_id = actor`; a thread outside scope is *not found*. Browser input never supplies
  owner, author or side.
* Sends are rate limited per user (members 10/min, Logistics 30/min).

## 4. Service (`App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService`)

`startThread`, `replyAsMember`, `memberThreads`, `memberThread`, `memberUnreadCount`,
`markReadByMember`, `replyAsLogistics`, `logisticsThreads(filter: all|unread|awaiting, search)`,
`logisticsThread`, `logisticsUnreadCount`, `markReadByLogistics`, `messagesFor` (newest 200, oldest
first). Lists page 20 at a time ("Show more" raises the window).

**Idempotency:** a retry with the same `client_request_id` returns the original message; the same id
with different text (or another thread) is refused with a clear error. Concurrent duplicates are
resolved by the unique index. Replies lock the thread row, so per-thread message ids are in commit
order.

**Read markers are message ids** (no timestamp precision races):

* unread for the member ⇔ `last_logistics_message_id > member_read_message_id`
* unread for Logistics (shared team marker) ⇔ `last_member_message_id > logistics_read_message_id`
* A marker only moves forward, only to the newest message actually rendered to an authorized viewer
  (`seenUpTo`), and only when a thread is opened (member page, Logistics inbox, or a card row
  expanded). Lists, badges, polls and the dashboard preview never move markers. An author's own
  messages never count as unread for them.

## 5. Drafts, polling and PWA updates

* The composer is an Alpine component inside `wire:ignore`; Livewire polls (30 s, visible tabs only)
  can never reset typed text or the selection.
* While the composer holds text, is sending, or holds a failed send, it registers the busy reason
  `employee-message:<id>` in the shared `window.__mbfdBusy` registry and dispatches
  `mbfd:busy-change`, so neither the automatic nor the **manual** "Refresh now" path reloads the page.
  Text is cleared and the reason released only after the server confirms the message was saved.
* Offline: the send button reports "You're offline …" and keeps the draft; nothing is ever shown as
  sent without a server confirmation. A timed-out send keeps the draft and the same request id, so a
  retry cannot create a duplicate.
* The composer is inline Blade/Alpine (no new lazily loaded JS chunk).
* Message pages and the Livewire responses are authenticated HTML; service workers do not cache
  them (no new allowlist entry).

## 6. Release and rollback

Standard immutable-image release. Additive migration; an earlier image runs unchanged with the new
tables present. Rollback = redeploy the prior image digest; conversations stay. Post-deploy: the
deploy smoke now also checks `/app-release` (no-store) and every current/legacy service-worker URL.

## 7. Verification

* PHP: `php artisan test --filter=EmployeeLogistics` (service, access matrix, idempotency and races,
  read markers, search/filter, permission provisioning, query counts, member portal, admin inbox and
  Logistics Dashboard card).
* Browser: `npm run test:employee-messages-e2e` (Chromium desktop 1440 + mobile 375; synthetic
  personas from `EmployeeMessagesE2ESeeder`, testing/sqlite only). Runs in the required release gate
  (`daily-authenticated-lifecycles`) next to `test:pwa-update-e2e` and `test:daily-pwa-upgrade-e2e`.
* A reply names the conversation its box was drawn for; if the page switched conversations while it
  was being sent, the server refuses it ("Your message was not sent and is still here").
