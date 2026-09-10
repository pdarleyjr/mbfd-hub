#!/usr/bin/env bash
set -euo pipefail
umask 077

root="${AUTHENTIK_ROOT:-/opt/mbfd/authentik}"
environment="${AUTHENTIK_ENV_FILE:-/etc/mbfd/authentik.env}"
output_environment="${AUTHENTIK_HUB_ENV_FILE:-/etc/mbfd/authentik-hub.env}"
break_glass_environment="${AUTHENTIK_BREAK_GLASS_FILE:-/etc/mbfd/authentik-break-glass.env}"
bind_port="${AUTHENTIK_BIND_PORT:-9000}"
public_url="${AUTHENTIK_PUBLIC_URL:-https://auth.mbfdhub.com}"
hub_redirect_uri="${HUB_REDIRECT_URI:-https://www.mbfdhub.com/auth/identity/callback}"
hub_post_logout_uri="${HUB_POST_LOGOUT_URI:-https://www.mbfdhub.com/login}"
cloudflare_redirect_uri="${CLOUDFLARE_ACCESS_REDIRECT_URI:-}"
api="http://127.0.0.1:${bind_port}/api/v3"

for command in curl docker jq openssl; do
  command -v "$command" >/dev/null || { echo "Required command is unavailable: $command" >&2; exit 1; }
done
[[ -r "$environment" ]] || { echo "Authentik environment is not readable." >&2; exit 1; }

bootstrap_token="$(sed -n 's/^AUTHENTIK_BOOTSTRAP_TOKEN=//p' "$environment")"
bootstrap_password="$(sed -n 's/^AUTHENTIK_BOOTSTRAP_PASSWORD=//p' "$environment")"
[[ -n "$bootstrap_token" && -n "$bootstrap_password" ]] || {
  echo "One-time bootstrap credentials are absent; tenant configuration will not guess or replace them." >&2
  exit 1
}

work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
admin_config="$work/admin.curl"
printf 'header = "Authorization: Bearer %s"\nheader = "Accept: application/json"\n' "$bootstrap_token" > "$admin_config"

api_get() {
  curl --fail --silent --show-error --config "$admin_config" "$api$1"
}

api_write() {
  local method="$1" path="$2" body="$3"
  echo "Configuring $path" >&2
  curl --fail --silent --show-error --config "$admin_config" \
    --request "$method" --header 'Content-Type: application/json' \
    --data-binary "@$body" "$api$path"
}

wait_for_flow_id() {
  local slug="$1" value=''
  for _ in $(seq 1 60); do
    value="$(api_get '/flows/instances/?page_size=100' | jq -r --arg slug "$slug" \
      '[.results[] | select(.slug == $slug)] | if length == 1 then .[0].pk else empty end')"
    [[ -n "$value" ]] && { printf '%s' "$value"; return; }
    sleep 2
  done
  echo "Required flow was not applied: $slug" >&2
  exit 1
}

authentication_flow="$(wait_for_flow_id default-authentication-flow)"
authorization_flow="$(wait_for_flow_id default-provider-authorization-implicit-consent)"
invalidation_flow="$(wait_for_flow_id default-provider-invalidation-flow)"
recovery_flow="$(wait_for_flow_id mbfd-identity-recovery)"
signing_key="$(api_get '/crypto/certificatekeypairs/?page_size=100' | jq -er \
  '[.results[] | select(.has_key == true or .name == "authentik Self-signed Certificate")] | if length >= 1 then .[0].pk else error("signing key missing") end')"
scope_mappings="$(api_get '/propertymappings/provider/scope/?page_size=100' | jq -cer \
  '[.results[] | select(.scope_name == "openid" or .scope_name == "profile" or .scope_name == "email") | .pk] | if length == 3 then . else error("required OIDC scopes missing") end')"

provider="$(api_get '/providers/oauth2/?page_size=100' | jq -cer '[.results[] | select(.name == "MBFD Hub")]')"
if [[ "$(jq 'length' <<<"$provider")" -eq 0 ]]; then
  client_id="mbfd-hub-$(openssl rand -hex 12)"
  client_secret="$(openssl rand -base64 48 | tr -d '\n')"
  jq -n \
    --arg name 'MBFD Hub' \
    --arg authentication_flow "$authentication_flow" \
    --arg authorization_flow "$authorization_flow" \
    --arg invalidation_flow "$invalidation_flow" \
    --arg signing_key "$signing_key" \
    --arg client_id "$client_id" \
    --arg client_secret "$client_secret" \
    --arg redirect "$hub_redirect_uri" \
    --arg logout "$hub_post_logout_uri" \
    --argjson mappings "$scope_mappings" \
    '{name:$name,authentication_flow:$authentication_flow,authorization_flow:$authorization_flow,
      invalidation_flow:$invalidation_flow,property_mappings:$mappings,client_type:"confidential",
      grant_types:["authorization_code"],client_id:$client_id,client_secret:$client_secret,
      include_claims_in_id_token:true,signing_key:$signing_key,
      redirect_uris:[{matching_mode:"strict",url:$redirect}],logout_uri:$logout,
      logout_method:"frontchannel",sub_mode:"user_uuid",issuer_mode:"per_provider"}' > "$work/provider.json"
  provider="$(api_write POST '/providers/oauth2/' "$work/provider.json")"
