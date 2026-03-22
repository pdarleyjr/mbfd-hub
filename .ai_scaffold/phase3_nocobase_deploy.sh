#!/bin/bash
set -e
cd /root/mbfd-hub

echo "=== SAFETY CHECK: Production UP? ==="
curl -sI https://support.darleyplex.com | head -1

echo ""
echo "=== Phase 3a: Create nocobase_storage database in existing PostgreSQL ==="
docker exec mbfd-hub-pgsql-1 psql -U mbfd_user -c "CREATE DATABASE nocobase_storage;" 2>&1 || echo "DB may already exist, continuing..."

echo ""
echo "=== Phase 3b: Start NocoBase container (no impact on existing services) ==="
docker compose -f compose.yaml -f docker-compose.nocobase.yml up -d nocobase

echo ""
echo "=== Waiting 30s for NocoBase to initialize... ==="
sleep 30

echo ""
echo "=== Phase 3c: Verify NocoBase reachable at localhost:13000 ==="
curl -sI http://localhost:13000 | head -3

echo ""
echo "=== Post-start container status ==="
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'

echo ""
echo "=== SAFETY CHECK: Production still UP? ==="
curl -sI https://support.darleyplex.com | head -1

echo ""
echo "=== Phase 3 COMPLETE ==="
