# Security Test Plan

## Method

- Prefer static code/config review first.
- Use live checks only when read-only and low-volume.
- Do not run destructive exploit chains, brute force, DoS, data dumps, or real payloads against production.
- Use route middleware tests, syntax checks, dependency audits, and safe metadata commands.
- Redact or avoid secret values in outputs.

## Tests Completed

| Area | Test | Result |
|---|---|---|
| Laravel route hardening | `php artisan test tests/Feature/SecurityHardeningRoutesTest.php` | Passed: 4 tests, 76 assertions; PHP deprecation notices only |
| Admin PWA service worker | `node --check public/admin-pwa/service-worker.js` | Passed |
| Media Control upload hardening | `node --check ... && node --test server/test/upload-policy.test.js` | Passed: 3 tests |
| GMKtec inventory | Read-only SSH metadata commands for OS, UFW, SSHD, ports, Docker, services | Completed; no secret values requested |
| GitHub settings | `gh` metadata/secret-name/actions-permission checks | Completed; action SHA pinning enforced |
| Cloudflare MCP | `cloudflare_workers_list` | Blocked by MCP auth 403; no token placed into shell commands |

## Tests Not Safely Completed

- Cloudflare live DNS/Tunnel/Access/WAF export: Cloudflare MCP returned auth 403 and no `CLOUDFLARE_API_TOKEN` env was present. Do not paste tokens into shell history/transcripts; use a secure credential channel or local env outside logs.
- Live public probing of admin tools beyond metadata: avoided to preserve availability and avoid disclosing state.
- Real DB/backup restore drills: not run to avoid production data handling during this pass.
- Starlink/router Wi-Fi/admin review: not accessible from repo/SSH; requires local router/app inspection.

## Next Safe Validation

1. Export Cloudflare DNS, Tunnel ingress, Access apps/policies, WAF, and rate-limit rules with redaction.
2. Run dependency audits after lockfile updates: `composer audit --locked`, `npm audit --audit-level=high`, nested npm audits.
3. Run full Laravel test suite and Media Control server test suite after reviewing existing unrelated local changes.
4. Validate expected HTTP status for each hostname: public app pages 200, admin/internal tools 302/401/403 behind Access/auth, deprecated origins 404/410.
5. Perform backup restore drill using non-production restored data.