else
  [[ "$(jq 'length' <<<"$provider")" -eq 1 ]] || { echo 'MBFD Hub provider is ambiguous.' >&2; exit 1; }
  provider="$(jq -c '.[0]' <<<"$provider")"
  client_id="$(jq -er '.client_id' <<<"$provider")"
  client_secret="$(jq -er '.client_secret' <<<"$provider")"
fi
provider_id="$(jq -er '.pk' <<<"$provider")"

application="$(api_get '/core/applications/?page_size=100' | jq -cer '[.results[] | select(.slug == "mbfd-hub")]')"
jq -n --arg name 'MBFD Hub' --arg slug 'mbfd-hub' --argjson provider "$provider_id" \
  '{name:$name,slug:$slug,provider:$provider,meta_description:"Miami Beach Fire Department operations hub",meta_launch_url:"https://www.mbfdhub.com"}' > "$work/application.json"
if [[ "$(jq 'length' <<<"$application")" -eq 0 ]]; then
  api_write POST '/core/applications/' "$work/application.json" >/dev/null
elif [[ "$(jq 'length' <<<"$application")" -eq 1 ]]; then
  api_write PATCH '/core/applications/mbfd-hub/' "$work/application.json" >/dev/null
else
  echo 'MBFD Hub application is ambiguous.' >&2
  exit 1
fi

cloudflare_client_id=''
cloudflare_client_secret=''
if [[ -n "$cloudflare_redirect_uri" ]]; then
  cloudflare_provider="$(api_get '/providers/oauth2/?page_size=100' | jq -cer '[.results[] | select(.name == "Cloudflare Access")]')"
  if [[ "$(jq 'length' <<<"$cloudflare_provider")" -eq 0 ]]; then
    cloudflare_client_id="cloudflare-access-$(openssl rand -hex 12)"
    cloudflare_client_secret="$(openssl rand -base64 48 | tr -d '\n')"
    jq -n \
      --arg authentication_flow "$authentication_flow" \
      --arg authorization_flow "$authorization_flow" \
      --arg invalidation_flow "$invalidation_flow" \
      --arg signing_key "$signing_key" \
      --arg client_id "$cloudflare_client_id" \
      --arg client_secret "$cloudflare_client_secret" \
      --arg redirect "$cloudflare_redirect_uri" \
      --argjson mappings "$scope_mappings" \
      '{name:"Cloudflare Access",authentication_flow:$authentication_flow,authorization_flow:$authorization_flow,
        invalidation_flow:$invalidation_flow,property_mappings:$mappings,client_type:"confidential",
        grant_types:["authorization_code"],client_id:$client_id,client_secret:$client_secret,
        include_claims_in_id_token:true,signing_key:$signing_key,
        redirect_uris:[{matching_mode:"strict",url:$redirect}],
        logout_method:"frontchannel",sub_mode:"user_uuid",issuer_mode:"per_provider"}' > "$work/cloudflare-provider.json"
    cloudflare_provider="$(api_write POST '/providers/oauth2/' "$work/cloudflare-provider.json")"
  else
    [[ "$(jq 'length' <<<"$cloudflare_provider")" -eq 1 ]] || { echo 'Cloudflare Access provider is ambiguous.' >&2; exit 1; }
    cloudflare_provider="$(jq -c '.[0]' <<<"$cloudflare_provider")"
    cloudflare_client_id="$(jq -er '.client_id' <<<"$cloudflare_provider")"
    cloudflare_client_secret="$(jq -er '.client_secret' <<<"$cloudflare_provider")"
  fi
  cloudflare_provider_id="$(jq -er '.pk' <<<"$cloudflare_provider")"
  cloudflare_application="$(api_get '/core/applications/?page_size=100' | jq -cer '[.results[] | select(.slug == "cloudflare-access")]')"
  jq -n --argjson provider "$cloudflare_provider_id" \
    '{name:"Cloudflare Access",slug:"cloudflare-access",provider:$provider,meta_description:"Additional MBFD Identity login method for Cloudflare Access",meta_hide:true}' > "$work/cloudflare-application.json"
  if [[ "$(jq 'length' <<<"$cloudflare_application")" -eq 0 ]]; then
    api_write POST '/core/applications/' "$work/cloudflare-application.json" >/dev/null
  elif [[ "$(jq 'length' <<<"$cloudflare_application")" -eq 1 ]]; then
    api_write PATCH '/core/applications/cloudflare-access/' "$work/cloudflare-application.json" >/dev/null
  else
    echo 'Cloudflare Access application is ambiguous.' >&2
    exit 1
  fi
