#!/usr/bin/env python3
"""Provider-neutral, authenticated MBFD AI Gateway foundation.

The module intentionally uses only the Python standard library so the deployed
service has no package-install or network dependency.  Configuration chooses
consumers, logical capabilities, providers, models, and admission policy; HTTP
handlers never choose a physical model on their own.
"""

from __future__ import annotations

import dataclasses
import datetime as dt
import hmac
import http.client
import http.server
import ipaddress
import json
import os
import re
import socket
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Any, TextIO

REQUIRED_CAPABILITIES = frozenset(
    {
        "mbfd-general",
        "mbfd-code",
        "mbfd-eoc-grounding",
        "mbfd-embeddings",
        "mbfd-transcribe",
        "mbfd-image",
        "mbfd-ops-summary",
    }
)
SUPPORTED_PROVIDERS = frozenset({"ollama", "openai_compatible", "speaches", "comfyui"})
READ_PATHS = frozenset(
    {
        "/health",
        "/health/live",
        "/health/ready",
        "/health/backends",
        "/v1/models",
        "/api/tags",
        "/api/version",
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
        "trailer",
        "trailers",
        "transfer-encoding",
        "upgrade",
        "accept-encoding",
        "x-mbfd-capability",
        "x-request-id",
    }
)
HOP_BY_HOP_RESPONSE_HEADERS = frozenset(
    {
        "connection",
        "content-length",
        "date",
        "keep-alive",
        "proxy-authenticate",
        "proxy-authorization",
        "server",
        "te",
        "trailer",
        "trailers",
        "transfer-encoding",
        "upgrade",
        "x-request-id",
    }
)
REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$")


class GatewayError(Exception):
    """An expected boundary failure with a stable machine classification."""

    def __init__(self, status: int, classification: str, message: str):
        super().__init__(message)
        self.status = status
        self.classification = classification
        self.message = message


@dataclasses.dataclass(slots=True)
class ColdStartPolicy:
    mode: str
    ready_timeout_seconds: float


@dataclasses.dataclass(slots=True)
class GlobalLimits:
    concurrency: int
    queue_limit: int
    queue_timeout_seconds: float
    request_timeout_seconds: float
    max_request_body_bytes: int


@dataclasses.dataclass(slots=True)
class RateLimitPolicy:
    requests: int
    window_seconds: float
    burst: int


@dataclasses.dataclass(slots=True)
class Consumer:
    consumer_id: str
    credential: str
    allowed_capabilities: frozenset[str]
    concurrency: int
    legacy_passthrough: bool
    rate_limit: RateLimitPolicy | None


@dataclasses.dataclass(slots=True)
class Backend:
    backend_id: str
    provider: str
    base_url: str
    enabled: bool
    health_timeout_seconds: float


@dataclasses.dataclass(slots=True)
class Capability:
    capability_id: str
    backend_id: str
    model: str
    paths: frozenset[str]
    concurrency: int
    timeout_seconds: float
    retries: int
    cold_start: ColdStartPolicy
    ollama_options: dict[str, Any]
    openai_options: dict[str, Any]
    allow_tools: bool
    heavy_workload: str | None


@dataclasses.dataclass(slots=True)
class HeavyWorkloadPolicy:
    workload_id: str
    lease_group: str
    mem_available_floor_mb: int = 0
    declared_model_allocation_mb: int = 0
    memory_psi_avg10_max: float = 100.0
    max_swap_activity_pages: int | None = None
    deny_on_recent_oom: bool = False
    deny_on_recent_gpu_reset: bool = False
    require_production_healthy: bool = False


@dataclasses.dataclass(slots=True)
class CompatibilityModel:
    capability_id: str
    backend_model: str | None = None
    ollama_options: dict[str, Any] | None = None


@dataclasses.dataclass(slots=True)
class HostHealthConfig:
    recent_oom_marker: str | None = None
    recent_gpu_reset_marker: str | None = None
    production_health_file: str | None = None


@dataclasses.dataclass(slots=True)
class GatewayConfig:
    listeners: tuple[str, ...]
    port: int
    limits: GlobalLimits
    consumers: dict[str, Consumer]
    backends: dict[str, Backend]
    capabilities: dict[str, Capability]
    compatibility_models: dict[str, CompatibilityModel]
    heavy_workloads: dict[str, HeavyWorkloadPolicy]
    host_health: HostHealthConfig


@dataclasses.dataclass(slots=True)
class Selection:
    consumer: Consumer
    capability: Capability
    backend: Backend
    requested_model: str
    backend_model: str
    ollama_options: dict[str, Any]


@dataclasses.dataclass(slots=True)
class ModelReadiness:
    backend_healthy: bool
    model_available: bool
    model_loaded: bool
    classification: str | None
    detail: str


@dataclasses.dataclass(slots=True)
class HostHealthSnapshot:
    mem_available_mb: int
    memory_psi_avg10: float
    recent_oom: bool
    recent_gpu_reset: bool
    production_healthy: bool
    swap_activity_pages: int


def _duplicate_rejecting_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"duplicate configuration key: {key}")
        result[key] = value
    return result


