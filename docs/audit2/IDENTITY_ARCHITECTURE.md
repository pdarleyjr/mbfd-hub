# Unified Human Identity Architecture

**Status:** frozen implementation contract for planning. Owner-policy gates remain explicit.
**Source baseline:** `3cbea3c95b9bf4333b9830f9bcec749da7ff28eb`

## Invariants

1. `User` is the only human authentication, session, security, role, and permission principal.
2. `Employee` is the operational personnel profile. Existing Employee foreign keys and history stay Employee foreign keys.
3. Every enabled human User links to exactly one Employee; each Employee links to at most one User. Historical/non-login Employees need not be activated.
4. Human Actor identity is derived on the server from the canonical session. It is never selected by the browser, a station PIN, a device name, or an external service.
5. Subject/beneficiary, reviewer/approver, assignee, station/apparatus/shift, and device context are separate semantics.
6. No canonical human password is captured, mirrored, exported, logged, reversibly encrypted, or sent to another application.
7. Authentication does not flatten authorization. Every panel and route—including API endpoints, interactive actions, queue views, and direct record access—reauthorizes server-side.

## Canonical schema

### `users`

Keep existing primary keys, email, password, roles, permissions, Workgroup memberships, notification state, and audit relationships. Add only:

| Field | Contract |
|---|---|
| `employee_profile_id` | nullable during reconciliation; final enabled-human rows are non-null; unique FK to `employees.id`; `restrictOnDelete`; no cascade delete |
| `account_status` | checked values `pending_activation`, `active`, `disabled`; default `pending_activation`; no inference from Employee rank or roster absence |
| `security_version` | unsigned integer default 1; increment on disable/reactivate, password/recovery change, role/permission security change, and global revocation |
| `password_changed_at` | nullable timestamp; set when canonical password is established/changed |
| `must_change_password` | existing field; preserve and enforce with restricted session |
| `remember_token` | transitional only; rotate at cutover and retire from persistent-login use once per-device credentials exist |

Do **not** add `recovery_email` fields until the owner approves an authoritative recovery channel. Existing email may remain a notification/account field but is not silently treated as verified recovery proof.

The existing string `users.employee_id` is a reconciliation input only. Keep it during preview/apply and observation, then remove it after the surrogate FK is authoritative and rollback evidence no longer requires it. Never maintain two editable links.

### `employees`

Keep `id`, `employee_id` (the displayed Employee number), name, rank, and every operational relation. Add only the inverse relationship in source:

```php
Employee::user(): HasOne
User::employeeProfile(): BelongsTo
```

The Employee password, remember token, and must-change fields remain transitional until the legacy guard has zero observed use for the approved window. Final retirement is additive-first: disable verification, rotate legacy remember state, make fields nullable/scrub unreachable hashes, then drop authentication columns in a later release. Do not delete Employees.

### Authentication sessions

Create an authoritative metadata registry separate from the Redis session payload:

| Field | Purpose |
|---|---|
| `id` | random UUID exposed to the user's session list; not the Laravel session ID |
| `user_id` | canonical User FK |
| `session_id_hash` | keyed hash of the Laravel session ID; raw session ID never stored in the registry |
| `security_version` | User version captured at authentication |
| `context_class` | `managed_city`, `enrolled_phone`, `unmanaged_browser`, `shared_station`, `kiosk_overlay`, `privileged` |
| `device_principal_id` | nullable device FK; device never substitutes for User |
| `issued_at`, `last_activity_at`, `idle_expires_at`, `absolute_expires_at` | server-side expiry evidence |
| `recent_auth_at` | last qualifying Hub credential authentication |
| `revoked_at`, `revoked_reason` | immediate individual/global invalidation |
| `user_agent_label`, `last_ip_prefix` | minimal user-facing/session-security metadata; retention policy required |

Redis remains the live session payload store. Middleware requires both a live Redis session and a non-revoked registry row with matching `security_version` and time bounds. Registry loss fails closed for authenticated requests and does not silently recreate trust.

Persistent login must use a per-device selector plus a hashed validator, bound to the context and absolute expiry. Do not use Laravel's single shared 400-day `remember_token` as the final contract. Store no persistent plaintext token server-side; rotation and replay detection revoke the credential.

### Device principals

