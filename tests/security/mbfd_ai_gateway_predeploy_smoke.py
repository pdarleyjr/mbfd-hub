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
        config_path = MODULE_PATH.parent / "mbfd-ai-gateway.json"
        raw_config = json.loads(config_path.read_text(encoding="utf-8"))
        credentials: dict[str, str] = {}
        for consumer_id, consumer in raw_config["consumers"].items():
            credential_value = f"isolated-predeploy-{consumer_id}-credential"
            credential_path = Path(consumer["credential_file"].replace("%d", temp))
            credential_path.write_text(credential_value, encoding="utf-8")
            credentials[consumer_id] = credential_value

        config = gateway.load_config(
            config_path,
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
                status, _payload = get(config.port, path, credentials["legacy-11440"])
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
