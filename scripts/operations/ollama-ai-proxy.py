#!/usr/bin/env python3
"""Authenticated logical-model gateway for the local Ollama API."""

import hmac
import http.client
import http.server
import ipaddress
import json
import os
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Any


STANDARD_CONTEXT = 32768
DEEP_CONTEXT = 65536
MAX_REQUEST_BODY_BYTES = 4 * 1024 * 1024
HOP_BY_HOP_RESPONSE_HEADERS = frozenset(
    {
        "connection",
        "keep-alive",
        "proxy-authenticate",
        "proxy-authorization",
        "te",
        "trailers",
        "transfer-encoding",
        "upgrade",
        "content-length",
        "date",
        "server",
    }
)
HOP_BY_HOP_REQUEST_HEADERS = frozenset(
    {
        "authorization",
        "connection",
        "content-length",
        "host",
        "keep-alive",
        "proxy-authenticate",
        "proxy-authorization",
        "te",
        "trailers",
        "transfer-encoding",
        "upgrade",
        "accept-encoding",
    }
)
READ_PATHS = frozenset({"/api/tags", "/api/version", "/health", "/v1/models"})
NATIVE_GENERATION_PATHS = frozenset({"/api/chat", "/api/generate"})
OPENAI_GENERATION_PATHS = frozenset(
    {"/v1/chat/completions", "/v1/completions", "/v1/responses"}
)
EMBEDDING_PATHS = frozenset({"/api/embed", "/api/embeddings", "/v1/embeddings"})
INFERENCE_PATHS = NATIVE_GENERATION_PATHS | OPENAI_GENERATION_PATHS | EMBEDDING_PATHS


class PolicyError(ValueError):
    """A request is authenticated but outside the approved gateway contract."""


class GatewayPolicy:
    """Explicit logical models and fixed safe request-context profiles."""

    def __init__(
        self,
        *,
        general_model: str,
        general_deep_model: str,
        embedding_model: str,
        standard_context: int,
        deep_context: int,
        legacy_general_models: frozenset[str],
        legacy_embedding_models: frozenset[str],
    ) -> None:
        aliases = {general_model, general_deep_model, embedding_model}
        if "" in aliases or len(aliases) != 3:
            raise ValueError("gateway logical model aliases must be distinct and non-empty")
        if standard_context != STANDARD_CONTEXT or deep_context != DEEP_CONTEXT:
            raise ValueError("gateway context profiles must be 32K standard and 64K deep")
        if aliases & legacy_general_models or aliases & legacy_embedding_models:
            raise ValueError("legacy model names cannot collide with logical aliases")
        if legacy_general_models & legacy_embedding_models:
            raise ValueError("a legacy model cannot be both generation and embedding")

        self.general_model = general_model
        self.general_deep_model = general_deep_model
        self.embedding_model = embedding_model
        self.standard_context = standard_context
        self.deep_context = deep_context
        self.legacy_general_models = legacy_general_models
        self.legacy_embedding_models = legacy_embedding_models

    def logical_models(self) -> tuple[str, str, str]:
        return self.general_model, self.general_deep_model, self.embedding_model

    def resolve_generation(self, model: str) -> tuple[str, int]:
        if model == self.general_deep_model:
            return self.general_deep_model, self.deep_context
        if model == self.general_model or model in self.legacy_general_models:
            return self.general_model, self.standard_context
        raise PolicyError("model is not approved for text generation")

    def resolve_embedding(self, model: str) -> str:
        if model == self.embedding_model or model in self.legacy_embedding_models:
            return self.embedding_model
        raise PolicyError("model is not approved for embeddings")

    def catalog(self) -> dict[str, Any]:
        return {
            "object": "list",
            "data": [
                {"id": model, "object": "model", "created": 0, "owned_by": "mbfd"}
                for model in self.logical_models()
            ],
        }

    def native_catalog(self) -> dict[str, Any]:
        return {
            "models": [{"name": model, "model": model} for model in self.logical_models()]
        }


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
        parsed.port
    except ValueError as error:
        raise ValueError("OLLAMA_PROXY_UPSTREAM has an invalid port") from error
    return urllib.parse.urlunsplit(("http", parsed.netloc, "", "", ""))


def relative_request_target(value: str) -> str:
    """Return one origin-form path/query and reject absolute or protocol-relative targets."""
    parsed = urllib.parse.urlsplit(value)
    if (
        parsed.scheme
        or parsed.netloc
        or parsed.fragment
        or not parsed.path.startswith("/")
        or parsed.path.startswith("//")
    ):
        raise ValueError("request target must be a relative API path")
    return urllib.parse.urlunsplit(("", "", parsed.path, parsed.query, ""))


