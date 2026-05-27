#!/usr/bin/env bash
# Idempotent re-deploy script for GMKtec.
# Run as the user that owns /opt/mbfd-vacation.
set -euo pipefail

REPO_DIR="${REPO_DIR:-/opt/mbfd-vacation}"

cd "$REPO_DIR"

# Load env so DATABASE_URL etc. are available for the migration step.
if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env
  set +a
fi

echo "→ Pulling latest…"
git pull --ff-only

echo "→ Building & restarting Docker stack…"
docker compose \
  -f infra/docker-compose.yml \
  -f infra/docker-compose.prod.yml \
  up -d --build

echo "→ Waiting for Postgres to be healthy…"
for i in {1..30}; do
  if docker compose -f infra/docker-compose.yml exec -T vac-postgres pg_isready -U "${POSTGRES_USER:-vacation}" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "→ Running migrations (tsx)…"
docker compose -f infra/docker-compose.yml exec -T vac-api \
  node --import tsx/esm packages/db/src/migrate.ts || true

echo "→ Seeding leave codes (tsx)…"
docker compose -f infra/docker-compose.yml exec -T vac-api \
  node --import tsx/esm packages/db/src/seed.ts || true

echo "→ Reloading nginx…"
docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml restart vac-nginx

echo "✓ Deploy complete."
