# API and Integration Security Review

## Integrations Identified

- Cloudflare Workers/AI/Vectorize/R2.
- PulsePoint proxy.
- Google API/service account.
- Snipe-IT API.
- Baserow.
- Sentry backend/frontend/source-map workflows.
- VAPID/Web Push.
- Nextcloud/ONLYOFFICE callbacks.
- Open WebUI/Ollama/Qdrant/AI extras.
- Vacation app R2 imports and Worker PIN gate.

## Findings

- Shared-token bridge routes should keep failing closed and use timestamp/replay checks where requests are not already ephemeral.
- Worker routes with CORS are not authentication by themselves; all non-public ingest/delete/vectorize endpoints need shared-secret or JWT enforcement.
- Public support/chat/AI endpoints need strict rate limits and cost/resource ceilings.
- Repo secrets exist for Cloudflare, R2, Sentry, deploy SSH, and GitHub PAT by name; exposed values must be rotated.
- Health checks and logs must not expose env/config details.

## Recommendations

1. Inventory all Worker secrets by name only after Cloudflare auth is fixed.
2. Add HMAC request signing with timestamp/replay windows for internal webhooks where feasible.
3. Minimize API response fields on public/station endpoints.
4. Add per-user/IP/route throttles for AI, support chat, uploads, webhooks, and Media Control commands.
5. Add audit events for new admin users, role changes, display-control actions, AI ingest/delete, and webhook failures.
