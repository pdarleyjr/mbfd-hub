#!/bin/bash
# Baserow User Provisioning Script
# Run on VPS: bash /tmp/baserow_provision.sh

set -e

HOST_HEADER="Host: baserow.support.darleyplex.com"
BASE_URL="http://127.0.0.1:8082"
PSQL="docker exec -u postgres baserow psql -d baserow -t -A"

# Workspace IDs
SUPPORT_WS=131
TRAINING_WS=132

echo "=== Baserow User Provisioning ==="
echo ""

# Function to create user via API and return user ID
create_user() {
    local email="$1"
    local password="$2"
    local name="$3"
    
    # Check if user exists
    local existing_id=$($PSQL -c "SELECT id FROM auth_user WHERE LOWER(email)=LOWER('$email');" 2>/dev/null | tr -d ' ')
    
    if [ -n "$existing_id" ]; then
        echo "EXISTS:$existing_id"
        return
    fi
    
    # Create via API signup
    local result=$(curl -s -H "$HOST_HEADER" -X POST "$BASE_URL/api/user/" \
        -H "Content-Type: application/json" \
        -d "{\"name\":\"$name\",\"email\":\"$email\",\"password\":\"$password\",\"authenticate\":true}")
    
    local user_id=$(echo "$result" | python3 -c "import sys,json; print(json.load(sys.stdin)['user']['id'])" 2>/dev/null)
    
    if [ -n "$user_id" ]; then
        echo "CREATED:$user_id"
    else
        echo "FAILED:$result"
    fi
}

# Function to set user password via psql (using Django's make_password equivalent)
# We'll update passwords via the API after getting a token for each user
# Actually, for existing users we need admin API. Let's get admin token first.

get_admin_token() {
    local result=$(curl -s -H "$HOST_HEADER" -X POST "$BASE_URL/api/user/token-auth/" \
        -H "Content-Type: application/json" \
        -d '{"email":"admin@darleyplex.com","password":"AdminPass123!"}')
    echo "$result" | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])" 2>/dev/null
}

# Function to add user to workspace
add_to_workspace() {
    local user_id="$1"
    local workspace_id="$2"
    local permissions="$3"
    
    # Check if already a member
    local existing=$($PSQL -c "SELECT id FROM core_workspaceuser WHERE user_id=$user_id AND workspace_id=$workspace_id;" 2>/dev/null | tr -d ' ')
    
    if [ -n "$existing" ]; then
        # Update permissions
        $PSQL -c "UPDATE core_workspaceuser SET permissions='$permissions' WHERE user_id=$user_id AND workspace_id=$workspace_id;" >/dev/null
        echo "  Updated permissions to $permissions in workspace $workspace_id"
    else
        # Get next order
        local max_order=$($PSQL -c "SELECT COALESCE(MAX(\"order\"),0)+1 FROM core_workspaceuser WHERE workspace_id=$workspace_id;" 2>/dev/null | tr -d ' ')
        $PSQL -c "INSERT INTO core_workspaceuser (\"order\", workspace_id, user_id, created_on, updated_on, permissions) VALUES ($max_order, $workspace_id, $user_id, NOW(), NOW(), '$permissions');" >/dev/null
        echo "  Added to workspace $workspace_id with $permissions"
    fi
}

# Function to remove user from all workspaces except specified ones
remove_from_other_workspaces() {
    local user_id="$1"
    shift
    local allowed_ws="$@"
    
    local ws_list=$(echo "$allowed_ws" | tr ' ' ',')
    local removed=$($PSQL -c "DELETE FROM core_workspaceuser WHERE user_id=$user_id AND workspace_id NOT IN ($ws_list) RETURNING workspace_id;" 2>/dev/null | tr -d ' ')
    
    if [ -n "$removed" ]; then
        echo "  Removed from workspaces: $removed"
    fi
}

echo "--- Step 1: Creating Users ---"
echo ""

# Support users
declare -A USER_IDS

