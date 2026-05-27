#!/usr/bin/env bash
# Run the Telestaff XML bootstrap loader inside the vac-worker container so
# it uses the same Node + tsx + pinned deps as the running worker.
#
# Usage on GMKtec:
#   /opt/mbfd-vacation/scripts/bootstrap-telestaff.sh \
#       /opt/mbfd-vacation/fixtures/telestaff-bootstrap.xml
set -euo pipefail

INPUT="${1:-/opt/mbfd-vacation/fixtures/telestaff-bootstrap.xml}"

if [ ! -f "$INPUT" ]; then
  echo "Input file not found: $INPUT" >&2
  exit 1
fi

CONTAINER_INPUT="/tmp/telestaff-bootstrap.xml"

# Stage the file inside the worker container then run the loader from
# the workspace root (so workspace package imports resolve).
docker cp "$INPUT" vac-worker:"$CONTAINER_INPUT"
docker exec \
  -e DATABASE_URL="postgres://vacation:${POSTGRES_PASSWORD:-vacation}@vac-postgres:5432/vacation" \
  -w /app \
  vac-worker node --import tsx/esm apps/worker/src/scripts/bootstrap-telestaff-xml.ts "$CONTAINER_INPUT"

echo "Bootstrap complete."