def request_path(value: str) -> str:
    return urllib.parse.urlsplit(relative_request_target(value)).path


def is_allowed_gateway_route(method: str, target: str) -> bool:
    """Allow only explicit read and inference routes; never model administration."""
    try:
        path = request_path(target)
    except ValueError:
        return False
    if method.upper() == "GET":
        return path in READ_PATHS
    if method.upper() == "POST":
        return path in INFERENCE_PATHS
    return False


def comma_separated_env(name: str) -> frozenset[str]:
    return frozenset(
        value.strip() for value in os.environ.get(name, "").split(",") if value.strip()
    )


def bounded_positive_int_env(name: str, default: int, maximum: int) -> int:
    try:
        value = int(os.environ.get(name, str(default)))
    except ValueError as error:
        raise ValueError(f"{name} must be an integer") from error
    if not 1 <= value <= maximum:
        raise ValueError(f"{name} must be between 1 and {maximum}")
    return value


def listen_hosts_from_environment() -> tuple[str, ...]:
    configured = os.environ.get(
        "OLLAMA_PROXY_LISTEN_HOSTS", os.environ.get("OLLAMA_PROXY_HOST", "127.0.0.1")
    )
    hosts = tuple(value.strip() for value in configured.split(",") if value.strip())
    if not hosts or len(hosts) != len(set(hosts)):
        raise ValueError("OLLAMA_PROXY_LISTEN_HOSTS must contain distinct non-empty hosts")
    return hosts


def build_policy_from_environment() -> GatewayPolicy:
    return GatewayPolicy(
        general_model=os.environ.get("OLLAMA_PROXY_GENERAL_MODEL", "mbfd-general").strip(),
        general_deep_model=os.environ.get(
            "OLLAMA_PROXY_GENERAL_DEEP_MODEL", "mbfd-general-deep"
        ).strip(),
        embedding_model=os.environ.get("OLLAMA_PROXY_EMBEDDING_MODEL", "mbfd-embeddings").strip(),
        standard_context=bounded_positive_int_env(
            "OLLAMA_PROXY_STANDARD_CONTEXT", STANDARD_CONTEXT, STANDARD_CONTEXT
        ),
        deep_context=bounded_positive_int_env(
            "OLLAMA_PROXY_DEEP_CONTEXT", DEEP_CONTEXT, DEEP_CONTEXT
        ),
        legacy_general_models=comma_separated_env("OLLAMA_PROXY_LEGACY_GENERAL_MODELS"),
        legacy_embedding_models=comma_separated_env("OLLAMA_PROXY_LEGACY_EMBEDDING_MODELS"),
    )


def rewrite_json_request(target: str, body: bytes, policy: GatewayPolicy) -> bytes:
    """Rewrite only approved logical/legacy models and fixed context limits."""
    try:
        payload = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PolicyError("request body must be a UTF-8 JSON object") from error
    if not isinstance(payload, dict):
        raise PolicyError("request body must be a JSON object")

    model = payload.get("model")
    if not isinstance(model, str) or not model.strip():
        raise PolicyError("request model is required")
    path = request_path(target)

    if path in (NATIVE_GENERATION_PATHS | OPENAI_GENERATION_PATHS):
        rewritten_model, context = policy.resolve_generation(model.strip())
        payload["model"] = rewritten_model
        options = payload.get("options", {})
        if not isinstance(options, dict):
            raise PolicyError("Ollama options must be a JSON object")
        payload["options"] = {**options, "num_ctx": context}
    elif path in EMBEDDING_PATHS:
        payload["model"] = policy.resolve_embedding(model.strip())
    else:
        raise PolicyError("route is not an inference endpoint")

    return json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def valid_or_generated_request_id(value: str | None) -> str:
    """Preserve a safe caller correlation ID or generate one at the boundary."""
    if value:
        candidate = value.strip()
        if 0 < len(candidate) <= 128 and candidate.isprintable():
            return candidate
    return uuid.uuid4().hex


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Ollama should not redirect; following redirects could escape loopback."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