fi

brand="$(api_get '/core/brands/?page_size=100' | jq -cer '[.results[] | select(.default == true)] | if length == 1 then .[0] else error("default brand missing or ambiguous") end')"
brand_id="$(jq -er '.brand_uuid' <<<"$brand")"
jq -n \
  --arg domain 'auth.mbfdhub.com' \
  --arg title 'MBFD Identity' \
  --arg logo 'https://www.mbfdhub.com/images/mbfd-logo.png' \
  --arg css ':root { --ak-accent: #991b1b; } .pf-c-login__main-header { border-top: 5px solid #b08d3b; }' \
  --arg recovery "$recovery_flow" \
  '{domain:$domain,default:true,branding_title:$title,branding_logo:$logo,branding_custom_css:$css,flow_recovery:$recovery}' > "$work/brand.json"
api_write PATCH "/core/brands/$brand_id/" "$work/brand.json" >/dev/null

service_accounts="$(api_get '/core/users/?type=service_account&page_size=100' | jq -cer \
  '[.results[] | select(.name == "MBFD Hub Identity Service")]')"
if [[ "$(jq 'length' <<<"$service_accounts")" -eq 0 ]]; then
  service_response="$(api_write POST '/core/users/service_account/' <(jq -n \
    '{name:"MBFD Hub Identity Service",create_group:false,expiring:false}'))"
  service_user_pk="$(jq -er '.user_pk' <<<"$service_response")"
elif [[ "$(jq 'length' <<<"$service_accounts")" -eq 1 ]]; then
  service_user_pk="$(jq -er '.[0].pk' <<<"$service_accounts")"
else
  echo 'MBFD Hub service account is ambiguous.' >&2
  exit 1
fi

role="$(api_get '/rbac/roles/?page_size=100' | jq -cer '[.results[] | select(.name == "MBFD Hub Identity Service")]')"
if [[ "$(jq 'length' <<<"$role")" -eq 0 ]]; then
  role="$(api_write POST '/rbac/roles/' <(jq -n '{name:"MBFD Hub Identity Service"}'))"
elif [[ "$(jq 'length' <<<"$role")" -eq 1 ]]; then
  role="$(jq -c '.[0]' <<<"$role")"
else
  echo 'MBFD Hub service role is ambiguous.' >&2
  exit 1
fi
role_id="$(jq -er '.pk' <<<"$role")"

permissions='[
  ["authentik_core","user","add_user"],
  ["authentik_core","user","change_user"],
  ["authentik_core","user","view_user"],
  ["authentik_core","user","reset_user_password"],
  ["authentik_core","authenticatedsession","view_authenticatedsession"],
  ["authentik_core","authenticatedsession","delete_authenticatedsession"],
  ["authentik_stages_authenticator_static","staticdevice","view_staticdevice"],
  ["authentik_stages_authenticator_static","staticdevice","delete_staticdevice"],
  ["authentik_stages_authenticator_totp","totpdevice","view_totpdevice"],
  ["authentik_stages_authenticator_totp","totpdevice","delete_totpdevice"],
  ["authentik_stages_authenticator_webauthn","webauthndevice","view_webauthndevice"],
  ["authentik_stages_authenticator_webauthn","webauthndevice","delete_webauthndevice"]
]'
permission_names='[]'
while IFS=$'\t' read -r app_label model codename; do
  permission_id="$(api_get "/rbac/permissions/?search=$codename&page_size=100" | jq -er \
    --arg app "$app_label" --arg model "$model" --arg codename "$codename" \
    '[.results[] | select(.app_label == $app and .model == $model and .codename == $codename)]
      | if length == 1 then .[0].id else error("required service permission missing or ambiguous") end')"
  permission_names="$(jq -c --arg name "$app_label.$codename" '. + [$name]' <<<"$permission_names")"
