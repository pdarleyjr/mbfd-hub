# Deployment — MBFD Vacation Selection V1

> **STATUS (2026-05-27):** V1 is **already deployed and serving** at
> https://vacation.mbfdhub.com. This document is the runbook used to do
> that — it remains the source of truth for re-deploying from scratch on
> a new host or restoring after disaster.

This is the end-to-end checklist for the GMKtec EVO-X2 first deploy.
Everything is additive; nothing here touches MBFDHub, Nextcloud, OWUI, the
admin dashboard, or any other existing stack on the box.

## Prerequisites

- GMKtec EVO-X2 with Ubuntu 26, Docker Engine 24+, `cloudflared` running the
  existing `mbfdhub-gmktec` tunnel
- SSH access (`ssh gmktec` via `C:\Program Files\OpenSSH\ssh.exe`)
- Cloudflare account access (DNS + Workers + R2)
- The R2 bucket `mbfd-hub-laravel` already exists in this account
- `wrangler` CLI on your laptop (or use `npx wrangler`)
- `gh` CLI authenticated as `pdarleyjr`

## Step 1 — Clone the repo on GMKtec

```bash
ssh gmktec
sudo mkdir -p /opt/mbfd-vacation
sudo chown $USER:$USER /opt/mbfd-vacation
cd /opt/mbfd-vacation
git clone https://github.com/pdarleyjr/mbfd-vacation.git .
```

## Step 2 — Create the `.env`

```bash
cp .env.example .env
```

Edit `.env` and fill in:

| Variable | Where to get it |
| --- | --- |
| `POSTGRES_PASSWORD` | Generate: `openssl rand -hex 32` |
| `R2_ENDPOINT` | Cloudflare → R2 → API tokens → `https://<account-id>.r2.cloudflarestorage.com` |
| `R2_ACCESS_KEY_ID` | The Access Key ID you provisioned for the `mbfd-hub-laravel` bucket |
| `R2_SECRET_ACCESS_KEY` | Matching secret |
| `R2_BUCKET` | `mbfd-hub-laravel` |
| `R2_PREFIX` | `vacation/imports/` |
| `PIN_AUDIT_WEBHOOK_SECRET` | Generate: `openssl rand -hex 32`. Re-used in the Worker (Step 6). |

> Never commit `.env`. The repo already ignores it.

## Step 3 — Build & start

```bash
docker compose --env-file .env \
  -f infra/docker-compose.yml \
  -f infra/docker-compose.prod.yml \
  up -d --build
```

The `--env-file .env` flag is REQUIRED — compose's interpolation looks for
`infra/.env` by default, but our `.env` lives at the project root.

This builds `vac-api`, `vac-worker`, `vac-web`, and `vac-nginx`, then starts
the full stack. Postgres + Redis come up first; the apps wait for them.

## Step 4 — Run migrations + seed

```bash
docker compose --env-file .env -f infra/docker-compose.yml exec -T vac-api \
  node --import tsx/esm packages/db/src/migrate.ts
docker compose --env-file .env -f infra/docker-compose.yml exec -T vac-api \
  node --import tsx/esm packages/db/src/seed.ts
```

(The containers run TypeScript directly via tsx — no dist build step.)

## Step 5 — Add Cloudflare Tunnel ingress + DNS via API

The GMKtec `cloudflared` instance is token-based (no local YAML config),
so ingress changes go through the Cloudflare API. We need TWO hostnames:

- `vacation.mbfdhub.com` — what users hit, gated by the PIN Worker
- `vacation-origin.mbfdhub.com` — same tunnel, NO Worker; the PIN Worker
  proxies authenticated traffic here. Without a separate origin hostname
  the Worker would loop.

Both are added as ingress rules above the catch-all 404, then created as
proxied CNAMEs pointing at `<tunnel-id>.cfargotunnel.com`. The tunnel id
is `20cb894c-a5b0-4149-bc11-1499d772401e`; the zone id for `mbfdhub.com`
is `9c7b03d154bbf6abe7b2edd4b5c33fe5`.

Use a CF API token with Tunnel + DNS Edit permissions (the user's
`cfut_` Wrangler token has both). The exact API calls performed during
the first deploy are in `git log -p` for commit b6facae9 onward, or:

```bash
# 1. Fetch tunnel config, splice in vacation.mbfdhub.com + vacation-origin
#    above the catch-all 404 rule, PUT it back.
# 2. POST DNS CNAME records for both hostnames.
```