UPSTREAM = validate_upstream(
    os.environ.get("OLLAMA_PROXY_UPSTREAM", "http://127.0.0.1:11434")
)
OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect)
LISTEN_HOST = os.environ.get("OLLAMA_PROXY_HOST", "127.0.0.1")
LISTEN_HOSTS = listen_hosts_from_environment()
LISTEN_PORT = bounded_positive_int_env("OLLAMA_PROXY_PORT", 11440, 65535)
REQUEST_TIMEOUT_SECONDS = bounded_positive_int_env("OLLAMA_PROXY_TIMEOUT_SECONDS", 600, 900)
CLIENT_READ_TIMEOUT_SECONDS = bounded_positive_int_env(
    "OLLAMA_PROXY_CLIENT_READ_TIMEOUT_SECONDS", 30, 60
)
MAX_INFLIGHT = bounded_positive_int_env("OLLAMA_PROXY_MAX_INFLIGHT", 1, 1)
POLICY = build_policy_from_environment()
INFERENCE_SEMAPHORE = threading.BoundedSemaphore(MAX_INFLIGHT)


def credential_path_from_environment() -> str:
    credential_path = os.environ.get("OLLAMA_PROXY_API_KEY_FILE", "")
    if not credential_path.startswith("%d/"):
        return credential_path

    credential_directory = os.environ.get("CREDENTIALS_DIRECTORY", "")
    suffix = Path(credential_path[3:])
    if not credential_directory or suffix.is_absolute() or ".." in suffix.parts:
        raise ValueError("OLLAMA_PROXY_API_KEY_FILE has an invalid credential path")
    return str(Path(credential_directory) / suffix)


def read_api_key() -> str:
    credential_path = credential_path_from_environment()
    if credential_path:
        return Path(credential_path).read_text(encoding="utf-8").strip()
    return os.environ.get("OLLAMA_PROXY_API_KEY", "").strip()


API_KEY = read_api_key()