done < <(jq -r '.[] | @tsv' <<<"$permissions")
api_write POST "/rbac/permissions/assigned_by_roles/$role_id/assign/" \
  <(jq -n --argjson permissions "$permission_names" '{permissions:$permissions}') >/dev/null
api_write POST "/rbac/roles/$role_id/add_user/" \
  <(jq -n --argjson pk "$service_user_pk" '{pk:$pk}') >/dev/null

service_tokens="$(api_get '/core/tokens/?page_size=100' | jq -cer \
  '[.results[] | select(.identifier == "mbfd-hub-identity-api")]')"
if [[ "$(jq 'length' <<<"$service_tokens")" -eq 0 ]]; then
  api_write POST '/core/tokens/' <(jq -n --argjson user "$service_user_pk" \
    '{identifier:"mbfd-hub-identity-api",intent:"api",user:$user,description:"Hub identity lifecycle API",expiring:false}') >/dev/null
elif [[ "$(jq 'length' <<<"$service_tokens")" -ne 1 ]]; then
  echo 'MBFD Hub API token is ambiguous.' >&2
  exit 1
fi
service_token="$(api_get '/core/tokens/mbfd-hub-identity-api/view_key/' | jq -er '.key')"

service_password_identifier="$(api_get '/core/tokens/?page_size=100' | jq -r --argjson user "$service_user_pk" \
  '.results[] | select(.user == $user and .intent == "app_password") | .identifier' | head -n 1)"
if [[ -n "$service_password_identifier" ]]; then
  api_write DELETE "/core/tokens/$service_password_identifier/" /dev/null >/dev/null
fi

service_config="$work/service.curl"
printf 'header = "Authorization: Bearer %s"\nheader = "Accept: application/json"\n' "$service_token" > "$service_config"
curl --fail --silent --show-error --config "$service_config" "$api/core/users/?page_size=1" >/dev/null
curl --fail --silent --show-error --config "$service_config" "$api/core/authenticated_sessions/?page_size=1" >/dev/null
curl --fail --silent --show-error --config "$service_config" "$api/authenticators/admin/all/?user=$service_user_pk" >/dev/null

issuer="${public_url%/}/application/o/mbfd-hub/"
{
  printf 'AUTHENTIK_API_URL=%s\n' "${public_url%/}"
  printf 'AUTHENTIK_API_TOKEN=%s\n' "$service_token"
  printf 'AUTHENTIK_CLIENT_ID=%s\n' "$client_id"
  printf 'AUTHENTIK_CLIENT_SECRET=%s\n' "$client_secret"
  printf 'AUTHENTIK_ISSUER=%s\n' "$issuer"
  printf 'AUTHENTIK_REDIRECT_URI=%s\n' "$hub_redirect_uri"
  printf 'AUTHENTIK_POST_LOGOUT_REDIRECT_URI=%s\n' "$hub_post_logout_uri"
  if [[ -n "$cloudflare_client_id" ]]; then
    printf 'AUTHENTIK_CLOUDFLARE_CLIENT_ID=%s\n' "$cloudflare_client_id"
    printf 'AUTHENTIK_CLOUDFLARE_CLIENT_SECRET=%s\n' "$cloudflare_client_secret"
    printf 'AUTHENTIK_CLOUDFLARE_ISSUER=%s\n' "${public_url%/}/application/o/cloudflare-access/"
  fi
} > "$work/hub.env"
install -m 0640 "$work/hub.env" "$output_environment"

{
  printf 'AUTHENTIK_BREAK_GLASS_USER=akadmin\n'
  printf 'AUTHENTIK_BREAK_GLASS_PASSWORD=%s\n' "$bootstrap_password"
} > "$work/break-glass.env"
install -m 0600 "$work/break-glass.env" "$break_glass_environment"

api_write DELETE '/core/tokens/authentik-bootstrap-token/' /dev/null >/dev/null
sed -i '/^AUTHENTIK_BOOTSTRAP_PASSWORD=/d; /^AUTHENTIK_BOOTSTRAP_TOKEN=/d; /^AUTHENTIK_BOOTSTRAP_EMAIL=/d' "$environment"
docker compose --env-file "$environment" -f "$root/compose.yaml" up -d --force-recreate --remove-orphans >/dev/null

echo 'TENANT_BRAND=PASS'
echo 'HUB_OIDC_CLIENT=PASS'
[[ -z "$cloudflare_redirect_uri" ]] || echo 'CLOUDFLARE_OIDC_CLIENT=PASS'
echo 'API_SERVICE_AUTH=PASS'
echo 'BOOTSTRAP_API_TOKEN_RETIRED=PASS'