`device_principals` contains opaque ID, type, station/room binding, ability set, credential/key hash and key ID, status, security version, issued/last-seen/expires/revoked timestamps. A device lease is independent from a human session. Station PIN may be a transitional device/station capability but never a human authenticator.

## Identity reconciliation

### Preview rows

The preview emits one row for every User and Employee and includes:

- Employee DB ID, Employee number, name, rank;
- existing User ID, legacy User employee ID, email, roles, direct permissions, Workgroup memberships, Training/Admin access;
- Employee and User credential algorithm/state, must-change state, and **non-reversible hash fingerprints only**;
- Snipe numeric ID, Bid subject, ScreenTinker identity, and other confirmed external mappings;
- proposed action, confidence, blocking exception, owner decision, and source evidence.

It never emits password hashes, remember tokens, recovery values, session identifiers, API tokens, cookies, or secrets.

### Matching rules

1. Exact owner-authoritative Employee number can propose a link.
2. Name, email, rank, station, phone, fuzzy similarity, or row order can never auto-link.
3. Duplicate/collision, historical ID, missing Employee, multiple Users, external-ID disagreement, test/service identity, or credential conflict blocks that identity.
4. Preserve User IDs, Employee IDs, role/permission assignments, Workgroup memberships, notifications, and all operational/audit history.
5. The preview is deterministic: same input snapshot and mapping ledger yields byte-identical normalized decisions.

### Preview/apply phases

1. `scan`: read-only inventory and safe fingerprints.
2. `propose`: apply only exact mapping-ledger rules; produce `LINK`, `CREATE_USER`, `QUARANTINE`, or `BLOCKED`.
3. `owner-review`: signed/committed mapping ledger; every current User classified.
4. `rehearse`: production-shaped restored copy, PostgreSQL constraints, roles/memberships/FKs counted before/after.
5. `apply`: one transaction or resumable idempotent batches with a run ID; abort on any drift from the preview snapshot.
6. `verify`: zero duplicate links, every active User linked, all counts/history preserved, login cohort evidence captured.
7. `rollback-rehearse`: restore old links/hashes/status from restricted backup; never restore ScreenTinker mirroring.

No production apply is authorized by A07.

## Password transition

Current runtime evidence confirms both stores use bcrypt (30 User rows and 237 Employee rows). Therefore approved Employee hashes can be copied verbatim to User without plaintext and without hashing the hash.

Rules:

- ScreenTinker plaintext capture and unsafe reset actions are removed first.
- For `CREATE_USER`, copy the Employee bcrypt hash verbatim, copy `must_change_password`, set `password_changed_at` only when known, and assign no role beyond the owner-approved baseline.
- For an existing mapped User, compare only keyed hash fingerprints. A difference is `CREDENTIAL_CONFLICT`; apply cannot silently overwrite it.
- Owner resolution choices are: adopt the known working Employee credential as canonical, or issue one-time activation. Reusing the old privileged User password is not the automatic default and no plaintext comparison is attempted.
- Any non-bcrypt/unsupported/malformed hash becomes one-time activation; never double-hash.
- After canonical password acceptance, increment `security_version`, revoke legacy/persistent sessions, and stop Employee guard logins for that cohort.
- Legacy Employee hashes remain only for the approved rollback window, then verification is disabled and the fields are retired. Rollback may restore a prior hash but may never restore plaintext propagation.

## Canonical browser contract

### `GET /login`

- One MBFD Hub brand and one Employee-ID/password form.
- Safe intended destination is a server-issued, signed or session-stored relative-path token. Allowed prefixes: `/employee`, `/admin`, `/training`, `/workgroups`, `/daily`, and explicitly registered first-party modules.
- Reject scheme/host, protocol-relative, encoded traversal/bypass, and unknown prefix.
- Already authenticated users are reauthorized and redirected to intended or role-derived home; no login loop.

### `POST /login`

Input: Employee ID, password, optional persistence request, intended token. Employee-ID normalization must be owner-approved and deterministic; until then, trim only and require exact stored value.

Processing:

1. Enforce 5 failures per normalized account+IP per minute and 30 per IP per minute; log without password or full recovery data.
2. Resolve Employee by exact number, then its linked active User. Unknown, unlinked, disabled, and bad password return the same message/status and a bounded equivalent timing path.
3. Verify only the canonical `users.password` after cutover. During a short cohort migration, the explicitly versioned transition service may verify the approved Employee hash and immediately establish the User hash; it cannot call external integrations.
4. Regenerate session ID and CSRF token; create registry row; set server context, security version, issued/absolute/idle times, and `recent_auth_at`.
5. A must-change User receives a restricted session that can access only password change, logout, and necessary static assets. Password change revokes every other session.