def _mapping(value: Any, path: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise TypeError(f"{path} must be an object")
    return value


def _string(value: Any, path: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f"{path} must be a non-empty string")
    return value.strip()


def _bool(value: Any, path: str) -> bool:
    if not isinstance(value, bool):
        raise TypeError(f"{path} must be a boolean")
    return value


def _int(value: Any, path: str, *, minimum: int = 1, maximum: int = 1_000_000) -> int:
    if (
        isinstance(value, bool)
        or not isinstance(value, int)
        or not minimum <= value <= maximum
    ):
        raise ValueError(f"{path} must be an integer between {minimum} and {maximum}")
    return value


def _number(
    value: Any, path: str, *, minimum: float = 0.001, maximum: float = 900.0
) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise TypeError(f"{path} must be a number")
    result = float(value)
    if not minimum <= result <= maximum:
        raise ValueError(f"{path} must be between {minimum} and {maximum}")
    return result


def _private_ip(value: str, path: str) -> str:
    try:
        address = ipaddress.ip_address(value)
    except ValueError as error:
        raise ValueError(
            f"{path} must be a literal loopback or private IP address"
        ) from error
    if address.is_unspecified or not (address.is_loopback or address.is_private):
        raise ValueError(f"{path} must be a private or loopback address")
    return value


def _private_http_origin(value: str, path: str) -> str:
    parsed = urllib.parse.urlsplit(value)
    if parsed.scheme != "http" or not parsed.hostname:
        raise ValueError(f"{path} must be a private HTTP origin")
    if (
        parsed.username
        or parsed.password
        or parsed.query
        or parsed.fragment
        or parsed.path not in ("", "/")
    ):
        raise ValueError(f"{path} cannot contain credentials, path, query, or fragment")
    if parsed.hostname != "localhost":
        _private_ip(parsed.hostname, path)
    try:
        _ = parsed.port
    except ValueError as error:
        raise ValueError(f"{path} has an invalid port") from error
    return urllib.parse.urlunsplit(("http", parsed.netloc, "", "", ""))


def _credential_path(value: str, environ: dict[str, str]) -> Path:
    if value.startswith("%d/"):
        directory = environ.get("CREDENTIALS_DIRECTORY", "")
        suffix = Path(value[3:])
        if not directory or suffix.is_absolute() or ".." in suffix.parts:
            raise ValueError(
                "consumer credential_file uses %d without a safe CREDENTIALS_DIRECTORY"
            )
        return Path(directory) / suffix
    return Path(value)


def runtime_path(value: str, environ: dict[str, str] | None = None) -> str:
    """Resolve systemd's credential-directory placeholder without exposing contents."""
    if not value.startswith("%d/"):
        return value
    environment = os.environ if environ is None else environ
    directory = environment.get("CREDENTIALS_DIRECTORY", "")
    parts = value[3:].split("/")
    if not directory or any(part in {"", ".", ".."} for part in parts):
        raise ValueError("%d path requires a safe CREDENTIALS_DIRECTORY")
    return str(Path(directory).joinpath(*parts))


def load_config(
    path: str | Path, environ: dict[str, str] | None = None
) -> GatewayConfig:
    """Load and fully validate configuration before any listener is opened."""
    environment = dict(os.environ if environ is None else environ)
    try:
        raw = json.loads(
            Path(path).read_text(encoding="utf-8"),
            object_pairs_hook=_duplicate_rejecting_object,
        )
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise ValueError(f"cannot load gateway configuration: {error}") from error
    root = _mapping(raw, "configuration")
    if root.get("schema_version") != 1:
        raise ValueError("schema_version must be 1")

    listeners_raw = root.get("listeners")
    if not isinstance(listeners_raw, list) or not listeners_raw:
        raise ValueError("listeners must be a non-empty array")
    listeners = tuple(
        _private_ip(_string(value, "listeners[]"), "listeners[]")
        for value in listeners_raw
    )
    if len(set(listeners)) != len(listeners):
        raise ValueError("listeners must be unique")
    port = _int(root.get("port"), "port", maximum=65535)

    global_raw = _mapping(root.get("global"), "global")
    limits = GlobalLimits(
        concurrency=_int(
            global_raw.get("concurrency"), "global.concurrency", maximum=64
        ),
        queue_limit=_int(
            global_raw.get("queue_limit"), "global.queue_limit", minimum=0, maximum=256
        ),
        queue_timeout_seconds=_number(
            global_raw.get("queue_timeout_seconds"), "global.queue_timeout_seconds"
        ),
        request_timeout_seconds=_number(
            global_raw.get("request_timeout_seconds"), "global.request_timeout_seconds"
        ),
        max_request_body_bytes=_int(
            global_raw.get("max_request_body_bytes"),
            "global.max_request_body_bytes",
            maximum=64 * 1024 * 1024,
        ),
    )

    host_health_raw = _mapping(root.get("host_health", {}), "host_health")

    def optional_path(name: str) -> str | None:
        value = host_health_raw.get(name)
        return None if value is None else _string(value, f"host_health.{name}")

    host_health = HostHealthConfig(
        recent_oom_marker=optional_path("recent_oom_marker"),
        recent_gpu_reset_marker=optional_path("recent_gpu_reset_marker"),
        production_health_file=optional_path("production_health_file"),
    )

    backends: dict[str, Backend] = {}
    for backend_id, value in _mapping(root.get("backends"), "backends").items():
        backend_id = _string(backend_id, "backends key")
        item = _mapping(value, f"backends.{backend_id}")
        provider = _string(item.get("provider"), f"backends.{backend_id}.provider")
        if provider not in SUPPORTED_PROVIDERS:
            raise ValueError(f"backends.{backend_id}.provider is unsupported")
        backends[backend_id] = Backend(
            backend_id=backend_id,
            provider=provider,
            base_url=_private_http_origin(
                _string(item.get("base_url"), f"backends.{backend_id}.base_url"),
                f"backends.{backend_id}.base_url",
            ),
            enabled=_bool(item.get("enabled", False), f"backends.{backend_id}.enabled"),
            health_timeout_seconds=_number(
                item.get("health_timeout_seconds", 2),
                f"backends.{backend_id}.health_timeout_seconds",
                maximum=30,
            ),
        )
    if not backends:
        raise ValueError("at least one backend is required")

    workloads: dict[str, HeavyWorkloadPolicy] = {}
    for workload_id, value in _mapping(
        root.get("heavy_workloads", {}), "heavy_workloads"
    ).items():
        item = _mapping(value, f"heavy_workloads.{workload_id}")
        max_swap = item.get("max_swap_activity_pages")
        workloads[workload_id] = HeavyWorkloadPolicy(
            workload_id=workload_id,
            lease_group=_string(
                item.get("lease_group"), f"heavy_workloads.{workload_id}.lease_group"
            ),
            mem_available_floor_mb=_int(
                item.get("mem_available_floor_mb", 0),
                f"heavy_workloads.{workload_id}.mem_available_floor_mb",
                minimum=0,
            ),
            declared_model_allocation_mb=_int(
                item.get("declared_model_allocation_mb", 0),
                f"heavy_workloads.{workload_id}.declared_model_allocation_mb",
                minimum=0,
            ),
            memory_psi_avg10_max=_number(
                item.get("memory_psi_avg10_max", 100.0),
                f"heavy_workloads.{workload_id}.memory_psi_avg10_max",
                minimum=0,
            ),
            max_swap_activity_pages=(
                None
                if max_swap is None
                else _int(
                    max_swap,
                    f"heavy_workloads.{workload_id}.max_swap_activity_pages",
                    minimum=0,
                )
            ),
            deny_on_recent_oom=_bool(
                item.get("deny_on_recent_oom", False),
                f"heavy_workloads.{workload_id}.deny_on_recent_oom",
            ),
            deny_on_recent_gpu_reset=_bool(
                item.get("deny_on_recent_gpu_reset", False),
                f"heavy_workloads.{workload_id}.deny_on_recent_gpu_reset",
            ),
            require_production_healthy=_bool(
                item.get("require_production_healthy", False),
                f"heavy_workloads.{workload_id}.require_production_healthy",
            ),
        )

    capabilities: dict[str, Capability] = {}
    for capability_id, value in _mapping(
        root.get("capabilities"), "capabilities"
    ).items():
        item = _mapping(value, f"capabilities.{capability_id}")
        backend_id = _string(
            item.get("backend"), f"capabilities.{capability_id}.backend"
        )
        if backend_id not in backends:
            raise ValueError(
                f"capabilities.{capability_id} references unknown backend {backend_id}"
            )
        paths_raw = item.get("paths")
        if not isinstance(paths_raw, list) or not paths_raw:
            raise ValueError(
                f"capabilities.{capability_id}.paths must be a non-empty array"
            )
        paths = frozenset(
            _string(value, f"capabilities.{capability_id}.paths[]")
            for value in paths_raw
        )
        if any(not value.startswith("/") or value.startswith("//") for value in paths):
            raise ValueError(
                f"capabilities.{capability_id}.paths must be origin-form paths"
            )
        cold_raw = _mapping(
            item.get("cold_start"), f"capabilities.{capability_id}.cold_start"
        )
        cold_mode = _string(
            cold_raw.get("mode"), f"capabilities.{capability_id}.cold_start.mode"
        )
        if cold_mode not in {"reject_if_cold", "wait"}:
            raise ValueError(
                f"capabilities.{capability_id}.cold_start.mode must be reject_if_cold or wait"
            )
        timeout = _number(
            item.get("timeout_seconds"), f"capabilities.{capability_id}.timeout_seconds"
        )
        ready_timeout = _number(
            cold_raw.get("ready_timeout_seconds"),
            f"capabilities.{capability_id}.cold_start.ready_timeout_seconds",
        )
        if ready_timeout > timeout:
            raise ValueError(
                f"capabilities.{capability_id}.cold_start deadline cannot exceed request timeout"
            )
        heavy = item.get("heavy_workload")
        if heavy is not None:
            heavy = _string(heavy, f"capabilities.{capability_id}.heavy_workload")
            if heavy not in workloads:
                raise ValueError(
                    f"capabilities.{capability_id} references unknown heavy workload {heavy}"
                )
        ollama_options = dict(
            _mapping(
                item.get("ollama_options", {}),
                f"capabilities.{capability_id}.ollama_options",
            )
        )
        openai_options = dict(
            _mapping(
                item.get("openai_options", {}),
                f"capabilities.{capability_id}.openai_options",
            )
        )
        unsupported_openai_options = openai_options.keys() - {
            "temperature",
            "top_p",
            "max_tokens",
            "reasoning_effort",
        }
        if unsupported_openai_options:
            raise ValueError(
                f"capabilities.{capability_id}.openai_options contains unsupported entries: "
                f"{', '.join(sorted(unsupported_openai_options))}"
            )
        if "temperature" in openai_options:
            openai_options["temperature"] = _number(
                openai_options["temperature"],
                f"capabilities.{capability_id}.openai_options.temperature",
                minimum=0,
                maximum=2,
            )
        if "top_p" in openai_options:
            openai_options["top_p"] = _number(
                openai_options["top_p"],
                f"capabilities.{capability_id}.openai_options.top_p",
                minimum=0,
                maximum=1,
            )
        if "max_tokens" in openai_options:
            openai_options["max_tokens"] = _int(
                openai_options["max_tokens"],
                f"capabilities.{capability_id}.openai_options.max_tokens",
                maximum=16384,
            )
        if "reasoning_effort" in openai_options:
            effort = _string(
                openai_options["reasoning_effort"],
                f"capabilities.{capability_id}.openai_options.reasoning_effort",
            )
            if effort not in {"none", "low", "medium", "high", "max"}:
                raise ValueError(
                    f"capabilities.{capability_id}.openai_options.reasoning_effort is unsupported"
                )
            openai_options["reasoning_effort"] = effort
        capabilities[capability_id] = Capability(
            capability_id=capability_id,
            backend_id=backend_id,
            model=_string(item.get("model"), f"capabilities.{capability_id}.model"),
            paths=paths,
            concurrency=_int(
                item.get("concurrency"),
                f"capabilities.{capability_id}.concurrency",
                maximum=64,
            ),
            timeout_seconds=timeout,
            retries=_int(
                item.get("retries", 0),
                f"capabilities.{capability_id}.retries",
                minimum=0,
                maximum=3,
            ),
            cold_start=ColdStartPolicy(cold_mode, ready_timeout),
            ollama_options=ollama_options,
            openai_options=openai_options,
            allow_tools=_bool(
                item.get("allow_tools", True),
                f"capabilities.{capability_id}.allow_tools",
            ),
            heavy_workload=heavy,
        )
    missing = REQUIRED_CAPABILITIES - capabilities.keys()
    if missing:
        raise ValueError(
            f"capabilities missing required entries: {', '.join(sorted(missing))}"
        )

    consumers: dict[str, Consumer] = {}
    credential_values: set[str] = set()
    for consumer_id, value in _mapping(root.get("consumers"), "consumers").items():
        item = _mapping(value, f"consumers.{consumer_id}")
        path_value = _string(
            item.get("credential_file"), f"consumers.{consumer_id}.credential_file"
        )
        credential_path = _credential_path(path_value, environment)
        try:
            credential = credential_path.read_text(encoding="utf-8").strip()
        except (OSError, UnicodeError) as error:
            raise ValueError(
                f"consumers.{consumer_id} credential cannot be read: {error}"
            ) from error
        if not credential:
            raise ValueError(f"consumers.{consumer_id} credential is empty")
        if credential in credential_values:
            raise ValueError("consumer credential values must be unique")
        credential_values.add(credential)
        allowed_raw = item.get("allowed_capabilities")
        if not isinstance(allowed_raw, list) or not allowed_raw:
            raise ValueError(
                f"consumers.{consumer_id}.allowed_capabilities must be a non-empty array"
            )
        allowed = frozenset(
            _string(entry, f"consumers.{consumer_id}.allowed_capabilities[]")
            for entry in allowed_raw
        )
        unknown = allowed - capabilities.keys()
        if unknown:
            raise ValueError(
                f"consumers.{consumer_id} references unknown capabilities: {', '.join(sorted(unknown))}"
            )
        rate_limit_raw = item.get("rate_limit")
        rate_limit = None
        if rate_limit_raw is not None:
            rate_item = _mapping(rate_limit_raw, f"consumers.{consumer_id}.rate_limit")
            requests = _int(
                rate_item.get("requests"),
                f"consumers.{consumer_id}.rate_limit.requests",
                maximum=100000,
            )
            burst = _int(
                rate_item.get("burst"),
                f"consumers.{consumer_id}.rate_limit.burst",
                maximum=10000,
            )
            if burst > requests:
                raise ValueError(
                    f"consumers.{consumer_id}.rate_limit.burst cannot exceed requests"
                )
            rate_limit = RateLimitPolicy(
                requests=requests,
                window_seconds=_number(
                    rate_item.get("window_seconds"),
                    f"consumers.{consumer_id}.rate_limit.window_seconds",
                    maximum=86400,
                ),
                burst=burst,
            )
        consumers[consumer_id] = Consumer(
            consumer_id=consumer_id,
            credential=credential,
            allowed_capabilities=allowed,
            concurrency=_int(
                item.get("concurrency"),
                f"consumers.{consumer_id}.concurrency",
                maximum=64,
            ),
            legacy_passthrough=_bool(
                item.get("legacy_passthrough", False),
                f"consumers.{consumer_id}.legacy_passthrough",
            ),
            rate_limit=rate_limit,
        )
    if not consumers:
        raise ValueError("at least one consumer is required")

    compatibility: dict[str, CompatibilityModel] = {}
    for model, value in _mapping(
        root.get("compatibility_models", {}), "compatibility_models"
    ).items():
        if isinstance(value, str):
            capability_id = value
            backend_model = None
            ollama_options = None
        else:
            item = _mapping(value, f"compatibility_models.{model}")
            capability_id = _string(
                item.get("capability"), f"compatibility_models.{model}.capability"
            )
            backend_model = item.get("model")
            if backend_model is not None:
                backend_model = _string(
                    backend_model, f"compatibility_models.{model}.model"
                )
            configured_options = item.get("ollama_options")
            ollama_options = (
                None
                if configured_options is None
                else dict(
                    _mapping(
                        configured_options,
                        f"compatibility_models.{model}.ollama_options",
                    )
                )
            )
        if capability_id not in capabilities:
            raise ValueError(
                f"compatibility model {model} references unknown capability {capability_id}"
            )
        compatibility[model] = CompatibilityModel(
            capability_id, backend_model, ollama_options
        )

    return GatewayConfig(
        listeners,
        port,
        limits,
        consumers,
        backends,
        capabilities,
        compatibility,
        workloads,
        host_health,
    )


class CredentialRegistry:
    def __init__(self, consumers: dict[str, Consumer]):
        self._consumers = tuple(consumers.values())

    def resolve(self, authorization: str) -> Consumer:
        supplied = authorization[7:] if authorization.startswith("Bearer ") else ""
        match: Consumer | None = None
        for consumer in self._consumers:
            if supplied and hmac.compare_digest(supplied, consumer.credential):
                match = consumer
        if match is None:
            raise GatewayError(
                401, "authentication_failed", "Invalid or missing gateway credential"
            )
        return match


def safe_request_id(value: str | None) -> str:
    if value and REQUEST_ID_PATTERN.fullmatch(value.strip()):
        return value.strip()
    return uuid.uuid4().hex


def relative_target(value: str) -> str:
    parsed = urllib.parse.urlsplit(value)
    if (
        parsed.scheme
        or parsed.netloc
        or parsed.fragment
        or not parsed.path.startswith("/")
        or parsed.path.startswith("//")
    ):
        raise GatewayError(
            400, "admission_denied", "Request target must be an origin-form API path"
        )
    return urllib.parse.urlunsplit(("", "", parsed.path, parsed.query, ""))


def target_path(value: str) -> str:
    return urllib.parse.urlsplit(relative_target(value)).path


def resolve_capability(
    config: GatewayConfig,
    consumer: Consumer,
    path: str,
    payload: dict[str, Any],
    requested_capability: str | None,
) -> Selection:
    requested_model = payload.get("model")
    if not isinstance(requested_model, str) or not requested_model.strip():
        raise GatewayError(
            400, "admission_denied", "A logical capability or model is required"
        )
    requested_model = requested_model.strip()

    compatibility = config.compatibility_models.get(requested_model)
    inferred = requested_model if requested_model in config.capabilities else None
    if compatibility:
        inferred = compatibility.capability_id
    capability_id = requested_capability.strip() if requested_capability else inferred
    if not capability_id or capability_id not in config.capabilities:
        raise GatewayError(
            403, "admission_denied", "Requested logical capability is not registered"
        )
    if requested_capability and inferred and inferred != capability_id:
        raise GatewayError(
            403, "admission_denied", "Capability header and request model disagree"
        )
    if capability_id not in consumer.allowed_capabilities:
        raise GatewayError(
            403, "admission_denied", "Consumer is not allowed to use this capability"
        )
    capability = config.capabilities[capability_id]
    if path not in capability.paths:
        raise GatewayError(
            403, "admission_denied", "Capability is not permitted on this API path"
        )
    backend = config.backends[capability.backend_id]
    backend_model = capability.model
    if compatibility and compatibility.backend_model:
        backend_model = compatibility.backend_model
    ollama_options = dict(capability.ollama_options)
    if compatibility and compatibility.ollama_options is not None:
        ollama_options = dict(compatibility.ollama_options)
    return Selection(
        consumer,
        capability,
        backend,
        requested_model,
        backend_model,
        ollama_options,
    )


def rewrite_model(
    payload: dict[str, Any], selection: Selection, path: str | None = None
) -> dict[str, Any]:
    rewritten = dict(payload)
    if not selection.capability.allow_tools and rewritten.get("tools"):
        raise GatewayError(
            403, "admission_denied", "Tools are disabled for this capability"
        )
    if path and path.startswith("/v1/") and selection.capability.openai_options:
        for provider_specific in (
            "options",
            "num_ctx",
            "think",
            "keep_alive",
            "reasoning",
        ):
            rewritten.pop(provider_specific, None)
        rewritten.update(selection.capability.openai_options)
    rewritten["model"] = selection.backend_model
    if selection.backend.provider == "ollama" and selection.ollama_options:
        supplied = rewritten.get("options", {})
        if not isinstance(supplied, dict):
            raise GatewayError(
                400, "admission_denied", "Ollama options must be a JSON object"
            )
        rewritten["options"] = {**supplied, **selection.ollama_options}
    return rewritten


class _AdmissionLease:
    def __init__(
        self,
        controller: AdmissionController,
        consumer: Consumer,
        capability: Capability,
        queue_timeout: float,
    ):
        self.controller = controller
        self.consumer = consumer
        self.capability = capability
        self.queue_timeout = queue_timeout
        self.acquired = False

    def __enter__(self):
        self.controller._enter(self.consumer, self.capability, self.queue_timeout)
        self.acquired = True
        return self

    def __exit__(self, *_args):
        if self.acquired:
            self.controller._exit(self.consumer, self.capability)
            self.acquired = False


class AdmissionController:
    def __init__(self, config: GatewayConfig):
        self._config = config
        self._condition = threading.Condition()
        self._global = 0
        self._queued = 0
        self._consumers: dict[str, int] = {}
        self._capabilities: dict[str, int] = {}
        self._rate_buckets: dict[str, tuple[float, float]] = {}

    def acquire(
        self,
        consumer: Consumer,
        capability: Capability,
        queue_timeout: float | None = None,
    ) -> _AdmissionLease:
        return _AdmissionLease(
            self,
            consumer,
            capability,
            self._config.limits.queue_timeout_seconds
            if queue_timeout is None
            else queue_timeout,
        )

    def _available(self, consumer: Consumer, capability: Capability) -> bool:
        return (
            self._global < self._config.limits.concurrency
            and self._consumers.get(consumer.consumer_id, 0) < consumer.concurrency
            and self._capabilities.get(capability.capability_id, 0)
            < capability.concurrency
        )

    def _enter(
        self, consumer: Consumer, capability: Capability, timeout: float
    ) -> None:
        now = time.monotonic()
        deadline = now + timeout
        with self._condition:
            policy = consumer.rate_limit
            if policy is not None:
                tokens, updated_at = self._rate_buckets.get(
                    consumer.consumer_id, (float(policy.burst), now)
                )
                elapsed = max(0.0, now - updated_at)
                tokens = min(
                    float(policy.burst),
                    tokens + elapsed * policy.requests / policy.window_seconds,
                )
                if tokens < 1.0:
                    self._rate_buckets[consumer.consumer_id] = (tokens, now)
                    raise GatewayError(
                        429, "rate_limit", "Consumer request rate limit exceeded"
                    )
                self._rate_buckets[consumer.consumer_id] = (tokens - 1.0, now)
            if not self._available(consumer, capability):
                if self._queued >= self._config.limits.queue_limit:
                    raise GatewayError(
                        429, "concurrency_limit", "Gateway admission queue is full"
                    )
                self._queued += 1
                try:
                    while not self._available(consumer, capability):
                        remaining = deadline - time.monotonic()
                        if remaining <= 0:
                            raise GatewayError(
                                429,
                                "concurrency_limit",
                                "Gateway admission wait timed out",
                            )
                        self._condition.wait(remaining)
                finally:
                    self._queued -= 1
            self._global += 1
            self._consumers[consumer.consumer_id] = (
                self._consumers.get(consumer.consumer_id, 0) + 1
            )
            self._capabilities[capability.capability_id] = (
                self._capabilities.get(capability.capability_id, 0) + 1
            )

    def _exit(self, consumer: Consumer, capability: Capability) -> None:
        with self._condition:
            self._global -= 1
            self._consumers[consumer.consumer_id] -= 1
            self._capabilities[capability.capability_id] -= 1
            self._condition.notify_all()

    def snapshot(self) -> dict[str, Any]:
        with self._condition:
            return {
                "running_global": self._global,
                "queued": self._queued,
                "consumers": dict(self._consumers),
                "capabilities": dict(self._capabilities),
            }


class HostHealthProbe:
    """Read-only host checks. Optional marker files are fail-closed when present."""

    def __init__(
        self,
        *,
        oom_marker: str | None = None,
        gpu_reset_marker: str | None = None,
        production_health_file: str | None = None,
    ):
        self.oom_marker = oom_marker
        self.gpu_reset_marker = gpu_reset_marker
        self.production_health_file = production_health_file
        self._last_swap: tuple[int, int] | None = None

    @staticmethod
    def _mem_available_mb() -> int:
        try:
            for line in Path("/proc/meminfo").read_text(encoding="ascii").splitlines():
                if line.startswith("MemAvailable:"):
                    return int(line.split()[1]) // 1024
        except (OSError, ValueError, IndexError):
            pass
        return 0

    @staticmethod
    def _psi_avg10() -> float:
        try:
            first = (
                Path("/proc/pressure/memory")
                .read_text(encoding="ascii")
                .splitlines()[0]
            )
            for field in first.split():
                if field.startswith("avg10="):
                    return float(field.split("=", 1)[1])
        except (OSError, ValueError, IndexError):
            pass
        return 0.0

    def _swap_activity(self) -> int:
        current = {"pswpin": 0, "pswpout": 0}
        try:
            for line in Path("/proc/vmstat").read_text(encoding="ascii").splitlines():
                key, _, value = line.partition(" ")
                if key in current:
                    current[key] = int(value)
        except (OSError, ValueError):
            return 0
        pair = (current["pswpin"], current["pswpout"])
        previous, self._last_swap = self._last_swap, pair
        if previous is None:
            return 0
        return max(0, pair[0] - previous[0]) + max(0, pair[1] - previous[1])

    @staticmethod
    def _marker(path: str | None) -> bool:
        return bool(path and Path(path).exists())

    def snapshot(self) -> HostHealthSnapshot:
        production_healthy = True
        if self.production_health_file:
            try:
                production_healthy = (
                    Path(self.production_health_file)
                    .read_text(encoding="utf-8")
                    .strip()
                    == "healthy"
                )
            except OSError:
                production_healthy = False
        return HostHealthSnapshot(
            mem_available_mb=self._mem_available_mb(),
            memory_psi_avg10=self._psi_avg10(),
            recent_oom=self._marker(self.oom_marker),
            recent_gpu_reset=self._marker(self.gpu_reset_marker),
            production_healthy=production_healthy,
            swap_activity_pages=self._swap_activity(),
        )


class _HeavyLease:
    def __init__(
        self,
        manager: HeavyLeaseManager,
        workload_id: str | None,
        requires_allocation: bool,
    ):
        self.manager = manager
        self.workload_id = workload_id
        self.requires_allocation = requires_allocation
        self.group: str | None = None

    def __enter__(self):
        if self.workload_id is not None:
            self.group = self.manager._enter(self.workload_id, self.requires_allocation)
        return self

    def __exit__(self, *_args):
        if self.group is not None:
            self.manager._exit(self.group)
            self.group = None


class HeavyLeaseManager:
    def __init__(
        self, policies: dict[str, HeavyWorkloadPolicy], probe: Any | None = None
    ):
        self.policies = policies
        self.probe = probe or HostHealthProbe()
        self._lock = threading.Lock()
        self._leases: dict[str, str] = {}

    def acquire(
        self, workload_id: str | None, *, requires_allocation: bool = False
    ) -> _HeavyLease:
        return _HeavyLease(self, workload_id, requires_allocation)

    def _enter(self, workload_id: str, requires_allocation: bool) -> str:
        policy = self.policies[workload_id]
        state = self.probe.snapshot()
        reasons = []
        if state.mem_available_mb < policy.mem_available_floor_mb:
            reasons.append("MemAvailable below policy floor")
        if (
            requires_allocation
            and state.mem_available_mb
            < policy.mem_available_floor_mb + policy.declared_model_allocation_mb
        ):
            reasons.append("declared model allocation exceeds available memory policy")
        if state.memory_psi_avg10 > policy.memory_psi_avg10_max:
            reasons.append("memory PSI exceeds policy")
        if (
            policy.max_swap_activity_pages is not None
            and state.swap_activity_pages > policy.max_swap_activity_pages
        ):
            reasons.append("swap activity exceeds policy")
        if policy.deny_on_recent_oom and state.recent_oom:
            reasons.append("recent OOM marker is active")
        if policy.deny_on_recent_gpu_reset and state.recent_gpu_reset:
            reasons.append("recent GPU reset marker is active")
        if policy.require_production_healthy and not state.production_healthy:
            reasons.append("production health is not approved")
        if reasons:
            raise GatewayError(503, "admission_denied", "; ".join(reasons))
        with self._lock:
            if policy.lease_group in self._leases:
                raise GatewayError(
                    429,
                    "admission_denied",
                    "Conflicting heavy workload lease is active",
                )
            self._leases[policy.lease_group] = workload_id
        return policy.lease_group

    def _exit(self, group: str) -> None:
        with self._lock:
            self._leases.pop(group, None)

    def snapshot(self) -> dict[str, str]:
        with self._lock:
            return dict(self._leases)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Provider:
    def __init__(self, backend: Backend):
        self.backend = backend
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect
        )

    def service_health(self) -> bool:
        raise NotImplementedError

    def model_readiness(self, model: str) -> ModelReadiness:
        raise NotImplementedError

    def open(self, request: urllib.request.Request, timeout: float):
        return self.opener.open(request, timeout=timeout)