users_data=(
    "MiguelAnchia@miamibeachfl.gov|Penco1|Miguel Anchia|SUPPORT|ADMIN"
    "RichardQuintela@miamibeachfl.gov|Penco2|Richard Quintela|SUPPORT|ADMIN"
    "PeterDarley@miamibeachfl.gov|Penco3|Peter Darley|SUPPORT|ADMIN"
    "GreciaTrabanino@miamibeachfl.gov|MBFDSupport!|Grecia Trabanino|SUPPORT|ADMIN"
    "geralddeyoung@miamibeachfl.gov|MBFDGerry1|Gerald DeYoung|SUPPORT|MEMBER"
    "danielgato@miamibeachfl.gov|Gato1234!|Daniel Gato|TRAINING|ADMIN"
    "victorwhite@miamibeachfl.gov|Vic1234!|Victor White|TRAINING|ADMIN"
    "ClaudioNavas@miamibeachfl.gov|Flea1234!|Claudio Navas|TRAINING|ADMIN"
    "michaelsica@miamibeachfl.gov|Sica1234!|Michael Sica|TRAINING|ADMIN"
)

for entry in "${users_data[@]}"; do
    IFS='|' read -r email password name workspace role <<< "$entry"
    echo "Processing: $email"
    result=$(create_user "$email" "$password" "$name")
    status=$(echo "$result" | cut -d: -f1)
    uid=$(echo "$result" | cut -d: -f2)
    
    if [ "$status" = "EXISTS" ]; then
        echo "  User already exists (id=$uid)"
    elif [ "$status" = "CREATED" ]; then
        echo "  User created (id=$uid)"
    else
        echo "  FAILED: $result"
        continue
    fi
    
    # Store user ID for workspace assignment
    USER_IDS["$email"]="$uid"
    
    # Determine workspace ID
    if [ "$workspace" = "SUPPORT" ]; then
        ws_id=$SUPPORT_WS
    else
        ws_id=$TRAINING_WS
    fi
    
    # Add to correct workspace
    add_to_workspace "$uid" "$ws_id" "$role"
    
    # Remove from other workspaces (except their assigned one)
    remove_from_other_workspaces "$uid" "$ws_id"
    
    echo ""
done

# Special case: PeterDarley already exists as user 1 with lowercase email
# Check if PeterDarley@miamibeachfl.gov matched user 1
peter_id=$($PSQL -c "SELECT id FROM auth_user WHERE LOWER(email)=LOWER('PeterDarley@miamibeachfl.gov');" 2>/dev/null | tr -d ' ')
if [ "$peter_id" = "1" ]; then
    echo "Note: PeterDarley is user 1 (existing admin). Keeping in both Support and Training as per original setup? No - task says Support ONLY."
    # Peter should be in Support only per the task
    add_to_workspace "1" "$SUPPORT_WS" "ADMIN"
    # Remove from Training
    $PSQL -c "DELETE FROM core_workspaceuser WHERE user_id=1 AND workspace_id=$TRAINING_WS;" >/dev/null 2>&1
    echo "  Removed Peter from Training workspace"
fi

