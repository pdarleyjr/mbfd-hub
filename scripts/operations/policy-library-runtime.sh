#!/usr/bin/env bash
# Sourced only from the exact approved production checkout.
set -euo pipefail
HUB_COMPOSE_ARGS=(-f compose.prod.image.yaml)
if [[ "$POLICY_LIBRARY_ENABLED" == true ]]; then
  [[ "$POLICY_LIBRARY_REVISION" =~ ^[0-9a-f]{40}$ ]]
  [[ "$POLICY_LIBRARY_CARRIER_DIGEST" =~ ^sha256:[0-9a-f]{64}$ ]]
  [[ "$POLICY_LIBRARY_BASE_IMAGE" =~ ^ghcr\.io/pdarleyjr/mbfd-hub@sha256:[0-9a-f]{64}$ ]]
  HUB_COMPOSE_ARGS+=(-f compose.prod.policy-library.yaml)
  test "$(docker image inspect --format '{{index .Config.Labels "mbfd.policy-library.revision"}}' "$IMAGE_REF")" = "$POLICY_LIBRARY_REVISION"
  test "$(docker image inspect --format '{{index .Config.Labels "mbfd.policy-library.base-image"}}' "$IMAGE_REF")" = "$POLICY_LIBRARY_BASE_IMAGE"
  test "$(docker image inspect --format '{{index .Config.Labels "mbfd.policy-library.carrier"}}' "$IMAGE_REF")" = "ghcr.io/pdarleyjr/mbfd-policy-library-source@$POLICY_LIBRARY_CARRIER_DIGEST"
fi
