# MBFD Hub Email Center: architecture

The Email Center is one Filament page at `/admin/email`. It is a client over the mail the Hub already
receives and sends. It adds no mail transport, Worker, queue system, database or authentication.

```
Cloudflare Email Routing -> cloudflare-email-worker -> POST /api/v2/email/inbound -> inbound_emails
Email Center / notifications -> CloudflareEmailDispatcher -> Cloudflare Email Sending -> outbound_emails
Cloudflare GraphQL analytics -> CloudflareDeliveryReconciler -> outbound_email_recipients + delivery events
inbound_emails + outbound_emails -> MailThreadIndexer -> mail_threads -> Email Center
```

`inbound_emails` and `outbound_emails` remain the authoritative copies of every message. The `mail_*`
tables hold only what those two cannot: conversation grouping and human state. No body is copied.

## Decisions

| Topic | Decision | Why |
|---|---|---|
| Worker | No change | It already forwards text, HTML, threading headers and attachments |
| Transport | `CloudflareEmailDispatcher` only | Keeps budget, ledger and duplicate protection in one place |
| Lifecycle events | Keep the GraphQL reconciler; do not add a Queue subscription | The reconciler already records per-recipient outcomes at this volume (about 100 messages in total). A second lifecycle path adds a Queue, a token and a consumer for no current gain |
| Threading | `in_reply_to` / `references` / parent foreign keys; never subject | Subject matching merges unrelated conversations |
| Trash and archive | State on `mail_threads` | TeleStaff cleanup hard-deletes `inbound_emails`; human actions must never touch those rows |
| Drafts | `mail_drafts`, never `outbound_emails` | That table is the send ledger and every row carries budget meaning |
| Search | `pg_trgm` GIN indexes on the existing columns, `LIKE` on SQLite | Avoids a second copy of message bodies |
| Rich text | Filament `RichEditor` (Trix, already in the stack) with a short toolbar | No new editor dependency |
| HTML viewing | Sanitized at ingest, shown only through a sandboxed iframe route | Received mail is untrusted; never `{!! !!}` in the page |
| Unread | Per user: `read_through_at` against `last_inbound_at` | The global `read_at` column can't represent a shared mailbox |
| Recipient limit | Effective limit per send is the smaller of the message cap and the per-minute cap | 6 to 10 recipients could never pass the 5-per-minute guard; the composer says so up front instead |

## Provider policy for the composer

Cloudflare documents Email Service as transactional only. The composer is for replies, forwards and
individual or small operational messages. It refuses more recipients than the effective limit and directs
department-wide messages to Department Updates. There is no unsubscribe machinery because there is no
subscription mail. This page is the policy; there is no per-message category picker.

## TeleStaff protection

Roster mail shares `inbound_emails`. These rules are enforced by tests:

- No `SoftDeletes` or global scope on `InboundEmail`; `InboundEmailResource::canDelete` stays false.
- Every new foreign key to `inbound_emails` or `outbound_emails` is cascade or null on delete.
- Columns the classifier and cleanup read are never changed after ingest, and `attachment_metadata` keeps its
  shape. Attachment files are never moved or rewritten.
- Indexing runs after commit and can never fail the inbound request. A sweep repairs anything it missed.
- A thread holding roster mail is `kind = automated`: out of the Inbox and unread counts, read-only in the
  Automated folder. Rejected and needs-review mail stays visible there and under Roster Imports.
- Human actions never create an `outbound_emails` row and never set `parent_inbound_email_id`.

## Data model (new tables only, except one nullable column)

- `mail_threads`: `thread_key` unique, `kind` (`conversation`, `automated`), `subject`, `snippet`,
  `participants` json, `message_count`, `has_attachments`, `first_message_at`, `last_message_at`,
  `last_inbound_at`, `unread_baseline_at`, `archived_at`, `trashed_at`, `snoozed_until`,
  `assigned_user_id`, `priority` (`normal`, `high`).
- `mail_thread_messages`: `thread_id`, `inbound_email_id`, `outbound_email_id` (each nullable, unique, cascade),
  `message_at`.
- `mail_thread_user_states`: `user_id`, `thread_id` unique pair, `read_through_at`, `starred_at`.
- `mail_labels`, `mail_thread_labels`.
- `mail_thread_notes`: internal only. No code path may read them when building an outbound message.
- `mail_thread_events`: `thread_id`, `user_id`, `type`, `metadata` json.
- `mail_drafts`: `user_id`, `thread_id`, `mode`, `source_kind`, `source_id`, `to`/`cc`/`bcc` json, `subject`,
  `body_html`, `version`, `state` (`open`, `sending`, `sent`, `unconfirmed`), `outbound_email_id`.