class OllamaProvider(Provider):
    def _json_get(self, path: str) -> Any:
        request = urllib.request.Request(f"{self.backend.base_url}{path}", method="GET")
        with self.opener.open(
            request, timeout=self.backend.health_timeout_seconds
        ) as response:
            return json.loads(response.read(4 * 1024 * 1024).decode("utf-8"))

    def service_health(self) -> bool:
        if not self.backend.enabled:
            return False
        try:
            payload = self._json_get("/api/version")
            return isinstance(payload, dict) and bool(payload.get("version"))
        except (OSError, ValueError, UnicodeError, urllib.error.URLError, TimeoutError):
            return False

    @staticmethod
    def _names(payload: Any) -> set[str]:
        if not isinstance(payload, dict) or not isinstance(payload.get("models"), list):
            return set()
        return {
            item["name"]
            for item in payload["models"]
            if isinstance(item, dict) and isinstance(item.get("name"), str)
        }

    @staticmethod
    def _contains(names: set[str], model: str) -> bool:
        return model in names or (":" not in model and f"{model}:latest" in names)

    def model_readiness(self, model: str) -> ModelReadiness:
        if not self.backend.enabled or not self.service_health():
            return ModelReadiness(
                False,
                False,
                False,
                "backend_unavailable",
                "backend service health failed",
            )
        try:
            available = self._names(self._json_get("/api/tags"))
        except (OSError, ValueError, UnicodeError, urllib.error.URLError, TimeoutError):
            return ModelReadiness(
                False, False, False, "backend_unavailable", "model catalog unavailable"
            )
        if not self._contains(available, model):
            return ModelReadiness(
                True, False, False, "model_not_found", "model is not installed"
            )
        try:
            loaded = self._names(self._json_get("/api/ps"))
        except (OSError, ValueError, UnicodeError, urllib.error.URLError, TimeoutError):
            return ModelReadiness(
                False,
                True,
                False,
                "backend_unavailable",
                "loaded-model state unavailable",
            )
        if not self._contains(loaded, model):
            return ModelReadiness(
                True, True, False, "model_cold", "model is available but not loaded"
            )
        return ModelReadiness(True, True, True, None, "model is loaded and ready")


