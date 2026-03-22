#!/bin/bash
set -e
cd /root/mbfd-hub

TAG=${1:-v1-pre-daily-checkout}
BACKUP=${2}

echo "Rolling back to $TAG..."
git checkout "$TAG"

if [ -n "$BACKUP" ]; then
    echo "Restoring database from $BACKUP..."
    docker compose exec -T pgsql psql -U mbfd_user mbfd_hub < "$BACKUP"
fi

docker compose restart app
echo "Rollback complete!"
