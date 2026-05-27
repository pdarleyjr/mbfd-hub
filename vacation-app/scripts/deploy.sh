#!/usr/bin/env bash
# Idempotent re-deploy script for GMKtec.
# Run as the user that owns /opt/mbfd-vacation.
set -euo pipefail

REPO_DIR="${REPO_DIR:-/opt/mbfd-vacation}"

cd "$REPO_DIR"

echo "→ Pulling latest…"
git pull --ff-only

echo "→ Building & restarting Docker stack…"
docker compose \
  -f infra/docker-compose.yml \
  -f infra/docker-compose.prod.yml \
  up -d --build

echo "→ Running migrations…"
docker compose -f infra/docker-compose.yml run --rm \
  -e DATABASE_URL="postgres://${POSTGRES_USER:-vacation}:${POSTGRES_PASSWORD}@vac-postgres:5432/${POSTGRES_DB:-vacation}" \
  vac-api node packages/db/dist/migrate.js || true

echo "→ Seeding leave codes…"
docker compose -f infra/docker-compose.yml run --rm \
  -e DATABASE_URL="postgres://${POSTGRES_USER:-vacation}:${POSTGRES_PASSWORD}@vac-postgres:5432/${POSTGRES_DB:-vacation}" \
  vac-api node packages/db/dist/seed.js || true

echo "→ Reloading nginx…"
docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml restart vac-nginx

echo "✓ Deploy complete."
