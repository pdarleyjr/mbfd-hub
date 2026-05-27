# Deployment — MBFD Vacation Selection V1

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
docker compose \
  -f infra/docker-compose.yml \
  -f infra/docker-compose.prod.yml \
  up -d --build
```

This builds `vac-api`, `vac-worker`, `vac-web`, and `vac-nginx`, then starts
the full stack. Postgres + Redis come up first; the apps wait for them.

## Step 4 — Run migrations + seed

```bash
docker compose exec vac-api node packages/db/dist/migrate.js
docker compose exec vac-api node packages/db/dist/seed.js
```

(The image bundles the compiled migrate + seed scripts.)

## Step 5 — Splice the Cloudflare Tunnel ingress

Open the existing tunnel config on GMKtec:

```bash
sudo nano /etc/cloudflared/config.yml
```

Splice the contents of `infra/cloudflared/ingress-snippet.yml` into the
`ingress:` list ABOVE the catch-all `service: http_status:404` rule. Then:

```bash
sudo systemctl restart cloudflared
```

Create the DNS record:

```bash
cloudflared tunnel route dns mbfdhub-gmktec vacation.mbfdhub.com
```

## Step 6 — Deploy the PIN-gate Worker

On your laptop:

```bash
cd vacation-app/apps/pin-gate
npx wrangler kv:namespace create "PIN_AUDIT_KV"
# → paste the returned id into wrangler.toml
npx wrangler secret put PIN_VALUE                  # the shared department PIN
npx wrangler secret put PIN_SIGNING_SECRET         # openssl rand -hex 32
npx wrangler secret put PIN_AUDIT_WEBHOOK_SECRET   # MUST match step 2
npx wrangler deploy
```

Bind the Worker to the hostname in the Cloudflare dashboard:

- Workers & Pages → `mbfd-vacation-pin-gate` → Triggers → Routes
- Add route: `vacation.mbfdhub.com/*` on zone `mbfdhub.com`

## Step 7 — Smoke test

1. Visit `https://vacation.mbfdhub.com` — you should see the MBFD-styled PIN form.
2. Enter a wrong PIN — get a rate-limited error after 5 attempts.
3. Enter the right PIN — redirected to `/board`, which shows the empty state.
4. Click "Go to Import" — upload a small Telestaff CSV, follow the wizard,
   commit. The board should populate.
5. Click into Runs → open the import → roll it back → board returns to empty.

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