- `mail_draft_attachments`: `draft_id`, `filename`, `mime_type`, `size`, `disk`, `path`, `sha256`.
- `inbound_emails.skipped_attachments` (nullable json): parts not kept, so one unsupported inline image no
  longer bounces a whole message. `attachment_metadata` is untouched.

## Service contract

Namespace `App\Services\Mail`. Authorization lives here, not only in the views.

```php
MailAccess::canView(User): bool    // panel entitlement AND (admin.email.view OR legacy admin.communications.view)
MailAccess::canSend(User): bool    // canView AND (admin.email.send OR legacy admin.communications.send)
MailAccess::canManage(User): bool  // canView AND admin.email.manage

MailboxQuery::threads(User, MailboxFilter, ?string $cursor = null, int $perPage = 30): CursorPaginator
MailboxQuery::thread(User, int $threadId): MailThreadDetail
MailboxQuery::counts(User): array      // folder => count
MailboxQuery::unreadCount(User): int

MailboxActions::{markRead, markUnread, star, unstar, archive, unarchive, trash, restore}(User, MailThread): void
MailboxActions::assign(User, MailThread, ?User): void
MailboxActions::setPriority(User, MailThread, string): void
MailboxActions::snooze(User, MailThread, ?CarbonInterface): void
MailboxActions::{addLabel, removeLabel}(User, MailThread, string): void
MailboxActions::addNote(User, MailThread, string): MailThreadNote

MailDraftService::open(User, string $mode, ?int $threadId, ?string $sourceKind, ?int $sourceId): MailDraft
MailDraftService::save(User, MailDraft, array $fields, int $expectedVersion): MailDraft  // throws MailDraftConflict
MailDraftService::addAttachment(User, MailDraft, UploadedFile): MailDraftAttachment
MailDraftService::removeAttachment(User, MailDraftAttachment): void
MailDraftService::discard(User, MailDraft): void
MailDraftService::send(User, MailDraft): MailSendResult  // accepted | unconfirmed | deferred | refused

MailRecipientDirectory::suggest(User, string $query, int $limit = 8): array
MailHealthReport::summary(User): array
```

### Access levels (`MailAccess`)

Every level also requires a current admin-panel entitlement (`admin.access`, or `super_admin`, which passes every
level) on an active account that is not forced to change its password. The services and the two routes enforce
this server-side; the page only hides what is refused.

| Level | Permission | Allows |
|---|---|---|
| view | `admin.email.view` or legacy `admin.communications.view` | read folders, threads, bodies, attachments; per-user read state and stars |
| send | view AND (`admin.email.send` or legacy `admin.communications.send`) | compose, reply, forward; archive, assign, priority, snooze, labels, notes |
| manage | view AND `admin.email.manage` | the Trash folder, trash and restore |

Automated threads (roster mail, system notifications) refuse every action at every level, super administrators
included. Send alone or manage alone opens nothing: both include view.

Legacy fallback: the migration `2026_10_31_100400_create_email_permissions` creates the three `admin.email.*`
permissions and grants them to nobody, because the deploy gate refuses any role or permission change on
established accounts. Until an administrator grants them in the capability editor, everyone who could read or
send mail through `admin.communications.*` keeps exactly that. Only trash and restore need a new grant. Those two
legacy permissions keep their own meaning for Communications Usage, Sent and 300 Command Communications.

Folders: `inbox`, `assigned`, `starred`, `drafts`, `sent`, `automated`, `archive`, `trash`.

Routes, all behind admin authentication and `MailAccess`, all `no-store, private`:

- `GET /admin/email/messages/{kind}/{id}/body`: sanitized HTML with
  `Content-Security-Policy: sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'self'`.
  The document repeats the content rules in a meta tag, because the page's hidden height probe loads it without the header.
  Links carry `target="_blank" rel="noopener noreferrer"`, so they open in a new tab that is not sandboxed and cannot reach the
  Hub. Scripts and the Hub's origin are never granted (`allow-scripts` and `allow-same-origin` appear nowhere). The document has
  no closing `</html>` on purpose: Livewire only injects its scripts into a response that has one.
- `GET /admin/email/messages/{kind}/{id}/attachments/{index}`: stream, `attachment`, `nosniff`. Outbound sources whose content was never reviewed (`EmailConversation::canRespond`) keep their files private.

Rules worth keeping in mind:

- Roster mail is sealed whether or not it has a `roster_imports` row: the indexer also asks `TeleStaffMailClassifier`, the test the registrar itself uses. Reading it stays at view level, as on the older pages.
- New inbound mail brings a conversation back to the Inbox from Archive, Snooze or Trash. A person's reply must never land where nobody looks.
- The sweep's read baseline applies only the first time a message is placed. Re-checking indexed mail never marks it read.
- A definite provider refusal (a ledger row with no `accepted_at` and status `rejected` or `failed`) reopens the draft and says nothing was sent. Only an uncertain outcome locks a draft as unconfirmed.
- A `deliveryFailed` analytics event with `isLastEvent` false is `deferred`; only the last event can be a bounce.

## Delivery status wording

Shown as `Queued`, `Submitted`, `Accepted by Cloudflare`, `Deferred`, `Accepted by receiving server`, `Failed`,
`Rejected`, `Bounced`, `Suppressed` or `Unconfirmed`. Nothing in the product says "delivered to Inbox": no
signal available to the Hub can show which folder a message landed in.

## The page (UI)

`/admin/email` is `App\Filament\Admin\Pages\EmailCenter` (Communications > Email, sort 10, unread badge). It owns only the address
bar (`#[Url]`: `folder`, `thread`, `compose`, `draft`, `msg`, `q`, and the filters `unread`, `attachments`, `label`, `priority`) and
re-validates every value on mount and on every update, so a hand-edited link can never raise an error or open what access refuses.
`folder`, `thread`, `compose`, `draft` and `msg` push history entries (Back walks between conversations); search and filters replace.

Four Livewire components under `App\Livewire\Mail`, each re-checking `MailAccess` on every request:

| Component | Job |
|---|---|
| `MailboxList` | one folder, 30 conversations a page with a cursor per page; polls `$refresh` every 60 s while visible |
| `MessageReader` | the whole conversation, sandboxed HTML frames, attachments, delivery per recipient, actions, internal notes, history |
| `MessageComposer` | draft editing, recipient suggestions, attachments, send. It has **no poll**: a finished Livewire call clears the PWA update system's record of edits inside that component, which would hide unsaved typing |
| `MailboxBadge` | the header envelope (`PanelsRenderHook::GLOBAL_SEARCH_AFTER`), `wire:poll.60s.visible` |

Layout: three panes from 1280 px, two panes (folders above) from 1024 px, one column below with a visible Back button; at 640 px and
below compose is a full-height sheet with safe-area padding. The stylesheet is `resources/css/filament/email-center.css` (`.mail-`
prefix, `rgb(var(--hub-*))` tokens only): the admin theme is Filament's precompiled CSS and has no Tailwind build, so a utility
Filament does not already use does not exist on a panel page.

- **Compose state and the PWA.** The composer root carries `data-mbfd-unsaved-work-guard` and registers an `email-compose:<draft>`
  busy reason while a message is open. Autosave is a debounced (1.5 s) `autosave()` call on `MailDraftService::save()` with the
  draft `version`; a second window that saved first produces a conflict the writer resolves explicitly. Clicking away saves first.
- **HTML bodies** are shown only in `<iframe sandbox="allow-popups allow-popups-to-escape-sandbox">` pointing at the body route. The
  frame cannot be read from the page, so a hidden twin (`sandbox="allow-same-origin"`, no scripts, 0 height, off screen) is laid out
  at the same width to measure its content height.
- **Send** shows one of four results in words: Sent, Not sent (refused, draft kept), Not sent yet (paused, draft kept) or Not
  confirmed (locked, never resent). A group of five or more also needs the writer's "I have reviewed every recipient" tick, as the
  old Compose Email form asked. More recipients than the effective limit gets the explanation and a link to Department Updates.
- **Health** shows complaints as "Not reported by Cloudflare analytics", never as 0: the analytics dataset has no complaint data.
- **Keyboard**: j/k, c, r, a, f, e, `/`, `?` run in the capture phase, only on fine pointers, only when single-key shortcuts are on
  (the panel's own switch, Ctrl/Cmd+/), never while typing and never right after `g` (the panel's go-to prefix).

### Entry points and the old addresses

- Header envelope: only for `MailAccess::canView`; the accessible name carries the unread count (`Email, 3 unread`).
- Home Apps menu, the phone More sheet and the React shell: one `email` entry (`/admin/email`) right after Admin in
  `HubShellNavigation`, only when Admin is launchable and the member may read mail.
- Sidebar: one Communications > Email item. The Inbound Email, Outbound Email and Compose Email items are gone, their routes remain:
  the two lists and a bare Compose address redirect into the page (Inbox, Sent, `/admin/email`); a View page redirects to its
  conversation when indexed and otherwise keeps rendering; Compose with `source`, `message` and `mode` opens that reply or forward
  in the page (indexing the message first if needed). Access on those addresses is `MailAccess` (so `admin.email.*` works there too).
  `InboundEmailResource::canDelete` and `canDeleteAny` stay false.