def log(message: str) -> None:
    print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {message}", flush=True)


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def setup(self) -> None:
        super().setup()
        self.connection.settimeout(CLIENT_READ_TIMEOUT_SECONDS)

    def _begin_request(self) -> str:
        self._gateway_request_id = valid_or_generated_request_id(
            self.headers.get("X-Request-ID")
        )
        return self._gateway_request_id

    def _request_id(self) -> str:
        value = getattr(self, "_gateway_request_id", None)
        return value if value else self._begin_request()

    def _check_auth(self) -> bool:
        authorization = self.headers.get("Authorization", "")
        return bool(
            API_KEY
            and authorization.startswith("Bearer ")
            and hmac.compare_digest(authorization[7:], API_KEY)
        )

    def _request_log_path(self) -> str:
        try:
            return request_path(self.path)
        except ValueError:
            return "<invalid>"

    def _send_json(self, status: int, payload: dict[str, Any], *, close: bool = False) -> None:
        data = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("X-Request-ID", self._request_id())
        if close:
            self.send_header("Connection", "close")
            self.close_connection = True
        self.end_headers()
        self.wfile.write(data)

    def _send_json_error(
        self, status: int, message: str, error_type: str, *, close: bool = False
    ) -> None:
        self._send_json(
            status,
            {"error": {"message": message, "type": error_type}},
            close=close,
        )

    def _safe_request_framing(self, method: str) -> bool:
        if self.headers.get("Transfer-Encoding"):
            return False
        values = self.headers.get_all("Content-Length", [])
        if len(values) > 1:
            return False
        if not values:
            return method != "POST"
        if not values[0].isascii() or not values[0].isdecimal():
            return False
        return method == "POST" or values[0] == "0"

    def _read_body(self) -> bytes:
        supplied = self.headers["Content-Length"]
        length = int(supplied)
        if length > MAX_REQUEST_BODY_BYTES:
            raise PolicyError("request body exceeds the gateway limit")
        try:
            body = self.rfile.read(length)
        except OSError as error:
            raise PolicyError("request body read timed out") from error
        if len(body) != length:
            raise PolicyError("request body was incomplete")
        return body

    def _request_headers(self) -> dict[str, str]:
        headers = {
            key: value
            for key, value in self.headers.items()
            if key.lower() not in HOP_BY_HOP_REQUEST_HEADERS
            and key.lower() != "x-request-id"
        }
        headers["X-Request-ID"] = self._request_id()
        return headers

    @staticmethod
    def _read_upstream_chunk(response: Any) -> bytes:
        reader = getattr(response, "read1", None)
        if callable(reader):
            return reader(64 * 1024)
        buffered = getattr(response, "fp", None)
        reader = getattr(buffered, "read1", None)
        if callable(reader):
            return reader(64 * 1024)
        return response.read(64 * 1024)

    def _forward_response(self, response: Any) -> tuple[int, str]:
        expected_length = response.headers.get("Content-Length")
        try:
            expected_length = int(expected_length) if expected_length is not None else None
        except ValueError:
            expected_length = None
        sent = 0
        is_sse = "text/event-stream" in response.headers.get("Content-Type", "").lower()
        sse_line_buffer = b""
        try:
            status = getattr(response, "status", getattr(response, "code", 502))
            self.send_response(status)
            for key, value in response.headers.items():
                if (
                    key.lower() not in HOP_BY_HOP_RESPONSE_HEADERS
                    and key.lower() not in {"content-length", "x-request-id"}
                ):
                    self.send_header(key, value)
            self.send_header("X-Request-ID", self._request_id())
            self.send_header("Transfer-Encoding", "chunked")
            self.end_headers()

            while chunk := self._read_upstream_chunk(response):
                sent += len(chunk)
                self.wfile.write(f"{len(chunk):X}\r\n".encode("ascii"))
                self.wfile.write(chunk)
                self.wfile.write(b"\r\n")
                self.wfile.flush()
                if is_sse:
                    sse_line_buffer += chunk
                    while b"\n" in sse_line_buffer:
                        line, sse_line_buffer = sse_line_buffer.split(b"\n", 1)
                        if line.rstrip(b"\r") != b"data: [DONE]":
                            continue
                        self.wfile.write(b"0\r\n\r\n")
                        self.wfile.flush()
                        return sent, "complete"
            if expected_length is not None and sent != expected_length:
                self.close_connection = True
                return sent, "upstream_stream_error"
            self.wfile.write(b"0\r\n\r\n")
            self.wfile.flush()
            return sent, "complete"
        except (BrokenPipeError, ConnectionResetError):
            self.close_connection = True
            return sent, "client_disconnected"
        except (OSError, TimeoutError, http.client.IncompleteRead, urllib.error.URLError):
            self.close_connection = True
            return sent, "upstream_stream_error"

    def _logical_aliases_are_available(self, payload: object) -> bool:
        if not isinstance(payload, dict) or not isinstance(payload.get("models"), list):
            return False
        available = {
            model.get("name")
            for model in payload["models"]
            if isinstance(model, dict) and isinstance(model.get("name"), str)
        }
        return all(
            alias in available or f"{alias}:latest" in available
            for alias in POLICY.logical_models()
        )

    def _health(self) -> None:
        started = time.monotonic()
        headers = {"X-Request-ID": self._request_id()}
        try:
            version_request = urllib.request.Request(
                f"{UPSTREAM}/api/version", method="GET", headers=headers
            )
            with OPENER.open(version_request, timeout=REQUEST_TIMEOUT_SECONDS) as response:
                response.read()
            tags_request = urllib.request.Request(
                f"{UPSTREAM}/api/tags", method="GET", headers=headers
            )
            with OPENER.open(tags_request, timeout=REQUEST_TIMEOUT_SECONDS) as response:
                body = response.read(MAX_REQUEST_BODY_BYTES)
            if not self._logical_aliases_are_available(json.loads(body.decode("utf-8"))):
                raise PolicyError("logical aliases are not ready")
            self._send_json(200, {"status": "ok"})
            elapsed_ms = (time.monotonic() - started) * 1000
            log(f"200 GET /health READY {elapsed_ms:.0f}ms id={self._request_id()}")
        except Exception:
            self._send_json_error(503, "Ollama gateway is not ready", "service_unavailable")
            elapsed_ms = (time.monotonic() - started) * 1000
            log(f"503 GET /health NOT_READY {elapsed_ms:.0f}ms id={self._request_id()}")

    def _proxy(self, method: str) -> None:
        self._begin_request()
        if not self._safe_request_framing(method):
            self._send_json_error(
                400,
                "Ambiguous request framing is not permitted",
                "invalid_request_error",
                close=True,
            )
            log(f"400 FRAMING {method} {self._request_log_path()} id={self._request_id()}")
            return
        if not self._check_auth():
            self._send_json_error(
                401,
                "Invalid or missing API key",
                "authentication_error",
                close=method == "POST",
            )
            log(f"401 UNAUTHORIZED {method} {self._request_log_path()} id={self._request_id()}")
            return

        try:
            target = relative_request_target(self.path)
        except ValueError:
            self._send_json_error(
                400,
                "Invalid request target",
                "invalid_request_error",
                close=method == "POST",
            )
            return

        path = request_path(target)
        if not is_allowed_gateway_route(method, target):
            self._send_json_error(
                403,
                "Gateway route is not permitted",
                "permission_error",
                close=method == "POST",
            )
            log(f"403 DENIED {method} {path} id={self._request_id()}")
            return

        if method == "GET" and path == "/health":
            self._health()
            return
        if method == "GET" and path == "/v1/models":
            self._send_json(200, POLICY.catalog())
            log(f"200 {method} {path} LOGICAL_CATALOG id={self._request_id()}")
            return
        if method == "GET" and path == "/api/tags":
            self._send_json(200, POLICY.native_catalog())
            log(f"200 {method} {path} LOGICAL_CATALOG id={self._request_id()}")
            return

        acquired = False
        if method == "POST":
            acquired = INFERENCE_SEMAPHORE.acquire(blocking=False)
            if not acquired:
                self._send_json_error(
                    429,
                    "A generation request is already in progress",
                    "rate_limit_error",
                    close=True,
                )
                log(f"429 BUSY {method} {path} id={self._request_id()}")
                return

        try:
            try:
                body = self._read_body() if method == "POST" else None
                if body is not None:
                    body = rewrite_json_request(target, body, POLICY)
            except PolicyError as error:
                self._send_json_error(
                    400,
                    str(error),
                    "invalid_request_error",
                    close=method == "POST",
                )
                log(f"400 REJECTED {method} {path} id={self._request_id()}")
                return

            request = urllib.request.Request(
                f"{UPSTREAM}{target}",
                data=body,
                method=method,
                headers=self._request_headers(),
            )
            started = time.monotonic()
            response_started = False

            try:
                with OPENER.open(request, timeout=REQUEST_TIMEOUT_SECONDS) as response:
                    response_started = True
                    sent, outcome = self._forward_response(response)
                    elapsed_ms = (time.monotonic() - started) * 1000
                    log(
                        f"{response.status} {method} {path} {outcome} {sent}B "
                        f"{elapsed_ms:.0f}ms id={self._request_id()}"
                    )
            except urllib.error.HTTPError as error:
                response_started = True
                try:
                    sent, outcome = self._forward_response(error)
                finally:
                    error.close()
                elapsed_ms = (time.monotonic() - started) * 1000
                log(
                    f"{error.code} {method} {path} {outcome} {sent}B "
                    f"{elapsed_ms:.0f}ms id={self._request_id()}"
                )
            except Exception:
                elapsed_ms = (time.monotonic() - started) * 1000
                if response_started:
                    self.close_connection = True
                    log(
                        f"UPSTREAM_STREAM_ABORT {method} {path} {elapsed_ms:.0f}ms "
                        f"id={self._request_id()}"
                    )
                else:
                    self._send_json_error(502, "Ollama upstream unavailable", "proxy_error")
                    log(
                        f"502 {method} {path} PROXY_ERROR {elapsed_ms:.0f}ms "
                        f"id={self._request_id()}"
                    )
        finally:
            if acquired:
                INFERENCE_SEMAPHORE.release()

    def do_GET(self) -> None:
        self._proxy("GET")

    def do_POST(self) -> None:
        self._proxy("POST")

    def do_OPTIONS(self) -> None:
        self._begin_request()
        if not self._safe_request_framing("OPTIONS"):
            self._send_json_error(
                400,
                "Ambiguous request framing is not permitted",
                "invalid_request_error",
                close=True,
            )
            return
        if not self._check_auth():
            self._send_json_error(401, "Invalid or missing API key", "authentication_error")
            return
        self.send_response(204)
        self.send_header("Allow", "GET, POST, OPTIONS")
        self.send_header("Content-Length", "0")
        self.send_header("X-Request-ID", self._request_id())
        self.end_headers()

    def log_message(self, *_args: object) -> None:
        return


class GatewayHTTPServer(http.server.ThreadingHTTPServer):
    daemon_threads = True


if __name__ == "__main__":
    if not API_KEY:
        raise SystemExit("OLLAMA proxy credential is empty")

    servers = [GatewayHTTPServer((host, LISTEN_PORT), Handler) for host in LISTEN_HOSTS]
    log(
        f"Ollama AI proxy starting on {', '.join(f'{host}:{LISTEN_PORT}' for host in LISTEN_HOSTS)} "
        f"-> {UPSTREAM}"
    )
    for server in servers[:-1]:
        threading.Thread(target=server.serve_forever, daemon=True).start()
    servers[-1].serve_forever()
