# Cloudflare Live Route Review — MBFD Hub (Phase 2)

Date: 2026-06-06
Zone: `mbfdhub.com` (`9c7b03d154bbf6abe7b2edd4b5c33fe5`)
Account: `265122b6d6f29457b0ca950c55f3ac6e`
Production tunnel: `mbfdhub-gmktec` (`20cb894c-a5b0-4149-bc11-1499d772401e`)
Method: live Cloudflare API export (read) using an owner-provided token, plus unauthenticated external HTTP probing for empirical posture. No secret values are recorded here.

> Phase 1 was blocked on Cloudflare auth. Phase 2 obtained a working token and completed the live export, classification, and remediation.

## 1. DNS records (proxied status)

All MBFD app hostnames are **proxied (orange-cloud)** CNAMEs to the tunnel; Pages projects are proxied CNAMEs to `*.pages.dev`. No gray-cloud/origin-exposing records were found.

| Hostname | Type | Proxied | Target | Classification |
|---|---|---|---|---|
| mbfdhub.com / www.mbfdhub.com | CNAME | yes | tunnel → :8080/:8090 | public (app) |
| ai.mbfdhub.com | CNAME | yes | tunnel → :3030 | admin-only (Access) |
| cloud.mbfdhub.com | CNAME | yes | tunnel → :11000 | admin-only (Access) |
| office.mbfdhub.com | CNAME | yes | tunnel → :8092 | protected (JWT, Access-bypass exception) |
| office-ai.mbfdhub.com | CNAME | yes | tunnel → :11435 | protected (bearer 401) |
| admin.mbfdhub.com | CNAME | yes | tunnel → :8088 | admin-only (Access) |
| status.mbfdhub.com | CNAME | yes | tunnel → :3001 | admin-only (Access) |
| media.mbfdhub.com | CNAME | yes | tunnel → :8096 | public player + Access on `/app` |
| media-control.mbfdhub.com | CNAME | yes | tunnel → :8096 | admin-only (Access) + device bypass paths |
| inventory / www.inventory.mbfdhub.com | CNAME | yes | tunnel → :8083 (Snipe-IT) | admin-only (**Access ADDED Phase 2**) |
| ts.mbfdhub.com | CNAME | yes | tunnel → :7080 | protected (app 401) — Access recommended |
| gm-test.mbfdhub.com | CNAME | yes | tunnel → :8080 | app alias (**Access ADDED Phase 2**) |
| vacation.mbfdhub.com | CNAME | yes | tunnel → :7090 | public (PIN-gate worker) |
| vacation-origin.mbfdhub.com | CNAME | yes | tunnel → :7090 | protected (X-Origin-Token guard — verified) |
| baserow.mbfdhub.com | CNAME | yes | tunnel → :8082 | **REMOVED Phase 2 (decommissioned)** |
| wall.mbfdhub.com | CNAME | yes | mbfd-ops-wall.pages.dev | public (ops wall) |
| l1.mbfdhub.com | CNAME | yes | l1-compartment-builder.pages.dev | public/tool |
| staging.bid.mbfdhub.com | CNAME | yes | mbfd-bid-web-staging.pages.dev | staging |
| api.staging.bid.mbfdhub.com | AAAA | yes | 100:: (placeholder) | staging |
| vscode.mbfdhub.com | CNAME | yes | tunnel `mbfd-vscode` → :8080 | admin-only (Access: Peter) |

Other tunnels: `mbfdhub-nocobase` → catch-all 503 (idle/decommissioned); `mbfd-vscode` → vscode only.

## 2. Tunnel ingress (mbfdhub-gmktec, 20 rules)

Every public hostname maps to a loopback origin on the GMKtec host (`http://localhost:<port>`); catch-all returns `http_status:404`. The host binds all of these origins to `127.0.0.1` only (verified via `ss -tlnp`), so they are reachable **only** through the tunnel — never directly from the internet or LAN. Full ingress table captured in the assessment; notable removals/additions below.

## 3. Access applications & policies

Identity: Cloudflare Access team `darl.cloudflareaccess.com`, email OTP. Standard staff policy = allow `email_domain miamibeachfl.gov` + named admin emails (e.g., Peter). 24h sessions.

Pre-existing Access-protected: ai, cloud, admin, status, media-control (+ device bypass paths for `/player`, `/download`, `/socket.io`, `/api/kiosk`), media `/app`, vscode, office (intentional bypass — JWT at app layer), plus Nextcloud client-path bypasses (`/remote.php`, `/ocs`, `/public.php`, `/login/v2`, `/status.php`, `/index.php/204`).

### Phase-2 Access additions
- **Snipe-IT** `inventory.mbfdhub.com` and `www.inventory.mbfdhub.com`:
  - UI app (allow MBFD staff) — human access now requires Access OTP.
  - **`/api` path app (bypass everyone)** — preserves the Laravel→Snipe-IT server-to-server integration (`SNIPEIT_API_URL=https://inventory.mbfdhub.com/api/v1`), which is still protected by Snipe-IT's own API bearer token. Verified: UI → CF Access redirect; `/api/v1` → NOT behind Access (no breakage).
