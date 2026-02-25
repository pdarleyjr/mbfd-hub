#!/bin/bash
# Get Baserow JWT then create a permanent database API token
JWT=$(curl -s -X POST https://baserow.mbfdhub.com/api/user/token-auth/ \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@darleyplex.com","password":"admin123"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')

echo "JWT obtained: ${JWT:0:20}..."

# Get groups (old API) or workspaces
GROUPS_RAW=$(curl -s https://baserow.support.darleyplex.com/api/groups/ \
  -H "Authorization: JWT $JWT")
echo "Groups: $GROUPS_RAW" | head -c 300
echo ""

WORKSPACE_ID=$(echo "$GROUPS_RAW" | python3 -c 'import sys,json; ws=json.load(sys.stdin); print(ws[0]["id"])' 2>/dev/null)

if [ -z "$WORKSPACE_ID" ]; then
  # Try workspaces endpoint
  WS_RAW=$(curl -s https://baserow.support.darleyplex.com/api/workspaces/ \
    -H "Authorization: JWT $JWT")
  echo "Workspaces: $WS_RAW" | head -c 300
  WORKSPACE_ID=$(echo "$WS_RAW" | python3 -c 'import sys,json; ws=json.load(sys.stdin); print(ws[0]["id"])' 2>/dev/null)
fi

echo "Workspace/Group ID: $WORKSPACE_ID"

# Create a database API token
RESULT=$(curl -s -X POST https://baserow.support.darleyplex.com/api/database/tokens/ \
  -H 'Content-Type: application/json' \
  -H "Authorization: JWT $JWT" \
  -d "{\"name\":\"NocoBase Integration\",\"group\":$WORKSPACE_ID}")

echo "Token result: $RESULT"
TOKEN=$(echo "$RESULT" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("key","ERROR: "+str(d)))' 2>/dev/null)
echo "BASEROW_TOKEN=$TOKEN"
