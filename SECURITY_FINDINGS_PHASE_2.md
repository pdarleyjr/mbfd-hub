# Security Findings — Phase 2 (MBFD Hub)

Date: 2026-06-06. Severity: Critical / High / Medium / Low. Status reflects end of Phase 2.

Phase 2 obtained live Cloudflare + production-host access (Phase 1 was blocked on Cloudflare auth) and closed the residual items from Phase 1's backlog.

## Resolved in Phase 2

| ID | Sev | Finding | Resolution | Status |
|---|---|---|---|---|
| P2-01 | High | Cloudflare Access coverage unverified (Phase-1 H-07) | Live export done; classified every hostname; gaps closed (below) | ✅ Resolved |
| P2-02 | High | `baserow.mbfdhub.com` publicly reachable, only app-login | Owner decommissioned Baserow: DNS removed, container stopped, **full code removal** (client/webhook/Filament/models/compose) | ✅ Resolved |
| P2-03 | High | `inventory.mbfdhub.com` (Snipe-IT) not behind CF Access | CF Access on UI + `/api` bypass so Laravel integration keeps working (verified non-breaking) | ✅ Resolved |
| P2-04 | High | Public apparatus inspection could force Out-of-Service (Phase-1 H-01) | Pending-review workflow + gated approve endpoint + tests | ✅ Resolved |
| P2-05 | High | Public station APIs over-expose operational data (Phase-1 H-02) | 9 public redaction Resources + contract tests | ✅ Resolved |
| P2-06 | High | Sensitive files on public disk (Phase-1 H-04) | Private disk + authorized/signed serving + move command + tests | ✅ Resolved (code; data-move command runs on box) |
| P2-07 | High | No edge WAF / rate-limiting (0 custom rules) | WAF scanner-block + login rate-limit live | ✅ Resolved (rate-limit plan-limited — see P2-15) |
| P2-08 | Med | Media Control display-control had no rate-limit/audit; SSRF on remote URLs | Per-socket rate-limit + queue-depth, `audit_log` w/ redaction, DNS-based SSRF policy (158 tests) | ✅ Resolved |
| P2-09 | Med | Backups local-only, unencrypted, no restore test (Phase-1 M-08) | Restic→R2 encrypted, cron, staleness alert, **restore test passed** | ✅ Resolved |
| P2-10 | Med | dozzle + uptime-kuma mounted Docker socket (`:ro` ≠ API read-only) (Phase-1 M-06) | Read-only `docker-socket-proxy`; kuma's unused mount removed; writes denied (403) | ✅ Resolved |
| P2-11 | Med | `gm-test.mbfdhub.com` ungated alias to prod app | CF Access added | ✅ Resolved |
| P2-12 | Med | No host alerting | `/opt/mbfd/alerts.sh` (disk/DB/app/tunnel/backup/containers/queue/SSH) every 15 min | ✅ Resolved (delivery channel = owner step) |

## Verified non-issues (checked live in Phase 2)

| ID | Item | Finding |
|---|---|---|
| P2-V1 | `vacation-origin.mbfdhub.com` PIN-gate bypass | App-layer `X-Origin-Token` guard enforced; data endpoints 404 on direct access. Mitigated. |
| P2-V2 | Open WebUI signup (Phase-1 M-07) | `ENABLE_SIGNUP:false`/`WEBUI_AUTH:true`/`DEFAULT_USER_ROLE:pending` in compose — persists recreate |
| P2-V3 | AI agent SSH/token/write mounts (Phase-1 M-07) | No AI container mounts SSH keys, docker.sock, or broad host paths — already least-privilege |
| P2-V4 | Internal services exposure | All non-public services bound `127.0.0.1`; UFW default-deny; reachable only via tunnel/Tailscale |

## Residual / deferred (owner or maintenance window)

| ID | Sev | Item | Recommended action |
|---|---|---|---|
| P2-13 | Critical | **Exposed credentials** (CF token, R2 keys, GitHub PAT pasted in transcript; stale box CF token; Snipe-IT DB pw) | Rotate per `DEFERRED_OWNER_SECRET_ROTATION.md` (NOT done per task constraint) |
| P2-14 | Med | `ts.mbfdhub.com` app-auth only (401) | Add CF Access once browser cross-origin use is ruled out (service-token bypass like Snipe-IT) |
| P2-15 | Med | CF rate-limiting is Free-tier (1 rule, 10s, block-only) | Upgrade entitlement → per-path rules for public-write/upload/AI + longer windows |
| P2-16 | Med | Mutable container image tags | Pin by `@sha256:` digest in a maintenance window (socket-proxy already pinned) |
| P2-17 | Low | `office.mbfdhub.com` Access-bypass | JWT-enforced at app layer; dated exception recorded; consider path-restricting WAF later |
| P2-18 | Low | Alert delivery channel | Populate `/opt/mbfd/secrets/alert-webhook` or add an Uptime Kuma notification |
| P2-19 | Low | Nextcloud user-data not in restic set | Add as a separate restic path (size-aware) |
| P2-20 | Low | Pre-existing PDF blade bug (`station-inventory.blade.php`) | Add missing `use ($categories)` (flagged, out of security scope) |

## Backlog status (Phase-1 SECURITY_HARDENING_BACKLOG.md)
- Immediate: CF export/classify ✅; admin tools behind Access ✅ (Baserow removed; Snipe-IT gated; others already gated); credential rotation → deferred to owner.
- Short term: public inventory PDFs/uploads → private ✅; apparatus inspection → review workflow ✅; public station redaction ✅; Dozzle socket-proxy ✅; Open WebUI signup ✅.
- Medium term: encrypted off-host backups ✅; image digest pinning → documented.