class UnavailableProvider(Provider):
    def service_health(self) -> bool:
        return False

    def model_readiness(self, model: str) -> ModelReadiness:
        return ModelReadiness(
            False,
            False,
            False,
            "backend_unavailable",
            "provider is registered but inactive",
        )


def provider_for(backend: Backend) -> Provider:
    if backend.provider == "ollama":
        return OllamaProvider(backend)
    return UnavailableProvider(backend)


class GatewayApplication:
    def __init__(
        self,
        config: GatewayConfig,
        *,
        telemetry_stream: TextIO | None = None,
        host_probe: Any | None = None,
    ):
        self.config = config
        self.credentials = CredentialRegistry(config.consumers)
        self.admission = AdmissionController(config)
        configured_probe = host_probe or HostHealthProbe(
            oom_marker=config.host_health.recent_oom_marker,
            gpu_reset_marker=config.host_health.recent_gpu_reset_marker,
            production_health_file=config.host_health.production_health_file,
        )
        self.heavy_leases = HeavyLeaseManager(config.heavy_workloads, configured_probe)
        self.providers = {
            backend_id: provider_for(backend)
            for backend_id, backend in config.backends.items()
        }
        self.telemetry_stream = telemetry_stream or sys.stdout
        self._telemetry_lock = threading.Lock()
        self._model_state_lock = threading.Lock()
        self._model_states: dict[tuple[str, str], tuple[str, float]] = {}
        self.started_monotonic = time.monotonic()

    def backend_readiness(self, backend_id: str, model: str) -> ModelReadiness:
        readiness = self.providers[backend_id].model_readiness(model)
        key = (backend_id, model)
        with self._model_state_lock:
            if readiness.model_loaded:
                self._model_states.pop(key, None)
                return readiness
            tracked = self._model_states.get(key)
            if tracked and time.monotonic() - tracked[1] > 60:
                self._model_states.pop(key, None)
                tracked = None
        if readiness.classification == "model_cold" and tracked:
            return ModelReadiness(
                readiness.backend_healthy,
                readiness.model_available,
                False,
                tracked[0],
                "gateway-observed model transition",
            )
        return readiness

    def mark_model_state(self, backend_id: str, model: str, state: str) -> None:
        if state not in {"model_loading", "model_failed", "ready"}:
            raise ValueError("invalid model state transition")
        key = (backend_id, model)
        with self._model_state_lock:
            if state == "ready":
                self._model_states.pop(key, None)
            else:
                self._model_states[key] = (state, time.monotonic())

    def emit(self, record: dict[str, Any]) -> None:
        with self._telemetry_lock:
            self.telemetry_stream.write(
                json.dumps(record, separators=(",", ":"), sort_keys=True) + "\n"
            )
            self.telemetry_stream.flush()

    def backend_health(self) -> dict[str, dict[str, str]]:
        result: dict[str, dict[str, str]] = {}
        for backend_id, backend in self.config.backends.items():
            if not backend.enabled:
                state = "disabled"
            else:
                state = (
                    "healthy"
                    if self.providers[backend_id].service_health()
                    else "unavailable"
                )
            result[backend_id] = {"provider": backend.provider, "service": state}
        return result

    def readiness_report(self) -> dict[str, Any]:
        capabilities: dict[str, dict[str, Any]] = {}
        for capability_id, capability in self.config.capabilities.items():
            backend = self.config.backends[capability.backend_id]
            if backend.enabled:
                readiness = self.backend_readiness(backend.backend_id, capability.model)
            else:
                readiness = ModelReadiness(
                    False,
                    False,
                    False,
                    "backend_unavailable",
                    "backend is registered but disabled",
                )
            capabilities[capability_id] = {
                "backend": backend.backend_id,
                "model": capability.model,
                "backend_healthy": readiness.backend_healthy,
                "model_available": readiness.model_available,
                "model_loaded": readiness.model_loaded,
                "state": readiness.classification or "ready",
                "cold_start_mode": capability.cold_start.mode,
                "cold_start_deadline_seconds": capability.cold_start.ready_timeout_seconds,
            }
        return {
            "gateway": "ready",
            "backends": self.backend_health(),
            "capabilities": capabilities,
        }

    def open_upstream(
        self,
        selection: Selection,
        target: str,
        body: bytes,
        request_id: str,
        headers: dict[str, str],
        initial_timeout: float,
    ) -> tuple[Any, int]:
        forwarded = {
            key: value
            for key, value in headers.items()
            if key.lower() not in HOP_BY_HOP_REQUEST_HEADERS
        }
        forwarded["X-Request-ID"] = request_id
        request = urllib.request.Request(
            f"{selection.backend.base_url}{target}",
            data=body,
            method="POST",
            headers=forwarded,
        )
        retries = 0
        while True:
            try:
                return self.providers[selection.backend.backend_id].open(
                    request, initial_timeout
                ), retries
            except urllib.error.HTTPError as error:
                return error, retries
            except (
                urllib.error.URLError,
                TimeoutError,
                http.client.RemoteDisconnected,
                ConnectionError,
                OSError,
            ):
                if retries >= selection.capability.retries:
                    raise
                retries += 1