### `POST /logout`

CSRF required. Logout `web`, revoke current registry row and persistent credential, invalidate the Laravel session, regenerate CSRF, clear selected Workgroup/station/human overlay context, and expire canonical plus known legacy cookies. GET logout is prohibited.

### Recovery and account security

Recovery remains **OWNER POLICY REQUIRED**. The technical minimum is generic response, single-use expiring verifier, rate limits, verified proofing channel, immutable audit, notification, session/security-version revocation, and no operator-known permanent password.

Security administration (password, recovery, role/permission, enable/disable, device/API credential) requires Hub credential reauthentication no older than 5 minutes. Operationally sensitive approvals use 15 minutes only after owner classification.

## Session policy

These are MBFD risk limits informed by NIST SP 800-63B-4 and OWASP session guidance; they are not claimed to be mandated values. Context classification is server-assigned from verified posture. If posture is unavailable, use `unmanaged_browser` or `shared_station`, never a browser-selected stronger class.

| Context | Idle | Absolute | Persistence | Privileged ceiling |
|---|---:|---:|---|---|
| Managed City workstation with verified posture | 8 h | 7 d | per-device credential, max 7 d; City auto-lock prerequisite <=15 min | 1 h idle / 24 h absolute |
| Enrolled/device-bound personal phone | 7 d | 30 d | per-device credential, max 30 d; passcode/biometric and named revocation required | 1 h idle / 24 h absolute |
| Unenrolled personal browser | 24 h | 7 d | optional per-browser credential, max 7 d | 1 h idle / 24 h absolute |
| Shared station computer | 15 min | 8 h | disabled | 15 min idle / 4 h absolute |
| Trusted kiosk device | device lease 30 d rotating; human overlay 15 min idle | human overlay 12 h | no human persistence | 15 min idle / 4 h absolute |

- Idle and absolute time are server-enforced and cannot be extended by client clocks, refresh loops, or WebSocket traffic alone.
- Password change, disable, global sign-out, recovery, and security-sensitive role loss increment `security_version` and revoke affected sessions immediately.
- Users can list named sessions, revoke one, revoke all others, or revoke all. Admin visibility is policy/retention limited.
- Host-only cookie target: `__Host-mbfd_hub_session`; Secure, HttpOnly, Path `/`, no Domain, approved SameSite. Explicitly expire the old `.mbfdhub.com` cookie during cutover.
- Encrypt Redis session payloads after compatibility/load/rollback proof. Do not claim the source default changed the currently observed production value.

## `AuthenticatedMemberContext`

One immutable request-scoped service resolves:

```php
interface AuthenticatedMemberContext
{
    public function user(): User;
    public function employee(): Employee;
    public function abilities(): array;
    public function workgroup(): ?WorkgroupContext;
    public function station(): ?StationWorkContext;
    public function device(): ?DeviceContext;
    public function requireAbility(string $ability, mixed $subject = null): void;
}
```

Inputs are only the authenticated User, verified Employee FK, current roles/policies, validated server-side selected contexts, session registry, and separately authenticated device. It fails closed for disabled/unlinked users, version mismatch, expiry, revoked device, or unauthorized context. Controllers do not perform ad-hoc identity lookups.

## `/api/me/context`

- Same-origin `GET`, `auth:sanctum` using the `web` cookie after Laravel `statefulApi()` is enabled and origins/CORS are explicitly tested.
- `Cache-Control: no-store, private` and `Vary: Cookie`.
- Stable minimal response:

```json
{
  "user": { "id": 123 },
  "employee": { "id": 456, "employee_number": "20731", "name": "...", "rank": "..." },
  "abilities": ["daily.inspection.create"],
  "workgroup": null,
  "station": { "id": 1, "source": "device", "locked": true, "editable": false, "shift": null },
  "device": { "id": "opaque", "type": "station_kiosk", "trusted": true }
}
```

Do not expose hashes, password/recovery state, role internals, security version, session IDs, tokens, arbitrary personnel directories, or policy explanations. Abilities are UI affordances only; every mutation reauthorizes.

## Role and privilege delegation

Every change evaluates the whole request:

