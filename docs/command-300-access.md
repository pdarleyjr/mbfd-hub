# 300 Command Access

One server-side decision governs everything on the 300 Command side of MBFD Hub:
`App\Services\Security\Command300Access` (Laravel Gate ability `command.300.access`).

## Who qualifies

An account must be active, allowed to authenticate (current personnel link when it has one),
and have no pending password setup. Then **any one** of these grants access:

| Source | How it is determined |
| --- | --- |
| Administrator | `super_admin` role, or a direct `admin.access` grant (same contract as `User::hasCurrentAdminPanelEntitlement()`). |
| Captain | Authoritative `employees.rank`, trimmed and case-insensitive, exactly `Captain`. |
| Division Chief | Same, exactly `Division Chief`. |
| 300 assignment | The member's single current (`superseded_at IS NULL`), integrity-checked bid row in the latest published bid year has `unit_label` exactly `300`. |
| Explicit grant | Direct Spatie permission `command.300.access`, set with the **300 Command Access** toggle. |

Not qualified by themselves: Lieutenant, Firefighter, Driver Engineer, other chief ranks,
apparatus names such as `Captain 5`, superseded or older-year 300 rows, and the role named
`admin` without a direct `admin.access` grant. Production ranks are canonical strings; no
abbreviations (`Capt`, `DC`) are recognised.

## Where it is enforced

- `/daily/command-300` (and case/trailing-slash variants) — 403 before the SPA shell is served.
- `GET /api/public/command/overview` — `can:command.300.access`.
- `/api/me/context` — `authorization.capabilities.command_300_access` is a UI hint only.
- 300 video conferencing — every command action and command-role LiveKit token re-checks live.
  No PIN. Conferencing still requires a linked personnel profile; an unlinked administrator
  receives a specific message.
- Command Board (`cmd` OIDC client) — authorize, code exchange, token use and userinfo all run
  the policy; claims carry `command_access: "300"` and omit `employee_id` for an unlinked
  administrator. An ineligible member is redirected back to the board with `error=access_denied`.
- 300 Command Communications — API, attachment downloads and the Filament page. The Admin
  Panel page additionally follows the Communications area capability (`super_admin` or a
  direct `admin.communications.view`), like its panel neighbours, so scoped administrators
  such as Uniform & PPE managers keep a narrow panel and use the 300 profile tab instead.

## Granting extra access

Admin Panel → Personnel → Employees → member → **Administration & access → 300 Command Access**
(Super Administrators only; current password and reason required; audited as
`change_command_300_access`). The form shows the current source ("Automatic — Captain",
"Explicit access grant", …). Turning the toggle off never removes automatic access and
never touches `admin.access` or other grants.

## Revocation timing

| Surface | Effect of losing access |
| --- | --- |
| Hub pages, APIs, communications, video command actions | Next request (live check, no cache). |
| Issued command LiveKit tokens | Expire with their TTL (≤ 15 min); no new token or command action is issued. |
| Command Board | Next userinfo revalidation (≤ 60 s; sockets swept every 15 s). Removing the explicit grant also revokes `cmd` OIDC sessions immediately when it was the member's only route. |

## Rollback

- Hub: redeploy the previous image; the permission row and nullable `oidc_sessions.employee_profile_id` are additive.
- Command Board: set `CMD_PIN_REQUIRED=true` and recreate the container, or retag the rollback image.
  The legacy **Command Board PIN** admin page (`/admin/command-board-pin`) is hidden from
  navigation but still reachable for `app.cmd.admin` holders for that purpose.