- **gm-test.mbfdhub.com** (ungated alias to the prod app on :8080) — allow MBFD staff.

## 4. WAF & rate-limiting (was: 0 custom rules, 0 rate-limit rules)

Baseline before Phase 2: Cloudflare Managed Free Ruleset (active), DDoS L7 (active), Normalization (active), `security_level=high`. **No custom WAF or rate-limit rules existed.**

### Phase-2 additions (LIVE)
- **WAF custom rule — scanner/probe blocker** (phase `http_request_firewall_custom`, action `block`): blocks paths containing `/.env`, `/.git/`, `/.aws`, `/.ssh`, `/wp-login`, `/wp-admin`, `/wp-content`, `/wp-includes`, `/xmlrpc.php`, `/phpmyadmin`, `/vendor/phpunit`, and `.sql`/`.bak`/`.old` suffixes. Verified live: `/.env`, `/wp-login.php`, `/phpmyadmin/`, `/backup.sql` → 403; `/` → 200.
- **Rate-limit rule — login/auth brute-force** (phase `http_ratelimit`, action `block`): >5 POST/10s per IP+colo to paths containing `/login`, `/auth`, `/password`, `/sign_in`. (Free rate-limiting tier caps this zone at **1 rule, 10s period, block-only** — see Residual.)

## 5. Hostname classification (final)

- **public**: mbfdhub.com, www, media (player), vacation (PIN), wall, l1, staging.bid
- **protected (app/JWT/bearer/token)**: office (JWT), office-ai (bearer), ts (app 401), vacation-origin (X-Origin-Token guard)
- **admin-only (CF Access)**: ai, cloud, admin, status, media-control, inventory(+www) UI, gm-test, vscode, media `/app`
- **internal-only (not tunneled, no DNS)**: dozzle, kuma direct, web-check, qdrant, postgres, redis, ollama, comfyui, tika, mcpo, AI tool services — all `127.0.0.1` only
- **deprecated/removed**: baserow (removed), nocobase tunnel (503 catch-all)

## 6. Verified non-issues
- **vacation-origin**: the pin-gate Worker injects `x-origin-token`; the origin API (`vacation-app/apps/api/src/middleware/origin-guard.ts`) rejects any request lacking the matching secret. Direct probe: data endpoint `/api/board` → 404 (guarded) vs 200 via the worker. Only the dataless SPA shell + `/api/health` are open. No PII exposure. **Mitigated.**
- **office.mbfdhub.com**: Access bypass is intentional for ONLYOFFICE iframe/callback compatibility; document/editing callbacks are JWT-signed at the ONLYOFFICE app layer (DS 9.4). Dated exception recorded here (2026-06-06). Scanner WAF now also applies.
- **AI tool origins, DBs, caches, Ollama, ComfyUI**: bound to `127.0.0.1`; UFW default-deny inbound (only Tailscale + loopback + container→Ollama). Not internet-reachable.

## 7. Changes made (with rollback)

| Change | Type | Rollback |
|---|---|---|
| WAF scanner-block rule | WAF custom | `PUT zones/{zone}/rulesets/phases/http_request_firewall_custom/entrypoint` with `{"rules":[]}` |
| Login rate-limit rule | Rate limit | `PUT .../phases/http_ratelimit/entrypoint` with `{"rules":[]}` |
| Snipe-IT UI Access apps (inventory, www.inventory) | Access app | `DELETE accounts/{acct}/access/apps/{id}` (ids: b3f266d5…, 8b7259f3…) |
| Snipe-IT `/api` bypass apps | Access app | `DELETE …/access/apps/{id}` (ids: 9c9ef231…, 19ded9ff…) |
| gm-test Access app | Access app | `DELETE …/access/apps/{id}` (id: dd1b8773…) |
| baserow DNS record delete | DNS | Re-create CNAME `baserow.mbfdhub.com` → `20cb894c-…cfargotunnel.com` (proxied) if ever needed |

## 8. Residual / owner follow-ups
- **Rate-limiting is plan-limited** (Free tier: 1 rule, 10s window, block-only). Recommend upgrading the zone's rate-limiting entitlement to add per-path rules for public-API writes, uploads, AI endpoints, and longer windows / managed-challenge actions. Ready-to-apply rule set is documented in `SECURITY_REMEDIATION_LOG_PHASE_2.md`.
- **ts.mbfdhub.com**: app-auth (401) today. Full CF Access recommended once it is confirmed the bid web app does not call it cross-origin from the browser (would need a service-token policy like Snipe-IT's `/api` bypass).
- **office.mbfdhub.com**: consider a WAF rule restricting it to the editor/callback path prefixes once the full ONLYOFFICE path set is enumerated in a maintenance window.
- The provided Cloudflare token is now exposed (transcript) — see `DEFERRED_OWNER_SECRET_ROTATION.md`.
