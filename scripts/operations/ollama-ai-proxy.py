#!/usr/bin/env python3
"""Authenticated, loopback-only reverse proxy for the local Ollama API."""

import http.server
import hmac
import ipaddress
import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


def validate_upstream(value: str) -> str:
    """Accept one fixed HTTP loopback origin, never a request-selected host."""
    parsed = urllib.parse.urlsplit(value)
    if parsed.scheme != "http" or not parsed.hostname:
        raise ValueError("OLLAMA_PROXY_UPSTREAM must be an HTTP loopback origin")
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError("OLLAMA_PROXY_UPSTREAM must not contain credentials, query, or fragment")
    if parsed.path not in ("", "/"):
        raise ValueError("OLLAMA_PROXY_UPSTREAM must not contain a path")
    if parsed.hostname != "localhost":
        try:
            if not ipaddress.ip_address(parsed.hostname).is_loopback:
                raise ValueError("OLLAMA_PROXY_UPSTREAM must resolve to loopback")
        except ValueError as error:
            raise ValueError("OLLAMA_PROXY_UPSTREAM must resolve to loopback") from error
    try:
        port = parsed.port
    except ValueError as error:
        raise ValueError("OLLAMA_PROXY_UPSTREAM has an invalid port") from error
    return urllib.parse.urlunsplit(("http", parsed.netloc, "", "", ""))


def relative_request_target(value: str) -> str:
    """Return one origin-form path/query and reject absolute or protocol-relative targets."""
    parsed = urllib.parse.urlsplit(value)
    if parsed.scheme or parsed.netloc or not parsed.path.startswith("/") or parsed.path.startswith("//"):
        raise ValueError("request target must be a relative API path")
    return urllib.parse.urlunsplit(("", "", parsed.path, parsed.query, ""))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Ollama should not redirect; following redirects could escape loopback."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


UPSTREAM = validate_upstream(
    os.environ.get("OLLAMA_PROXY_UPSTREAM", "http://127.0.0.1:11434")
)
OPENER = urllib.request.build_opener(NoRedirect)
LISTEN_HOST = os.environ.get("OLLAMA_PROXY_HOST", "127.0.0.1")
LISTEN_PORT = int(os.environ.get("OLLAMA_PROXY_PORT", "11440"))


def read_api_key() -> str:
    credential_path = os.environ.get("OLLAMA_PROXY_API_KEY_FILE")
    if credential_path:
        return Path(credential_path).read_text(encoding="utf-8").strip()

    return os.environ.get("OLLAMA_PROXY_API_KEY", "").strip()


API_KEY = read_api_key()


def log(message: str) -> None:
    print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {message}", flush=True)


class Handler(http.server.BaseHTTPRequestHandler):
    def _check_auth(self) -> bool:
        authorization = self.headers.get("Authorization", "")
        return bool(
            API_KEY
            and authorization.startswith("Bearer ")
            and hmac.compare_digest(authorization[7:], API_KEY)
        )

    def _send_json_error(self, status: int, message: str, error_type: str) -> None:
        data = json.dumps({"error": {"message": message, "type": error_type}}).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _proxy(self, method: str) -> None:
        if not self._check_auth():
            self._send_json_error(401, "Invalid or missing API key", "authentication_error")
            log(f"401 UNAUTHORIZED {method} {self.path}")
            return

        length = int(self.headers.get("Content-Length", 0) or 0)
        body = self.rfile.read(length) if length > 0 else None
        headers = {
            key: value
            for key, value in self.headers.items()
            if key.lower()
            not in ("host", "authorization", "content-length", "connection", "transfer-encoding")
        }
        try:
            target = relative_request_target(self.path)
        except ValueError:
            self._send_json_error(400, "Invalid request target", "invalid_request_error")
            return
        request = urllib.request.Request(
            f"{UPSTREAM}{target}", data=body, method=method, headers=headers
        )
        started = time.monotonic()

        try:
            with OPENER.open(request, timeout=600) as response:
                data = response.read()
                self.send_response(response.status)
                for key, value in response.headers.items():
                    if key.lower() in (
                        "transfer-encoding",
                        "connection",
                        "content-encoding",
                        "content-length",
                    ):
                        continue
                    self.send_header(key, value)
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
                elapsed_ms = (time.monotonic() - started) * 1000
                log(f"{response.status} {method} {self.path} {len(data)}B {elapsed_ms:.0f}ms")
        except urllib.error.HTTPError as error:
            data = error.read()
            self.send_response(error.code)
            self.send_header("Content-Type", error.headers.get("Content-Type", "application/json"))
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            elapsed_ms = (time.monotonic() - started) * 1000
            log(f"{error.code} {method} {self.path} UPSTREAM_ERROR {elapsed_ms:.0f}ms")
        except Exception:
            self._send_json_error(502, "Ollama upstream unavailable", "proxy_error")
            elapsed_ms = (time.monotonic() - started) * 1000
            log(f"502 {method} {self.path} PROXY_ERROR {elapsed_ms:.0f}ms")

    def do_GET(self) -> None:
        self._proxy("GET")

    def do_POST(self) -> None:
        self._proxy("POST")

    def do_OPTIONS(self) -> None:
        self.send_response(204)
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Authorization, Content-Type")
        self.end_headers()

    def log_message(self, *_args: object) -> None:
        return


if __name__ == "__main__":
    if not API_KEY:
        raise SystemExit("OLLAMA proxy credential is empty")

    log(f"Ollama AI proxy starting on {LISTEN_HOST}:{LISTEN_PORT} -> {UPSTREAM}")
    http.server.ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), Handler).serve_forever()