```text
authorize(actor, target, current roles/permissions, proposed additions, proposed removals, security action, scope, recent-auth age)
```

**Technical safe default:** deny by default; Workgroup authority cannot change global credentials/roles; an actor cannot act on self or an equal/stronger target, grant outside an explicit delegation, grant `super_admin`, bypass disabled status, or remove the last Super Admin. Until owner policy exists, only an existing Super Admin with recent auth may grant/remove lower delegated roles on a lower target; Super Admin creation/removal and equal-target actions remain blocked.

**Owner policy required:** delegation table, super-admin/break-glass custody, exact role hierarchy (if any), who may reactivate, and whether any equal-target action is legitimate.

## Station and device context

`StationWorkContext` fields: station ID, source, locked/editable, optional shift, optional opaque device ID, issued/expires times. Precedence:

1. verified locked device/deep-link station;
2. workflow-required station;
3. authoritative current duty assignment, if one exists;
4. permitted session-selected station;
5. Employee home/default only as a suggestion;
6. manual selection validated for the operation.

Every mutation records source. Lower trust cannot override locked higher trust. Home station is not current station. PIN is station/device authorization only.

## Daily and offline ownership

Each local completed item stores immutable client UUID, canonical payload hash, schema/app version, creation time, station/apparatus snapshots, and actor affinity `{user_id, employee_id, session_generation, authenticated_at, affinity_hash}`. Affinity is correlation, not authorization.

- Same actor returns: server reauthenticates, derives Actor, validates policy/context, and idempotently accepts.
- Different actor returns: no POST, no payload disclosure; state becomes `REAUTHENTICATION_REQUIRED_FOR_SYNC` and only a withheld count is shown.
- 401/419/403/mismatch: retain completed evidence and stop retries; never convert to generic permanent failure.
- Response lost after commit: same UUID/hash returns original receipt; different hash is a conflict.
- Logout clears decrypted session/UI material but does not silently delete lawful pending evidence.
- Unknown apparatus policy remains fail-closed; historical ledger/review provenance is untouched.

## External identity contracts

- **Bid:** browser authenticates to Hub. Hub issues a <=60-second, signed, one-time, audience-bound authorization code/assertion containing opaque subject and approved claims. Bid validates issuer/audience/signature/expiry and exchanges once. Disable the password verifier after monitored dual-run. No Hub password enters Bid.
- **ScreenTinker:** immediately stop password capture/mirroring. Use a least-privilege service principal for provisioning or verified federation/passwordless account activation. Hub must not change Media Control without separate authorization. Rollback cannot restore mirroring.
- **Snipe-IT:** persist the existing Snipe numeric user ID against the approved canonical Employee/User mapping before any create/update. Preview collisions and do not create on uncertainty. Prefer SSO for humans and service provisioning; retain a controlled break-glass Snipe administrator.
- **Cloudflare:** outer Zero Trust perimeter remains independent. Laravel remains authoritative. Fresh read-only control-plane evidence is required before any change; raw Access headers are never trusted without verified JWT issuer/audience/signature/subject/auth-time binding.

## Retirement order

1. Remove plaintext propagation and unsafe resets.
2. Commit A06 evidence; approve recovery/delegation/device/mapping policies.
3. Preview and rehearse identity/Snipe mappings.
4. Add User FK/status/security/session foundations.
5. Establish canonical hashes without plaintext; implement login/logout/account security.
6. Add stateful same-origin API and Identity Context.
7. Converge Employee, Admin, Training, Workgroups and domain routes in cohorts.
8. Replace Bid/ScreenTinker password flows; rotate service credentials.
9. Add Actor-derived Daily/station/inventory writes and actor-affine offline sync.
10. Redirect legacy login GETs, block legacy POSTs, rotate old sessions/tokens, observe zero legacy use.
11. Disable then remove Employee authentication fields/guard and obsolete routes/config in a later release.
12. Full security, migration, browser, integration, immutable-image, backup/restore, canary, physical, and human acceptance gates.

## Authoritative references

- NIST SP 800-63B-4: https://pages.nist.gov/800-63-4/sp800-63b.html
- OWASP Session Management Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
- OWASP Authentication Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
- Laravel authentication: https://laravel.com/docs/12.x/authentication
- Laravel Sanctum SPA authentication: https://laravel.com/docs/12.x/sanctum#spa-authentication
