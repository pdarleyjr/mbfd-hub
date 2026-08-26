# MBFD Full System Audit — In Progress

**Audit date:** 2026-08-25  
**Generated source inventory:** [MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json](MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json)  
**Workspace:** `D:\CodexWorktrees\mbfd-hub-full-system-20260825`  
**Branch / SHA:** `audit/mbfd-hub-full-system-20260825` / `779b252160f39456cbfa85d4fe9e52dbb5e4b656`

## Scope and evidence status

This report begins with a generated static-source inventory. Runtime, production, browser, database, and physical acceptance are separate gates; none are represented as a pass until directly observed. Secret values are deliberately excluded.

## Instruction and worktree baseline

- Loaded the supplied workspace's `AGENTS.md` and `CLAUDE.md`; no override or nested instruction file was found. Their applicable requirements are surgical changes, test-backed fixes, secret hygiene, and no destructive production action.
- Preserved the original `D:\GitHub_Repos\MBFD_Hub` worktree unchanged. It was on `feature/mbfd-coding-controller` with 73 untracked status entries and diverges from cached `origin/main`.
- This clean audit worktree is based on cached `origin/main` at `779b252160f39456cbfa85d4fe9e52dbb5e4b656`. The configured `github-mbfd` SSH alias could not resolve during the baseline, so remote freshness is not claimed.

## Programmatic inventory summary

| Surface | Count |
|---|---:|
| Source files scanned | 1803 |
| Laravel route files | 3 |
| Static route declarations | 177 |
| Daily SPA routes | 18 |
| Daily API-client endpoints | 32 |
| Controllers | 66 |
| Models | 79 |
| Migrations | 151 |
| Filament resource PHP files | 155 |
| Filament page PHP files | 7 |
| Feature tests | 62 |
| E2E specs | 13 |

## Runtime gate

- **BLOCKED (local setup):** Laravel runtime commands require generated Composer autoload files. The isolated worktree intentionally started without `vendor/` or `.env`. `composer install` downloaded the lockfile packages after using the local Git executable, but Composer's optimized-autoload phase did not complete within the bounded observation window; it was stopped without source changes. This is an environment/setup blocker, not an application pass or fail.
- **Pending:** `php artisan route:list`, `migrate:status`, database integrity, browser flows, external integrations, production reconnaissance, and physical acceptance.

## Static route declarations

The full declaration list, source locations, Daily route list, API-client endpoints, manifests, and integration references are machine-readable in the linked JSON inventory. Laravel group-derived routes remain pending a runtime route table.

## Findings

| ID | Severity | Evidence | Status | Finding |
|---|---|---|---|---|
| MBFD-AUDIT-001 | P0 | Static source | Open | The API reads `storage/app/checklists`, while the tracked JSON lives at `storage/checklists`; a clean deployment can return an empty checklist with HTTP 200. The Daily client turns absent/malformed data into an empty wizard state. |
| MBFD-AUDIT-002 | P0 | Static source | Open | A Daily inspection has no durable submission/idempotency key. Offline queue metadata is not posted, and the API creates a new inspection for every retry, permitting duplicate persistence and meter side effects after acknowledgement loss. |
| MBFD-AUDIT-003 | P0 | Static source | Open | The tracked data model has no canonical check-required/exempt status. The public selector lists all apparatus, so required, reserve, OOS, inactive, retired, and administrative units cannot be deterministically distinguished. |
| MBFD-AUDIT-004 | P0 | Static source | Open | Command Display derives readiness from raw inspection event counts and a static station complement. Duplicate retries can make a station appear complete while another eligible apparatus is unchecked. |

The evidence locations for each source finding are included in the JSON inventory. Runtime, database, and browser reproduction are still required before a final acceptance result.

## Change log

- 2026-08-25T22:49:07.387Z: generated initial source inventory from the clean audit branch.
- 2026-08-25: recorded four source-confirmed Daily Checkout P0 findings; repair and regression work is in progress.
