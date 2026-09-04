#!/usr/bin/env python3
"""Start the exact candidate on a disposable port against real Ollama, without inference."""

from __future__ import annotations

import http.client
import importlib.util
import json
import sys
import tempfile
import threading
from pathlib import Path

MODULE_PATH = (
    Path(__file__).parents[2] / "scripts" / "operations" / "mbfd_ai_gateway.py"
)
SPEC = importlib.util.spec_from_file_location("mbfd_ai_gateway", MODULE_PATH)
gateway = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = gateway
SPEC.loader.exec_module(gateway)


def get(port: int, path: str, token: str) -> tuple[int, dict]:
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=15)
    connection.request("GET", path, headers={"Authorization": f"Bearer {token}"})
    response = connection.getresponse()
    body = json.loads(response.read())
    connection.close()
    return response.status, body


def main() -> int:
    with tempfile.TemporaryDirectory() as temp:
        credential = Path(temp) / "api-key"
        credential.write_text("isolated-predeploy-credential", encoding="utf-8")
        config = gateway.load_config(
            MODULE_PATH.parent / "mbfd-ai-gateway.json",
            {"CREDENTIALS_DIRECTORY": temp},
        )
        config.listeners = ("127.0.0.1",)
        config.port = 19140
        app = gateway.GatewayApplication(config)
        server = gateway.GatewayHTTPServer(
            ("127.0.0.1", config.port), gateway.GatewayRequestHandler, app
        )
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            for path in (
                "/health/live",
                "/health/ready",
                "/health/backends",
                "/v1/models",
                "/api/version",
            ):
                status, _payload = get(
                    config.port, path, "isolated-predeploy-credential"
                )
                if status != 200:
                    raise RuntimeError(f"{path} returned {status}")
                print(f"PASS {path} status=200")
            status, payload = get(config.port, "/v1/models", "invalid")
            if (
                status != 401
                or payload["error"]["classification"] != "authentication_failed"
            ):
                raise RuntimeError("invalid-auth contract failed")
            print("PASS invalid-auth status=401 classification=authentication_failed")
        finally:
            server.shutdown()
            server.server_close()
            thread.join(2)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
