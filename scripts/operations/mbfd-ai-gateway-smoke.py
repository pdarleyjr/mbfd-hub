#!/usr/bin/env python3
"""Credential-safe, inference-free smoke checks for the deployed gateway."""

from __future__ import annotations

import http.client
import json
import os
from pathlib import Path


def get(path: str, token: str) -> tuple[int, dict[str, str], dict]:
    connection = http.client.HTTPConnection("127.0.0.1", 11440, timeout=10)
    connection.request(
        "GET",
        path,
        headers={
            "Authorization": f"Bearer {token}",
            "X-Request-ID": "foundation-smoke",
        },
    )
    response = connection.getresponse()
    body = response.read()
    headers = {key.lower(): value for key, value in response.getheaders()}
    connection.close()
    return response.status, headers, json.loads(body)


def main() -> int:
    credential_directory = os.environ.get("CREDENTIALS_DIRECTORY", "")
    if not credential_directory:
        print("FAIL missing systemd credential directory")
        return 2
    token = (Path(credential_directory) / "api-key").read_text(encoding="utf-8").strip()
    if not token:
        print("FAIL empty systemd credential")
        return 2

    expectations = {
        "/health/live": "alive",
        "/health/ready": "ready",
        "/health/backends": None,
        "/v1/models": None,
        "/api/version": None,
    }
    for path, expected_state in expectations.items():
        status, headers, payload = get(path, token)
        if status != 200 or headers.get("x-request-id") != "foundation-smoke":
            print(
                f"FAIL {path} status={status} request_id={headers.get('x-request-id')!r}"
            )
            return 1
        if expected_state is not None and payload.get("status") != expected_state:
            print(f"FAIL {path} state={payload.get('status')!r}")
            return 1
        if path == "/health/backends":
            backend_state = payload["backends"]["ollama-primary"]["service"]
            capability_states = {
                name: payload["capabilities"][name]["state"]
                for name in ("mbfd-general", "mbfd-embeddings", "mbfd-ops-summary")
            }
            if backend_state != "healthy":
                print(f"FAIL {path} ollama-primary={backend_state!r}")
                return 1
            print(
                "PASS readiness "
                f"backend=healthy capabilities={json.dumps(capability_states, sort_keys=True)}"
            )
        if path == "/v1/models":
            model_ids = {item["id"] for item in payload.get("data", [])}
            required = {"mbfd-general", "mbfd-general-deep", "mbfd-embeddings"}
            if not required.issubset(model_ids):
                print(f"FAIL {path} missing compatibility models")
                return 1
        print(f"PASS {path} status=200")

    status, _headers, payload = get("/v1/models", "definitely-invalid-smoke-token")
    classification = payload.get("error", {}).get("classification")
    if status != 401 or classification != "authentication_failed":
        print(f"FAIL invalid-auth status={status} classification={classification!r}")
        return 1
    print("PASS invalid-auth status=401 classification=authentication_failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