def _read_chunk(response: Any) -> bytes:
    reader = getattr(response, "read1", None)
    if callable(reader):
        return reader(64 * 1024)
    fp = getattr(response, "fp", None)
    reader = getattr(fp, "read1", None)
    if callable(reader):
        return reader(64 * 1024)
    return response.read(64 * 1024)


def _set_response_timeout(response: Any, timeout: float) -> None:
    """Change the upstream socket timeout after the cold-start first-byte gate."""
    pending = [response]
    seen: set[int] = set()
    for _ in range(12):
        if not pending:
            break
        current = pending.pop(0)
        if current is None or id(current) in seen:
            continue
        seen.add(id(current))
        setter = getattr(current, "settimeout", None)
        if callable(setter):
            setter(timeout)
            return
        for attribute in ("fp", "raw", "_sock"):
            pending.append(getattr(current, attribute, None))


def _is_timeout_error(error: BaseException) -> bool:
    if isinstance(error, (TimeoutError, socket.timeout)):
        return True
    return isinstance(error, urllib.error.URLError) and isinstance(
        error.reason,
        (TimeoutError, socket.timeout),
    )


class GatewayRequestHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    @property
    def app(self) -> GatewayApplication:
        return self.server.application

    def setup(self) -> None:
        super().setup()
        self.connection.settimeout(30)

    def _start(self) -> None:
        self._started = time.monotonic()
        self._request_id = safe_request_id(self.headers.get("X-Request-ID"))
        self._consumer: Consumer | None = None
        self._selection: Selection | None = None
        self._selected_backend: str | None = None
        self._selected_model: str | None = None
        self._retry_count = 0
        self._finished = False

    def _finish(self, status: int, classification: str | None = None) -> None:
        if self._finished:
            return
        self._finished = True
        selection = self._selection
        self.app.emit(
            {
                "timestamp": dt.datetime.now(dt.timezone.utc)
                .isoformat()
                .replace("+00:00", "Z"),
                "consumer_id": self._consumer.consumer_id if self._consumer else None,
                "request_id": self._request_id,
                "capability": selection.capability.capability_id if selection else None,
                "selected_backend": (
                    selection.backend.backend_id
                    if selection
                    else self._selected_backend
                ),
                "selected_model": selection.backend_model
                if selection
                else self._selected_model,
                "method": self.command,
                "path": urllib.parse.urlsplit(self.path).path,
                "response_status": status,
                "latency_ms": round((time.monotonic() - self._started) * 1000, 3),
                "retry_count": self._retry_count,
                "token_usage": None,
                "tool_call_count": None,
                "error_classification": classification,
            }
        )

    def _json(
        self, status: int, payload: dict[str, Any], *, close: bool = False
    ) -> None:
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("X-Request-ID", self._request_id)
        if close:
            self.send_header("Connection", "close")
            self.close_connection = True
        self.end_headers()
        self.wfile.write(body)

    def _error(self, error: GatewayError, *, close: bool = False) -> None:
        self._json(
            error.status,
            {
                "error": {
                    "message": error.message,
                    "type": "mbfd_gateway_error",
                    "classification": error.classification,
                    "request_id": self._request_id,
                }
            },
            close=close,
        )
        self._finish(error.status, error.classification)

    def _authenticate(self) -> Consumer:
        self._consumer = self.app.credentials.resolve(
            self.headers.get("Authorization", "")
        )
        return self._consumer

    def _safe_framing(self, method: str) -> None:
        if self.headers.get("Transfer-Encoding"):
            raise GatewayError(
                400,
                "admission_denied",
                "Transfer-Encoding request bodies are not accepted",
            )
        values = self.headers.get_all("Content-Length", [])
        if (
            len(values) > 1
            or (values and not values[0].isascii())
            or (values and not values[0].isdecimal())
        ):
            raise GatewayError(
                400, "admission_denied", "Ambiguous request framing is not permitted"
            )
        if method == "POST" and not values:
            raise GatewayError(
                400, "admission_denied", "POST requests require Content-Length"
            )
        if method != "POST" and values and values[0] != "0":
            raise GatewayError(400, "admission_denied", "Request body is not permitted")

    def _read_json_body(self) -> dict[str, Any]:
        length = int(self.headers["Content-Length"])
        if length > self.app.config.limits.max_request_body_bytes:
            raise GatewayError(
                413, "admission_denied", "Request body exceeds the configured limit"
            )
        try:
            body = self.rfile.read(length)
            if len(body) != length:
                raise OSError("incomplete body")
            payload = json.loads(body.decode("utf-8"))
        except (OSError, UnicodeError, json.JSONDecodeError) as error:
            raise GatewayError(
                400,
                "admission_denied",
                "Request body must be one complete UTF-8 JSON object",
            ) from error
        if not isinstance(payload, dict):
            raise GatewayError(
                400, "admission_denied", "Request body must be a JSON object"
            )
        return payload

    def _forward_response(
        self, response: Any, first_chunk: bytes = b""
    ) -> tuple[int, str | None]:
        status = getattr(response, "status", getattr(response, "code", 502))
        self.send_response(status)
        for key, value in response.headers.items():
            if key.lower() not in HOP_BY_HOP_RESPONSE_HEADERS:
                self.send_header(key, value)
        self.send_header("X-Request-ID", self._request_id)
        self.send_header("Transfer-Encoding", "chunked")
        self.end_headers()
        classification = None
        try:
            chunk = first_chunk
            while True:
                if chunk:
                    self.wfile.write(f"{len(chunk):X}\r\n".encode("ascii"))
                    self.wfile.write(chunk)
                    self.wfile.write(b"\r\n")
                    self.wfile.flush()
                chunk = _read_chunk(response)
                if not chunk:
                    break
            self.wfile.write(b"0\r\n\r\n")
            self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError):
            classification = "client_disconnected"
            self.close_connection = True
        except (
            OSError,
            TimeoutError,
            http.client.IncompleteRead,
            urllib.error.URLError,
        ):
            classification = "backend_unavailable"
            self.close_connection = True
        return status, classification

    def _handle_read(self) -> None:
        target = relative_target(self.path)
        path = target_path(target)
        if path not in READ_PATHS:
            raise GatewayError(
                403, "admission_denied", "Gateway route is not permitted"
            )
        if path == "/health/live":
            self._json(
                200,
                {
                    "status": "alive",
                    "uptime_seconds": round(
                        time.monotonic() - self.app.started_monotonic, 3
                    ),
                },
            )
            self._finish(200)
            return
        if path in {"/health", "/health/ready"}:
            self._json(200, {"status": "ready", "backends": self.app.backend_health()})
            self._finish(200)
            return
        if path == "/health/backends":
            self._json(200, self.app.readiness_report())
            self._finish(200)
            return
        if path == "/api/version":
            backend = next(
                (
                    item
                    for item in self.app.config.backends.values()
                    if item.enabled and item.provider == "ollama"
                ),
                None,
            )
            if backend is None:
                raise GatewayError(
                    503, "backend_unavailable", "No active Ollama backend is registered"
                )
            self._selected_backend = backend.backend_id
            upstream_request = urllib.request.Request(
                f"{backend.base_url}/api/version",
                method="GET",
                headers={"X-Request-ID": self._request_id},
            )
            try:
                response = self.app.providers[backend.backend_id].open(
                    upstream_request,
                    backend.health_timeout_seconds,
                )
                try:
                    status, classification = self._forward_response(response)
                finally:
                    response.close()
                self._finish(status, classification)
            except (urllib.error.URLError, TimeoutError, OSError) as error:
                raise GatewayError(
                    503,
                    "backend_unavailable",
                    "Ollama backend version endpoint is unavailable",
                ) from error
            return
        catalog_ids = list(self.app.config.compatibility_models)
        if path == "/v1/models":
            self._json(
                200,
                {
                    "object": "list",
                    "data": [
                        {
                            "id": model,
                            "object": "model",
                            "created": 0,
                            "owned_by": "mbfd",
                        }
                        for model in catalog_ids
                    ],
                },
            )
        else:
            self._json(
                200,
                {"models": [{"name": model, "model": model} for model in catalog_ids]},
            )
        self._finish(200)

    def _handle_post(self) -> None:
        target = relative_target(self.path)
        path = target_path(target)
        payload = self._read_json_body()
        selection = resolve_capability(
            self.app.config,
            self._consumer,
            path,
            payload,
            self.headers.get("X-MBFD-Capability"),
        )
        self._selection = selection
        if not selection.backend.enabled:
            raise GatewayError(
                503,
                "backend_unavailable",
                "Selected backend is registered but unavailable",
            )
        with self.app.admission.acquire(selection.consumer, selection.capability):
            readiness = self.app.backend_readiness(
                selection.backend.backend_id, selection.backend_model
            )
            if readiness.classification == "backend_unavailable":
                raise GatewayError(
                    503,
                    "backend_unavailable",
                    "Selected backend service is unavailable",
                )
            if readiness.classification == "model_not_found":
                raise GatewayError(
                    503,
                    "model_not_found",
                    "Selected model is not available on the backend",
                )
            if readiness.classification == "model_loading":
                raise GatewayError(
                    503, "model_cold", "Selected model is currently loading"
                )
            if readiness.classification == "model_failed":
                raise GatewayError(
                    503,
                    "backend_unavailable",
                    "Selected model failed its most recent start",
                )
            if (
                readiness.classification == "model_cold"
                and selection.capability.cold_start.mode == "reject_if_cold"
            ):
                raise GatewayError(
                    503, "model_cold", "Selected model is available but not loaded"
                )

            cold = readiness.classification == "model_cold"
            request_timeout = min(
                selection.capability.timeout_seconds,
                self.app.config.limits.request_timeout_seconds,
            )
            initial_timeout = (
                min(
                    selection.capability.cold_start.ready_timeout_seconds,
                    request_timeout,
                )
                if cold
                else request_timeout
            )
            encoded = json.dumps(
                rewrite_model(payload, selection, path),
                separators=(",", ":"),
                ensure_ascii=False,
            ).encode("utf-8")
            with self.app.heavy_leases.acquire(
                selection.capability.heavy_workload,
                requires_allocation=cold,
            ):
                try:
                    if cold:
                        self.app.mark_model_state(
                            selection.backend.backend_id,
                            selection.backend_model,
                            "model_loading",
                        )
                    response, retries = self.app.open_upstream(
                        selection,
                        target,
                        encoded,
                        self._request_id,
                        dict(self.headers.items()),
                        initial_timeout,
                    )
                    self._retry_count = retries
                    first = b""
                    if cold:
                        if (
                            getattr(response, "status", getattr(response, "code", 500))
                            >= 400
                        ):
                            response.close()
                            self.app.mark_model_state(
                                selection.backend.backend_id,
                                selection.backend_model,
                                "model_failed",
                            )
                            raise GatewayError(
                                503,
                                "backend_unavailable",
                                "Selected model failed to start",
                            )
                        first = _read_chunk(response)
                        if not first:
                            response.close()
                            self.app.mark_model_state(
                                selection.backend.backend_id,
                                selection.backend_model,
                                "model_failed",
                            )
                            raise GatewayError(
                                503,
                                "model_loading_timeout",
                                "Model did not become ready before policy deadline",
                            )
                        self.app.mark_model_state(
                            selection.backend.backend_id,
                            selection.backend_model,
                            "ready",
                        )
                    _set_response_timeout(response, request_timeout)
                    try:
                        status, classification = self._forward_response(response, first)
                    finally:
                        response.close()
                    self._finish(status, classification)
                except GatewayError:
                    raise
                except (
                    urllib.error.URLError,
                    TimeoutError,
                    http.client.RemoteDisconnected,
                    ConnectionError,
                    OSError,
                ) as error:
                    classification = (
                        "model_loading_timeout"
                        if cold and _is_timeout_error(error)
                        else "backend_unavailable"
                    )
                    message = (
                        "Model did not become ready before policy deadline"
                        if cold
                        else "Selected backend request failed"
                    )
                    if cold:
                        self.app.mark_model_state(
                            selection.backend.backend_id,
                            selection.backend_model,
                            "model_failed",
                        )
                    raise GatewayError(503, classification, message) from error

    def _dispatch(self, method: str) -> None:
        self._start()
        try:
            self._safe_framing(method)
            self._authenticate()
            if method == "GET":
                self._handle_read()
            elif method == "POST":
                self._handle_post()
            else:
                self.send_response(204)
                self.send_header("Allow", "GET, POST, OPTIONS")
                self.send_header("Content-Length", "0")
                self.send_header("X-Request-ID", self._request_id)
                self.end_headers()
                self._finish(204)
        except GatewayError as error:
            self._error(error, close=method == "POST")
        # This is the HTTP process boundary: unexpected defects become a redacted
        # machine error and telemetry record instead of terminating a worker.
        except Exception:  # noqa: BLE001
            self._error(
                GatewayError(500, "gateway_internal_error", "Gateway internal error"),
                close=True,
            )

    def do_GET(self) -> None:
        self._dispatch("GET")

    def do_POST(self) -> None:
        self._dispatch("POST")

    def do_OPTIONS(self) -> None:
        self._dispatch("OPTIONS")

    def log_message(self, *_args: object) -> None:
        return