echo ""
echo "--- Step 2: Clean up empty personal workspaces ---"
# Any workspace created automatically for new users (not Support/Training/templates)
# Check for workspaces owned by our users that are empty
for entry in "${users_data[@]}"; do
    IFS='|' read -r email password name workspace role <<< "$entry"
    uid=$($PSQL -c "SELECT id FROM auth_user WHERE LOWER(email)=LOWER('$email');" 2>/dev/null | tr -d ' ')
    if [ -z "$uid" ]; then continue; fi
    
    # Find workspaces where this user is the only member and workspace name matches their name
    personal_ws=$($PSQL -c "
        SELECT w.id, w.name FROM core_workspace w 
        JOIN core_workspaceuser wu ON w.id = wu.workspace_id 
        WHERE wu.user_id = $uid 
        AND w.id NOT IN ($SUPPORT_WS, $TRAINING_WS)
        AND w.name LIKE '%$name%'
    ;" 2>/dev/null | tr -d ' ')
    
    if [ -n "$personal_ws" ]; then
        echo "  Found personal workspace for $email: $personal_ws"
        # Check if it has any tables with data
        for ws_id_name in $personal_ws; do
            ws_id=$(echo "$ws_id_name" | cut -d'|' -f1)
            # Check for database tables in this workspace
            has_tables=$($PSQL -c "SELECT COUNT(*) FROM database_table t JOIN database_database d ON t.database_id=d.id JOIN core_application a ON d.application_ptr_id=a.id WHERE a.workspace_id=$ws_id;" 2>/dev/null | tr -d ' ')
            if [ "$has_tables" = "0" ] || [ -z "$has_tables" ]; then
                echo "    Workspace $ws_id is empty, deleting..."
                $PSQL -c "DELETE FROM core_workspaceuser WHERE workspace_id=$ws_id;" >/dev/null 2>&1
                $PSQL -c "DELETE FROM core_workspace WHERE id=$ws_id;" >/dev/null 2>&1
            else
                echo "    Workspace $ws_id has $has_tables tables, keeping."
            fi
        done
    fi
done

# Also clean up the admin@darleyplex.com user's auto-created workspace
admin_personal=$($PSQL -c "
    SELECT w.id FROM core_workspace w 
    JOIN core_workspaceuser wu ON w.id = wu.workspace_id 
    WHERE wu.user_id = 2 
    AND w.id NOT IN ($SUPPORT_WS, $TRAINING_WS)
;" 2>/dev/null | tr -d ' ')
if [ -n "$admin_personal" ]; then
    for ws_id in $admin_personal; do
        has_tables=$($PSQL -c "SELECT COUNT(*) FROM database_table t JOIN database_database d ON t.database_id=d.id JOIN core_application a ON d.application_ptr_id=a.id WHERE a.workspace_id=$ws_id;" 2>/dev/null | tr -d ' ')
        if [ "$has_tables" = "0" ] || [ -z "$has_tables" ]; then
            echo "  Deleting admin's empty personal workspace $ws_id"
            $PSQL -c "DELETE FROM core_workspaceuser WHERE workspace_id=$ws_id;" >/dev/null 2>&1
            $PSQL -c "DELETE FROM core_workspace WHERE id=$ws_id;" >/dev/null 2>&1
        fi
    done
fi

echo ""
echo "--- Step 3: Disable global workspace creation ---"
# Use admin API
ADMIN_TOKEN=$(get_admin_token)
if [ -n "$ADMIN_TOKEN" ]; then
    curl -s -H "$HOST_HEADER" -H "Authorization: JWT $ADMIN_TOKEN" \
        -X PATCH "$BASE_URL/api/settings/update/" \
        -H "Content-Type: application/json" \
        -d '{"allow_global_workspace_creation": false, "allow_new_signups": false}' | python3 -m json.tool 2>/dev/null | head -10
    echo "  Disabled global workspace creation and new signups"
else
    echo "  WARNING: Could not get admin token to update settings"
fi

echo ""
echo "--- Step 4: Verification ---"
echo ""
echo "Support Services (ws=$SUPPORT_WS) members:"
$PSQL -c "SELECT u.email, wu.permissions FROM core_workspaceuser wu JOIN auth_user u ON wu.user_id=u.id WHERE wu.workspace_id=$SUPPORT_WS ORDER BY u.email;"

echo ""
echo "Training (ws=$TRAINING_WS) members:"
$PSQL -c "SELECT u.email, wu.permissions FROM core_workspaceuser wu JOIN auth_user u ON wu.user_id=u.id WHERE wu.workspace_id=$TRAINING_WS ORDER BY u.email;"

echo ""
echo "All users:"
$PSQL -c "SELECT u.id, u.email, u.is_staff, u.is_active FROM auth_user u ORDER BY u.id;"

echo ""
echo "=== Provisioning Complete ==="
