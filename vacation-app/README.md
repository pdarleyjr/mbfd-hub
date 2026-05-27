# MBFD Vacation Selection

> Replaces the **FY25 Vacation Selection Master V6.xlsx** display surface with a
> web app that ingests Telestaff exports of any size and renders the same
> vacation board on desktop and mobile.

- **V1 scope**: Telestaff import + read-only board. No request workflow yet.
- **URL**: `https://vacation.mbfdhub.com`
- **Auth**: single shared department PIN (Cloudflare Worker gate).
- **Hosted on**: GMKtec EVO-X2 (Ubuntu 26), Docker Compose, exposed via the
  existing `mbfdhub-gmktec` Cloudflare Tunnel.

## Repo layout

```
vacation-app/
├── apps/
│   ├── web/        Next.js 15 (SSR + client) — the UI
│   ├── api/        Hono on Node 22 — upload + board JSON
│   ├── worker/     BullMQ consumer — parses uploaded files
│   └── pin-gate/   Cloudflare Worker — PIN form in front of the tunnel
├── packages/
│   ├── db/         Drizzle schema + migrations + seed
│   └── shared/     Zod types shared across apps
├── infra/          docker-compose + nginx + postgres init + cloudflared
├── scripts/        deploy + dev seed + stress fixture
├── tests/          unit (Vitest) + integration (Testcontainers) + e2e (Playwright)
└── docs/           DEPLOYMENT, ADMIN-GUIDE, ARCHITECTURE
```

## Quickstart (local dev)

```bash
# 1. Install
corepack enable
pnpm install

# 2. Copy env
cp .env.example .env
# Fill in R2 credentials + a strong POSTGRES_PASSWORD

# 3. Boot stack
docker compose -f infra/docker-compose.yml up -d vac-postgres vac-redis

# 4. Run migrations + seed
pnpm db:migrate
pnpm db:seed

# 5. Run the apps
pnpm dev
# api    → http://localhost:3001
# worker → tails the queue
# web    → http://localhost:3000
```

## Deploy to GMKtec

See [docs/DEPLOYMENT.md](./docs/DEPLOYMENT.md).

## For the admin

See [docs/ADMIN-GUIDE.md](./docs/ADMIN-GUIDE.md).

## Design + plan

- Spec: [../docs/superpowers/specs/2026-05-27-mbfd-vacation-selection-design.md](../docs/superpowers/specs/2026-05-27-mbfd-vacation-selection-design.md)
- Plan: [../docs/superpowers/plans/2026-05-27-mbfd-vacation-selection-v1-implementation.md](../docs/superpowers/plans/2026-05-27-mbfd-vacation-selection-v1-implementation.md)

## License

Internal — Miami Beach Fire Department.
