#!/bin/bash
# Generate a random token and insert it into Baserow database (max 32 chars)
MYTOKEN=$(python3 -c 'import secrets; print(secrets.token_hex(16))')
echo "Generated token: $MYTOKEN"

# Insert token into Baserow DB for workspace 131 (Support Services)
USERID=$(echo "SELECT id FROM auth_user WHERE email='peterdarley@miamibeachfl.gov';" | \
  docker exec -i baserow bash -c 'su -c "psql -d baserow -t" postgres' | tr -d ' \n')
echo "User ID: $USERID"

echo "INSERT INTO database_token (name, key, created, workspace_id, user_id, handled_calls) VALUES ('NocoBase Integration', '$MYTOKEN', NOW(), 131, $USERID, 0) RETURNING key;" | \
  docker exec -i baserow bash -c 'su -c "psql -d baserow" postgres'

echo "BASEROW_TOKEN=$MYTOKEN"
# Save to .env
grep -q BASEROW_TOKEN /root/mbfd-hub/.env && \
  sed -i "s/BASEROW_TOKEN=.*/BASEROW_TOKEN=$MYTOKEN/" /root/mbfd-hub/.env || \
  echo "BASEROW_TOKEN=$MYTOKEN" >> /root/mbfd-hub/.env
echo "Saved to /root/mbfd-hub/.env"