## Step 6 — Deploy the PIN-gate Worker

On your laptop:

```bash
cd vacation-app/apps/pin-gate
export CLOUDFLARE_API_TOKEN="<your cfut_ token>"
npx wrangler kv namespace create PIN_AUDIT_KV
# Paste the returned id into wrangler.toml's [[kv_namespaces]] block.
read -s -p "Department PIN: " PIN && echo "$PIN" | npx wrangler secret put PIN_VALUE
openssl rand -hex 32 | npx wrangler secret put PIN_SIGNING_SECRET
echo "<PIN_AUDIT_WEBHOOK_SECRET from .env on GMKtec>" | npx wrangler secret put PIN_AUDIT_WEBHOOK_SECRET
echo "<ORIGIN_SHARED_TOKEN from .env on GMKtec>" | npx wrangler secret put ORIGIN_SHARED_TOKEN
npx wrangler deploy
```

The `[[routes]]` block in `wrangler.toml` automatically binds the Worker
to `vacation.mbfdhub.com/*` on every deploy. No dashboard step needed.

## Step 7 — Smoke test

1. Visit `https://vacation.mbfdhub.com` — you should see the MBFD-styled PIN form.
2. Enter a wrong PIN — get a rate-limited error after 5 attempts.
3. Enter the right PIN — redirected to `/board`, which shows the empty state.
4. Click "Go to Import" — upload a small Telestaff CSV, XLSX, or
   SpreadsheetML 2003 XML (Chief Abello's "(EX) Export All Records"
   format), follow the wizard, commit. The board should populate.
5. Click into Runs → open the import → roll it back → board returns to empty.

## One-shot Telestaff XML bootstrap

For the initial load (pre-populating members + scheduled leave from
Telestaff without going through the wizard), the worker ships with a
streaming XML loader:

```bash
# On your laptop:
scp "TELESTAFF (EX) Export All Records.xml" gmktec:/tmp/telestaff-bootstrap.xml

# On GMKtec:
PGPASS=$(sudo grep ^POSTGRES_PASSWORD= /opt/mbfd-vacation/.env | cut -d= -f2)
docker cp /tmp/telestaff-bootstrap.xml vac-worker:/tmp/telestaff-bootstrap.xml
docker exec -e DATABASE_URL="postgres://vacation:${PGPASS}@vac-postgres:5432/vacation" \
  -w /app vac-worker \
  node --import tsx/esm apps/worker/src/scripts/bootstrap-telestaff-xml.ts \
       /tmp/telestaff-bootstrap.xml
```

The loader:
- Splits Telestaff's combined "LASTNAME, FIRSTNAME" Name column into
  proper firstName / lastName fields.
- Normalizes Position Rank labels (Firefighter → FF, Firefighter DE
  → FF-DE, Captain → CAPT, Division Chief → DC, civilian roles to
  their own short codes) and seeds any rank rows it hasn't seen.
- Picks each employee's primary shift by plurality across the export
  (handles cross-shift overtime correctly).
- Skips Overtime + Incentive category rows (those are on-duty, not
  leave) and writes an `import_runs` row so the bootstrap can be
  rolled back from the Runs tab just like a wizard import.
- Emits AM + PM block entries for 24-hour combat shifts so the board
  shows the full day off, not just morning.

## Updates / re-deploy

```bash
ssh gmktec
cd /opt/mbfd-vacation
./scripts/deploy.sh
```

## Backup

A weekly `pg_dump` task is included in the worker container's cron. R2 holds
every uploaded file forever; no expiry lifecycle. Manual backup:

```bash
docker compose exec vac-postgres pg_dump -U vacation vacation \
  | gzip > /opt/mbfd-vacation/backups/$(date +%F).sql.gz
```

## Troubleshooting

| Symptom | First thing to check |
|---|---|
| 502 at `vacation.mbfdhub.com` | `docker compose logs vac-nginx vac-web vac-api` |
| Upload returns 413 | nginx `client_max_body_size` (default 1100M) — increase in `infra/nginx/default.conf` |
| Worker doesn't run | `docker compose logs vac-worker`; check Redis health |
| PIN keeps re-prompting | Cookie domain or SameSite mismatch — confirm the Worker route is on the exact hostname `vacation.mbfdhub.com` |
| Audit webhook 401s | `PIN_AUDIT_WEBHOOK_SECRET` in `.env` must match the Worker secret |
