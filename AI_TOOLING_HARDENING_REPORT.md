# AI / Open WebUI / Ollama / Tooling Hardening Report — MBFD Hub (Phase 2)

Date: 2026-06-06
Host: GMKtec (`mbfdhub`)
Scope: Open WebUI, Ollama, AI tool containers, observability tooling. Verified against the **live** deployment (Phase 1 was repo/doc-based).

## Summary of live posture (verified)
The AI stack is materially more locked down than the Phase-1 review feared. Most "potential" risks did not materialize in the live deployment; the remaining items are documented with concrete recommendations.

| Item | Phase-1 concern | Live finding (Phase 2) | Status |
|---|---|---|---|
| Open WebUI signup | compose/docs mismatch; could be on | `ENABLE_SIGNUP:"false"`, `WEBUI_AUTH:"true"`, `DEFAULT_USER_ROLE:"pending"` **set in compose** (`/opt/ai-stack/docker-compose.yml` L35-37) → persists `--force-recreate` | ✅ Resolved/verified |
| AI agent SSH/token/write mounts | "writable SSH/token mounts, high-risk under prompt injection" | **No AI container mounts `~/.ssh`, `docker.sock`, `/root`, `/home`, or broad host paths.** `nextcloud-write`, `nextcloud-user-fs`, `media-control-tools`, `doc-generator` have **no sensitive host mounts**; `mcpo` mounts a read-only config; `comfyui` mounts only its own model/output dirs | ✅ Already least-privilege |
| Open WebUI exposure | public? | `ai.mbfdhub.com` behind **CF Access** (allow @miamibeachfl.gov); container bound `127.0.0.1:3030` | ✅ |
| Per-user RAG isolation | — | Per-user KBs + 15-min sync (prior work) | ✅ |
| Ollama bind | wildcard `*:11434` | Confirmed `*:11434`, but **UFW default-deny inbound** (only `tailscale0`, `lo`, and container subnet `172.16.0.0/12 → 11434`). Not internet-reachable | ⚠️ Firewall-contained; see recommendation |
| Observability Docker socket | dozzle + kuma mount `docker.sock` | **Fixed Phase 2** (socket-proxy) | ✅ |

## Changes made (Phase 2)

### Docker socket proxy (dozzle / uptime-kuma)
`/opt/mbfd/observability/compose.yaml` rewritten (backup: `compose.yaml.pre-socketproxy.bak`):
- Added `docker-socket-proxy` (`tecnativa/docker-socket-proxy:0.3.0`) — the **only** container that now mounts `docker.sock`. Read-only API surface: `CONTAINERS/EVENTS/INFO/PING/VERSION=1`, everything else (`POST/EXEC/IMAGES/NETWORKS/VOLUMES/SERVICES/TASKS/SECRETS/CONFIGS/SWARM/SYSTEM`)=`0`. Verified: `POST /containers/create` → **403**, `GET /images/json` → **403**, `GET /version` → 200.
- **dozzle** now reaches Docker via `tcp://docker-socket-proxy:2375` (no direct socket). Verified: `http://localhost:8888` → 200, logs "Connected to Docker".
- **uptime-kuma** socket mount **removed entirely** — it had **0 docker hosts / 0 docker-type monitors** (only 13 HTTP monitors), so the mount was unused. Verified healthy after change.
- Rationale: a `:ro` bind of `docker.sock` does **not** prevent Docker API writes (it only makes the socket *file* read-only) — equivalent to host root. The proxy enforces real read-only at the API layer.

> Why this matters: previously both `mbfd-dozzle` and `mbfd-uptime-kuma` could, via the Docker API, read every container's env (secrets) and create/exec containers. Now only a request-filtered, write-denying proxy can talk to Docker.

## Recommendations (documented, not auto-applied to avoid breaking the live AI stack)

### 1. AI agent permission tiers (policy)
The container layer is already least-privilege. Codify a **profile policy** for any future agent/tool wiring:
- **read-only** (default): no shell, no write tools, no SSH, no tokens, scoped per-user file reads only. This is what the current tool containers already are.
- **write-limited**: scoped writes into the caller's own Nextcloud space only (as `nextcloud-write` already does via `X-OpenWebUI-User-Email`).
- **deploy / break-glass**: the ONLY profile permitted shell/SSH; SSH keys mounted **read-only**, `StrictHostKeyChecking=yes`/`accept-new`, used interactively, never wired into an LLM tool. No such profile currently exists in a container (good) — keep it that way.

### 2. Ollama bind
Defense-in-depth (currently firewall-contained). Options, in order of safety vs. effort:
- Add a UFW rule to **deny 11434 on `tailscale0`** while keeping the container-subnet allow — closes Ollama to Tailscale peers without affecting containers (which use the bridge gateway). Apply only after confirming no Tailscale client (e.g., a dev laptop) calls Ollama directly. Command: `sudo ufw insert 1 deny in on tailscale0 to any port 11434 proto tcp`.
- Leave as-is: it is already not internet-reachable; the residual surface is the trusted Tailscale mesh + local containers.
- Pure loopback is **not viable** — the containerized AI stack reaches host Ollama via the docker bridge gateway, not `127.0.0.1`.

### 3. Prompt/log retention & redaction
- Open WebUI chat history is per-user in its DB; review retention expectations with the FD (consider a periodic prune of old conversations containing operational context).
- The new media-control audit log and the box logs **redact** secret-named keys/token-shaped values. Apply the same redaction discipline to any future AI request logging. Do not log full prompts containing PII to shared logs.

### 4. Rate limits / resource ceilings
- AI hostnames are behind CF Access (authenticated users only), which bounds abuse. The new CF login rate-limit + WAF apply at the edge. For public AI/support-chat endpoints (Workers), keep the per-route throttles. `MAX_LOADED_MODELS` and container resource limits are already set for the stack.

## Verification commands used (read-only / non-destructive)
- `docker inspect <c> --format '{{range .Mounts}}…'` (mount audit)
- `ss -tlnp` (bind audit), `ufw status`
- `sqlite3 /app/data/kuma.db "SELECT … FROM docker_host/monitor"` (kuma docker usage)
- socket-proxy POST/GET probes (403 confirmation)