class GatewayHTTPServer(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address, handler, application: GatewayApplication):
        self.application = application
        super().__init__(address, handler)


def serve(config_path: str | Path) -> None:
    config = load_config(config_path)
    application = GatewayApplication(config)
    servers = [
        GatewayHTTPServer((host, config.port), GatewayRequestHandler, application)
        for host in config.listeners
    ]
    for server in servers[:-1]:
        threading.Thread(target=server.serve_forever, daemon=True).start()
    application.emit(
        {
            "event": "gateway_started",
            "listeners": [f"{host}:{config.port}" for host in config.listeners],
            "timestamp": dt.datetime.now(dt.timezone.utc)
            .isoformat()
            .replace("+00:00", "Z"),
        }
    )
    servers[-1].serve_forever()


def main(argv: list[str] | None = None) -> int:
    arguments = list(sys.argv[1:] if argv is None else argv)
    validate_only = bool(arguments and arguments[0] == "--validate-config")
    if validate_only:
        config_path = arguments[1] if len(arguments) == 2 else ""
    else:
        config_path = (
            arguments[0] if arguments else os.environ.get("MBFD_AI_GATEWAY_CONFIG", "")
        )
    if not config_path:
        message = (
            "--validate-config requires exactly one configuration path"
            if validate_only
            else "MBFD_AI_GATEWAY_CONFIG or one configuration path argument is required"
        )
        print(message, file=sys.stderr)
        return 2
    try:
        config_path = runtime_path(config_path)
        if validate_only:
            load_config(config_path)
            print("gateway configuration valid")
        else:
            serve(config_path)
    except (TypeError, ValueError, OSError) as error:
        print(f"gateway configuration failed: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
