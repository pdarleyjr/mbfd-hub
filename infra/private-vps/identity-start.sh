#!/bin/sh
set -eu
export AUTHENTIK_POSTGRESQL__PASSWORD="$(cat /run/secrets/identity_db)"
export AUTHENTIK_SECRET_KEY="$(cat /run/secrets/identity_key)"
exec dumb-init -- ak "$@"
