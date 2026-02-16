#!/bin/bash
# Usage: ./scripts/remote_db.sh "SELECT * FROM users LIMIT 5;"

VPS_IP="145.223.73.170"
CONTAINER_NAME="mbfd-hub-pgsql-1"
DB_USER="mbfd_user"
DB_NAME="mbfd_hub"
QUERY="$1"

if [ -z "$QUERY" ]; then
  echo "Usage: $0 <sql_query>"
  exit 1
fi

ssh root@$VPS_IP "docker exec -i $CONTAINER_NAME psql -U mbfd_user mbfd_hub -c \"$QUERY\""
