#!/usr/bin/env python3
"""Credential-safe, inference-free smoke checks for the deployed gateway."""

from __future__ import annotations

import http.client
import json
import os
from pathlib import Path


def request(
    method: str,
    path: str,
    token: str,
    request_id: str,
    *,
    capability: str | None = None,
    payload: dict | None = None,
) -> tuple[int, dict[str, str], dict]:
    connection = http.client.HTTPConnection("127.0.0.1", 11440, timeout=10)
    headers = {
        "Authorization": f"Bearer {token}",
        "X-Request-ID": request_id,
    }
    body = None
    if capability is not None:
        headers["X-MBFD-Capability"] = capability
    if payload is not None:
        headers["Content-Type"] = "application/json"
        body = json.dumps(payload, separators=(",", ":"))
    connection.request(
        method,
        path,
        body=body,
        headers=headers,
    )
    response = connection.getresponse()
    body = response.read()
    headers = {key.lower(): value for key, value in response.getheaders()}
    connection.close()
    return response.status, headers, json.loads(body)


def get(
    path: str, token: str, request_id: str = "foundation-smoke"
) -> tuple[int, dict[str, str], dict]:
    return request("GET", path, token, request_id)


def main() -> int:
    credential_directory = os.environ.get("CREDENTIALS_DIRECTORY", "")
    if not credential_directory:
        print("FAIL missing systemd credential directory")
        return 2
    credential_files = {
        "legacy-11440": "api-key",
        "sports-intelligence": "sports-intelligence-api-key",
        "mbfd-hub": "mbfd-hub-api-key",
        "media-control": "media-control-api-key",
        "hermes": "hermes-api-key",
        "command": "command-api-key",
        "eoc": "eoc-api-key",
        "ts-orchestrator": "ts-orchestrator-api-key",
        "mbfd-support-ai": "mbfd-support-ai-api-key",
        "external-coding": "external-coding-api-key",
    }
    credentials: dict[str, str] = {}
    for consumer, filename in credential_files.items():
        path = Path(credential_directory) / filename
        if not path.is_file() or path.is_symlink():
            print(f"FAIL missing or symlinked systemd credential consumer={consumer}")
            return 2
        token = path.read_text(encoding="utf-8").strip()
        if not token:
            print(f"FAIL empty systemd credential consumer={consumer}")
            return 2
        credentials[consumer] = token

    token = credentials["legacy-11440"]

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
            backend_states = {
                name: payload["backends"][name]["service"]
                for name in ("ollama-primary", "ollama-eoc", "ollama-prm-sports")
            }
            capability_states = {
                name: payload["capabilities"][name]["state"]
                for name in (
                    "mbfd-general",
                    "mbfd-eoc-grounding",
                    "mbfd-embeddings",
                    "mbfd-ops-summary",
                    "prm-sports-research",
                )
            }
            if any(state != "healthy" for state in backend_states.values()):
                print(
                    f"FAIL {path} backends={json.dumps(backend_states, sort_keys=True)}"
                )
                return 1
            print(
                "PASS readiness "
                f"backends={json.dumps(backend_states, sort_keys=True)} "
                f"capabilities={json.dumps(capability_states, sort_keys=True)}"
            )
        if path == "/v1/models":
            model_ids = {item["id"] for item in payload.get("data", [])}
            required = {"mbfd-general", "mbfd-general-deep", "mbfd-embeddings"}
            if not required.issubset(model_ids):
                print(f"FAIL {path} missing compatibility models")
                return 1
        print(f"PASS {path} status=200")

    for consumer, consumer_token in credentials.items():
        request_id = f"auth-{consumer}"
        status, headers, _payload = get("/v1/models", consumer_token, request_id)
        if status != 200 or headers.get("x-request-id") != request_id:
            print(
                f"FAIL consumer-auth consumer={consumer} status={status} "
                f"request_id={headers.get('x-request-id')!r}"
            )
            return 1
        print(f"PASS consumer-auth consumer={consumer} status=200")

        denial_id = f"incorrect-capability-{consumer}"
        status, headers, payload = request(
            "POST",
            "/mbfd/jobs/image",
            consumer_token,
            denial_id,
            capability="mbfd-image",
            payload={"model": "mbfd-image", "prompt": "admission-only-smoke"},
        )
        classification = payload.get("error", {}).get("classification")
        if (
            status != 403
            or classification != "admission_denied"
            or headers.get("x-request-id") != denial_id
        ):
            print(
                f"FAIL incorrect-capability consumer={consumer} status={status} "
                f"classification={classification!r}"
            )
            return 1
        print(
            f"PASS incorrect-capability consumer={consumer} "
            "status=403 classification=admission_denied"
        )

    status, _headers, payload = get("/v1/models", "definitely-invalid-smoke-token")
    classification = payload.get("error", {}).get("classification")
    if status != 401 or classification != "authentication_failed":
        print(f"FAIL invalid-auth status={status} classification={classification!r}")
        return 1
    print("PASS invalid-auth status=401 classification=authentication_failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
