#!/usr/bin/env python3
"""Hermes inventory-driven site/topology monitor (v2 — persistent state).

Consumes an authoritative service inventory (JSON) and drives a small state
machine per service. Designed for execution from a systemd oneshot timer that
runs every couple of minutes, so it persists state across invocations in a
single versioned JSON document written atomically with an exclusive lock.

Design goals (see remediation spec sections 7-12):
  * State persists across separate processes / timer cycles.
  * Atomic writes (temp file + fsync + os.replace) and an exclusive lock so two
    timer runs can never interleave.
  * Corrupt/unreadable state is quarantined, never silently discarded.
  * Boot transitions reset counters (no stale pre-reboot outage carried over).
  * Deployment transitions trigger grace once per new deployment marker.
  * Idempotent alerts via a per-service fingerprint (service + state +
    classification + endpoint + boot_id + deployment_id).
  * Probe functions are module-level and monkeypatchable so tests need no network.
  * HTTP probes honour an explicit method, status set, optional redirect target,
    bounded timeouts, a max response byte count, and a finite redirect limit.
  * Notifications are invoked with an argument list (never shell=True), bounded,
    escaped, timeout-guarded, and failure-safe.

CLI modes:
  run       probe everything, persist state, notify on transitions (mode depends
            on --notify-mode: production | suppressed | test).
  validate  probe everything, persist state, never notify (dry run report).
  status    print a one-line per-service report (no notify).
"""
from __future__ import annotations

import calendar
import hashlib
import json
import os
import socket
import ssl
import subprocess
import time
import urllib.error
import urllib.request
from dataclasses import asdict, dataclass, field
from typing import Callable, Optional

STATES = ("HEALTHY", "SUSPECT", "DEGRADED", "OUTAGE", "RECOVERING")
STATE_RANK = {s: i for i, s in enumerate(STATES)}

BOOT_ID_PATH = "/proc/sys/kernel/random/boot_id"
UPTIME_PATH = "/proc/uptime"

DEFAULT_STATE_DIR = "/var/lib/mbfd-site-monitor-v2"
STATE_FILENAME = "state.json"
LOCK_FILENAME = "state.lock"

# Maximum characters kept from an untrusted response/excerpt when building a
# notification message (defence against log/notification injection and bloat).
MAX_MESSAGE_FIELD = 200
SERVICE_ID_ALLOWED = "`!@#$%^&*()+-={}[]|:;\"'<>,?/\\ "  # disallowed chars set


# --------------------------------------------------------------------------- #
# Runtime context (injectable for tests)
# --------------------------------------------------------------------------- #
@dataclass
class RuntimeContext:
    boot_id: str = "fixed-boot-id"
    uptime_seconds: float = 3600.0
    deployment_id: str = ""
    now: float = 0.0


def read_runtime(boot_grace_seconds: float = 600) -> RuntimeContext:
    boot_id = "unknown"
    try:
        with open(BOOT_ID_PATH) as fh:
            boot_id = fh.read().strip()
    except OSError:
        pass
    uptime = 1e9
    try:
        with open(UPTIME_PATH) as fh:
            uptime = float(fh.read().split()[0])
    except OSError:
        pass
    return RuntimeContext(
        boot_id=boot_id,
        uptime_seconds=uptime,
        now=time.time(),
        deployment_id=_read_deployment_id(),
    )


def _read_deployment_id() -> str:
    """Return a stable identifier for the most recent deployment, or ''.

    A deployment marker is written by the deploy pipeline. We derive the id from
    its content (so re-running the same deploy does not re-trigger grace) and
    only report it when fresh.
    """
    window = 600
    for path in (
        "/opt/mbfd/mbfd-hub/deploy-marker.json",
        "/opt/mbfd/deploy-marker.json",
    ):
        try:
            if not os.path.exists(path):
                continue
            if (time.time() - os.path.getmtime(path)) > window:
                continue
            with open(path) as fh:
                data = json.load(fh)
            ident = str(data.get("id") or data.get("commit") or os.path.basename(path))
            return ident
        except (OSError, ValueError):
            continue
    return ""


# --------------------------------------------------------------------------- #
# Persistent state store
# --------------------------------------------------------------------------- #
@dataclass
class ServiceState:
    state: str = "HEALTHY"
    consecutive_failures: int = 0
    consecutive_successes: int = 0
    last_transition: str = ""
    last_failure: str = ""
    last_success: str = ""
    last_alert_fingerprint: str = ""
    last_alert_at: str = ""
    last_observation: dict = field(default_factory=dict)

    def to_dict(self) -> dict:
        return asdict(self)

    @classmethod
    def from_dict(cls, d: dict) -> "ServiceState":
        known = {f for f in cls.__dataclass_fields__}  # type: ignore[attr-defined]
        clean = {k: v for k, v in d.items() if k in known}
        return cls(**clean)


@dataclass
class StateDocument:
    schema_version: int = 1
    boot_id: str = ""
    last_deployment_id: str = ""
    deployment_grace_until: str = ""
    last_updated: str = ""
    services: dict = field(default_factory=dict)

    def to_dict(self) -> dict:
        return asdict(self)

    @classmethod
    def from_dict(cls, d: dict) -> "StateDocument":
        sd = cls(
            schema_version=int(d.get("schema_version", 1)),
            boot_id=d.get("boot_id", ""),
            last_deployment_id=d.get("last_deployment_id", ""),
            deployment_grace_until=d.get("deployment_grace_until", ""),
            last_updated=d.get("last_updated", ""),
            services={
                sid: ServiceState.from_dict(sv)
                for sid, sv in (d.get("services") or {}).items()
            },
        )
        return sd


class StateStore:
    """Versioned, atomic, corruption-tolerant state persistence."""

    def __init__(self, state_dir: str, clock: Callable[[], float] = time.time):
        self.state_dir = state_dir
        self.state_path = os.path.join(state_dir, STATE_FILENAME)
        self.clock = clock

    def load(self) -> tuple[Optional[StateDocument], Optional[str]]:
        """Return (document, error). On corruption the bad file is quarantined."""
        if not os.path.exists(self.state_path):
            return None, None
        try:
            with open(self.state_path, "r", encoding="utf-8") as fh:
                raw = fh.read()
            data = json.loads(raw)
        except (OSError, ValueError) as exc:
            self._quarantine("unreadable-state", raw if "raw" in dir() else None)
            return None, f"corrupt-state:{type(exc).__name__}"
        version = data.get("schema_version", 1)
        if version > 1:
            # Future schema we do not understand: quarantine rather than guess.
            self._quarantine("unsupported-schema", raw)
            return None, f"unsupported-schema-version:{version}"
        try:
            doc = StateDocument.from_dict(data)
        except Exception as exc:  # defensive: any shape error -> quarantine
            self._quarantine("malformed-state", raw)
            return None, f"malformed-state:{type(exc).__name__}"
        return doc, None

    def _quarantine(self, reason: str, raw: Optional[str]) -> None:
        ts = time.strftime("%Y%m%d-%H%M%S")
        dest = os.path.join(self.state_dir, f"state.{reason}.{ts}.corrupt")
        try:
            if raw is not None:
                with open(dest, "w", encoding="utf-8") as out:
                    out.write(raw)
            elif os.path.exists(self.state_path):
                os.replace(self.state_path, dest)
        except OSError:
            pass

    def save(self, doc: StateDocument) -> None:
        os.makedirs(self.state_dir, exist_ok=True)
        tmp = os.path.join(self.state_dir, f".state.{os.getpid()}.tmp")
        data = json.dumps(doc.to_dict(), indent=2, sort_keys=True)
        with open(tmp, "w", encoding="utf-8") as fh:
            fh.write(data)
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(tmp, self.state_path)
        # Best-effort durability of the directory entry (POSIX only).
        try:
            o_dir = getattr(os, "O_DIRECTORY", 0)
            dfd = os.open(self.state_dir, o_dir)
            try:
                os.fsync(dfd)
            finally:
                os.close(dfd)
        except OSError:
            pass


# --------------------------------------------------------------------------- #
# Locking (exclusive run guard)
# --------------------------------------------------------------------------- #
class LockHeld(Exception):
    pass


def acquire_lock(state_dir: str):
    """Acquire an exclusive advisory lock on <state_dir>/state.lock.

    Returns the open lock file object (must be kept alive for the run). Raises
    LockHeld if another process holds it. Uses fcntl on POSIX; callers on
    non-POSIX systems (local dev) fall back to a best-effort pid-file guard.
    """
    os.makedirs(state_dir, exist_ok=True)
    lock_path = os.path.join(state_dir, LOCK_FILENAME)
    try:
        import fcntl  # type: ignore

        fh = open(lock_path, "w")
        try:
            fcntl.flock(fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            fh.close()
            raise LockHeld(lock_path)
        return fh
    except ImportError:
        # Non-POSIX (e.g. local Windows dev). Use a pidfile guard so the same
        # machine cannot run two copies accidentally.
        pid_path = lock_path + ".pid"
        if os.path.exists(pid_path):
            try:
                with open(pid_path) as pf:
                    old = int(pf.read().strip())
                os.kill(old, 0)  # raises if not running
                raise LockHeld(lock_path)
            except (OSError, ValueError):
                pass
        fh = open(pid_path, "w")
        fh.write(str(os.getpid()))
        fh.flush()
        return fh


# --------------------------------------------------------------------------- #
# Probe result
# --------------------------------------------------------------------------- #
@dataclass
class ProbeResult:
    status: str  # healthy | failed | starting | no_healthcheck | absent | error
    code: Optional[int] = None
    detail: str = ""
    excerpt: str = ""


# --------------------------------------------------------------------------- #
# Probes (monkeypatchable)
# --------------------------------------------------------------------------- #
def _capped_read(resp, max_bytes: int) -> str:
    try:
        data = resp.read(max_bytes + 1)
    except Exception:
        return ""
    text = data.decode("utf-8", "replace") if isinstance(data, (bytes, bytearray)) else str(data)
    return text[:max_bytes]


class _BoundedRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, limit: int = 0):
        self.limit = limit
        self.count = 0

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        # Default: do not follow (we inspect the 3xx ourselves). When a limit is
        # given, follow up to that many times then stop.
        if self.limit <= 0:
            return None
        self.count += 1
        if self.count > self.limit:
            return None
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def probe_http(
    url: str,
    method: str = "GET",
    expected_statuses: tuple = (200,),
    expected_redirect_host: Optional[str] = None,
    expected_redirect_path_prefix: Optional[str] = None,
    timeout: int = 10,
    max_bytes: int = 65536,
    max_redirects: int = 0,
    verify_tls: bool = True,
    user_agent: str = "MBFD-Hermes/2.0",
) -> ProbeResult:
    handler = _BoundedRedirect(limit=max_redirects)
    if not verify_tls:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        opener = urllib.request.build_opener(handler, urllib.request.HTTPSHandler(context=ctx))
    else:
        opener = urllib.request.build_opener(handler)
    req = urllib.request.Request(url, method=method, headers={"User-Agent": user_agent})
    try:
        resp = opener.open(req, timeout=timeout)
        code = resp.status
        location = resp.headers.get("location", "")
        excerpt = _capped_read(resp, max_bytes)
        resp.close()
    except urllib.error.HTTPError as exc:
        code = exc.code
        location = exc.headers.get("location", "")
        try:
            excerpt = _capped_read(exc, max_bytes)
        except Exception:
            excerpt = ""
    except ssl.SSLError as exc:
        return ProbeResult(status="failed", code=None, detail=f"tls-error:{exc.reason if hasattr(exc, 'reason') else type(exc).__name__}")
    except socket.timeout:
        return ProbeResult(status="failed", code=None, detail="timeout")
    except (urllib.error.URLError, OSError, ValueError) as exc:
        reason = getattr(exc, "reason", exc)
        return ProbeResult(status="failed", code=None, detail=f"connection-error:{type(reason).__name__}")

    ok = code in expected_statuses
    detail = ""
    if ok and expected_redirect_host and code in (300, 301, 302, 303, 307, 308):
        from urllib.parse import urlparse

        host = (urlparse(location).netloc or "").split("@")[-1].split(":")[0].lower()
        if host and host != expected_redirect_host.lower():
            ok = False
            detail = f"redirect-host-mismatch:got={host} want={expected_redirect_host}"
        elif expected_redirect_path_prefix and not urlparse(location).path.startswith(expected_redirect_path_prefix):
            ok = False
            detail = f"redirect-path-mismatch:got={urlparse(location).path}"
    return ProbeResult(
        status="healthy" if ok else "failed",
        code=code,
        detail=detail or (f"status={code}" if ok else f"unexpected-status:{code}"),
        excerpt=excerpt[:MAX_MESSAGE_FIELD],
    )


def probe_docker(container: str, timeout: int = 10) -> ProbeResult:
    if not container:
        return ProbeResult(status="no_healthcheck", detail="no-container-configured")
    try:
        proc = subprocess.run(
            ["docker", "inspect", "-f", "{{.State.Health.Status}}", container],
            timeout=timeout, capture_output=True, text=True,
        )
    except FileNotFoundError:
        return ProbeResult(status="error", detail="docker-unavailable")
    except subprocess.TimeoutExpired:
        return ProbeResult(status="error", detail="docker-timeout")
    except OSError as exc:
        return ProbeResult(status="error", detail=f"docker-error:{type(exc).__name__}")
    if proc.returncode != 0:
        # Container absent / inspect failed.
        if "No such object" in proc.stderr or "No such container" in proc.stderr:
            return ProbeResult(status="absent", detail="container-absent")
        return ProbeResult(status="error", detail="docker-inspect-failed")
    out = (proc.stdout or "").strip()
    if out == "healthy":
        return ProbeResult(status="healthy", detail="docker-healthy")
    if out == "starting":
        return ProbeResult(status="starting", detail="docker-starting")
    if out == "unhealthy":
        return ProbeResult(status="failed", detail="docker-unhealthy")
    if out == "":
        return ProbeResult(status="no_healthcheck", detail="no-healthcheck-defined")
    return ProbeResult(status="no_healthcheck", detail=f"unknown-health:{out}")


def probe_tcp(host: str, port: int, timeout: int = 5) -> ProbeResult:
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return ProbeResult(status="healthy", detail="tcp-open")
    except (OSError, ValueError):
        return ProbeResult(status="failed", detail="tcp-closed")


def probe_command(command: str, timeout: int = 10) -> ProbeResult:
    import shlex

    try:
        argv = shlex.split(command)
    except ValueError:
        return ProbeResult(status="failed", detail="bad-command-shell-metachar")
    if not argv:
        return ProbeResult(status="failed", detail="empty-command")
    try:
        rc = subprocess.run(argv, timeout=timeout, stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL).returncode
        return ProbeResult(status="healthy" if rc == 0 else "failed",
                           detail=f"rc={rc}")
    except (OSError, subprocess.SubprocessError):
        return ProbeResult(status="error", detail="command-error")


def probe_systemd(unit: str, timeout: int = 10) -> ProbeResult:
    if not unit:
        return ProbeResult(status="failed", detail="empty-unit")
    try:
        rc = subprocess.run(["systemctl", "is-active", "--quiet", unit],
                            timeout=timeout).returncode
        return ProbeResult(status="healthy" if rc == 0 else "failed",
                           detail="systemd-active" if rc == 0 else "systemd-inactive")
    except (OSError, subprocess.SubprocessError):
        return ProbeResult(status="error", detail="systemd-error")


# --------------------------------------------------------------------------- #
# HLS / benign cloudflared filtering
# --------------------------------------------------------------------------- #
_BENIGN_PATTERNS = (
    "context canceled",
    "request ended abruptly",
    "canceled by remote",
    "client disconnected",
    "Incoming request ended abruptly",
)


def is_benign_line(line: str) -> bool:
    return any(p in line for p in _BENIGN_PATTERNS)


def hls_escalation(evidence: dict) -> bool:
    return bool(
        evidence.get("stale_playlist")
        or evidence.get("missing_segments")
        or evidence.get("encoder_exit")
        or evidence.get("manifest_failure")
        or evidence.get("frozen_media_sequence")
        or evidence.get("sustained_public_failure")
    )


# --------------------------------------------------------------------------- #
# Scheduled-command recurrence
# --------------------------------------------------------------------------- #
def detect_scheduled_recurrence(log_path: str, fingerprint: str,
                                window_seconds: int = 86400, min_repeats: int = 2) -> bool:
    if not log_path or not os.path.exists(log_path):
        return False
    repeats = 0
    try:
        with open(log_path, "r", errors="ignore") as fh:
            for line in fh:
                if fingerprint not in line:
                    continue
                repeats += 1
                if repeats >= min_repeats:
                    return True
    except OSError:
        return False
    return False


# --------------------------------------------------------------------------- #
# Local / external diagnosis
# --------------------------------------------------------------------------- #
def classify(local: str, external: Optional[str]) -> str:
    """Classify the diagnosis from local-origin and public-route results.

    local/external are one of: 'healthy', 'failed', 'no_healthcheck', None.
    """
    if local == "healthy" and external in (None, "healthy"):
        return "Healthy"
    if local == "healthy" and external == "failed":
        return "Tunnel/DNS/Access path"
    if local == "failed" and external == "failed":
        return "Origin or dependency"
    if local == "failed" and external in ("healthy", "no_healthcheck", None):
        return "Stale cache, probe mismatch, or wrong topology"
    if local == "no_healthcheck":
        return "No health check (not asserted)"
    if local == "healthy" and external == "no_healthcheck":
        return "Healthy (no public route check)"
    return "Unknown"


# --------------------------------------------------------------------------- #
# State machine
# --------------------------------------------------------------------------- #
def next_state(healthy: bool, in_grace: bool, st: ServiceState,
               transient: int, outage: int, recovery: int = 2) -> tuple[str, bool]:
    prev = st.state
    if healthy:
        st.consecutive_failures = 0
        st.consecutive_successes += 1
        if prev in ("SUSPECT", "DEGRADED", "OUTAGE", "RECOVERING"):
            # Require N consecutive healthy observations before clearing the
            # RECOVERING state, so a single lucky probe does not flap to HEALTHY.
            st.state = "HEALTHY" if st.consecutive_successes >= recovery else "RECOVERING"
        else:
            st.state = "HEALTHY"
    else:
        st.consecutive_failures += 1
        st.consecutive_successes = 0
        if in_grace:
            st.state = "SUSPECT"
        elif st.consecutive_failures < transient:
            st.state = "SUSPECT"
        elif st.consecutive_failures < outage:
            st.state = "DEGRADED"
        else:
            st.state = "OUTAGE"
    worse = STATE_RANK[st.state] > STATE_RANK[prev] and st.state != "RECOVERING"
    return st.state, worse


def compute_fingerprint(service_id: str, state: str, diagnosis: str,
                        endpoint: str, boot_id: str, deployment_id: str) -> str:
    material = "|".join([service_id, state, diagnosis, endpoint, boot_id, deployment_id])
    return hashlib.sha256(material.encode("utf-8")).hexdigest()[:32]


def should_notify(service_id: str, new_state: str, prev_state: str, st: ServiceState,
                  fingerprint: str, now: float, cooldown: int) -> bool:
    """Return True when a transition warrants a notification.

    RECOVERING is intentionally silent; the single recovery summary is emitted
    when the service returns to HEALTHY. Duplicates (same fingerprint within the
    cooldown) are suppressed.
    """
    if new_state == "RECOVERING":
        return False
    if st.last_alert_fingerprint == fingerprint:
        return False
    if new_state == "HEALTHY" and prev_state != "HEALTHY" and st.last_alert_fingerprint:
        return True  # recovery summary (exactly once per outage)
    if new_state in ("OUTAGE", "DEGRADED", "SUSPECT"):
        return True
    return False


# --------------------------------------------------------------------------- #
# Probe a single service (maps a ProbeResult to healthy/failed)
# --------------------------------------------------------------------------- #
def _local_healthy(res: ProbeResult) -> bool:
    # 'no_healthcheck' and docker-unavailable do not invent a failure.
    if res.status in ("healthy", "starting", "no_healthcheck", "error"):
        return True
    if res.status in ("failed", "absent"):
        return False
    return True


def probe_service_local(
    svc: dict,
    http_fn: Callable = probe_http,
    tcp_fn: Callable = probe_tcp,
    cmd_fn: Callable = probe_command,
    sd_fn: Callable = probe_systemd,
    docker_fn: Callable = probe_docker,
) -> ProbeResult:
    h = svc.get("local_health") or {}
    t = h.get("type")
    default_timeout = int(h.get("timeout", 10))
    if t == "http":
        url = f"http://{svc['origin_host']}:{svc['origin_port']}{h.get('path', '/')}"
        r = http_fn(
            url,
            method=h.get("method", "GET"),
            expected_statuses=tuple(h.get("expected_statuses", [h.get("expected_status", 200)])),
            expected_redirect_host=h.get("expected_redirect_host"),
            expected_redirect_path_prefix=h.get("expected_redirect_path_prefix"),
            timeout=default_timeout,
            max_bytes=int(h.get("max_bytes", 65536)),
            max_redirects=int(h.get("max_redirects", 0)),
            verify_tls=bool(h.get("verify_tls", True)),
        )
        return r
    if t == "tcp":
        r = tcp_fn(svc["origin_host"], svc["origin_port"])
        return ProbeResult(status="healthy" if r else "failed",
                           detail="tcp" if r else "tcp-closed")
    if t == "command":
        rc = cmd_fn(h.get("command", "true"))
        if rc.status is True:
            return ProbeResult(status="healthy")
        if rc.status is False and h.get("fallback") == "tcp":
            r = tcp_fn(svc["origin_host"], svc["origin_port"])
            return ProbeResult(status="healthy" if r else "failed", detail="command-fallback")
        return ProbeResult(status="failed" if rc.status is False else "error")
    if t == "systemd":
        return sd_fn(h.get("unit", ""))
    if t == "docker":
        return docker_fn(svc.get("container"))
    # Unknown probe type -> do not assert failure.
    return ProbeResult(status="no_healthcheck", detail="unknown-probe-type")


def probe_service_external(svc: dict, http_fn: Callable = probe_http) -> Optional[ProbeResult]:
    hosts = svc.get("public_hostnames") or []
    if not hosts:
        return None
    eh = svc.get("external_health") or {}
    url = f"https://{hosts[0]}/"
    return http_fn(
        url,
        method=eh.get("method", "GET"),
        expected_statuses=tuple(eh.get("expected_statuses", [eh.get("expected_status", 200)])),
        expected_redirect_host=eh.get("expected_redirect_host"),
        expected_redirect_path_prefix=eh.get("expected_redirect_path_prefix"),
        timeout=int(eh.get("timeout", 10)),
        max_redirects=int(eh.get("max_redirects", 0)),
    )


# --------------------------------------------------------------------------- #
# Monitoring run
# --------------------------------------------------------------------------- #
def run_monitor(
    inventory: dict,
    ctx: RuntimeContext,
    store: StateStore,
    notify_fn: Optional[Callable] = None,
    http_fn: Callable = probe_http,
    tcp_fn: Callable = probe_tcp,
    cmd_fn: Callable = probe_command,
    sd_fn: Callable = probe_systemd,
    docker_fn: Callable = probe_docker,
    dry_run: bool = False,
) -> dict:
    boot_grace = int(inventory.get("boot_grace_seconds", 600))
    deploy_grace = int(inventory.get("deployment_grace_seconds", 600))
    transient = int(inventory.get("transient_failure_threshold", 3))
    outage = int(inventory.get("outage_threshold", 5))
    recovery = int(inventory.get("recovery_success_threshold", 2))
    cooldown = int(inventory.get("alert_cooldown_seconds", 900))

    doc, load_err = store.load()
    if doc is None:
        doc = StateDocument()
        if load_err:
            doc.services = {}  # start safe baseline; corruption already quarantined
    now_iso = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(ctx.now))

    in_boot_grace = ctx.uptime_seconds < boot_grace

    # Deployment grace: trigger once per *new* deployment marker, lasting a
    # fixed window. We only (re)arm the window when the id actually changes, so
    # a marker file that keeps existing does not repeatedly restart grace.
    deploy_changed = bool(ctx.deployment_id) and ctx.deployment_id != doc.last_deployment_id
    if deploy_changed:
        doc.deployment_grace_until = time.strftime(
            "%Y-%m-%dT%H:%M:%SZ", time.gmtime(ctx.now + deploy_grace))
    if ctx.deployment_id and doc.deployment_grace_until:
        try:
            until = calendar.timegm(time.strptime(doc.deployment_grace_until, "%Y-%m-%dT%H:%M:%SZ"))
            in_deploy_grace = ctx.now < until
        except ValueError:
            in_deploy_grace = False
    else:
        in_deploy_grace = False

    # Boot transition: a new boot resets counters (no stale outage carried over).
    boot_changed = bool(doc.boot_id) and doc.boot_id != ctx.boot_id
    if boot_changed:
        for st in doc.services.values():
            st.consecutive_failures = 0
            st.consecutive_successes = 0
            st.state = "HEALTHY"
            st.last_alert_fingerprint = ""
            st.last_alert_at = ""

    in_grace = in_boot_grace or in_deploy_grace

    report = {
        "boot_id": ctx.boot_id,
        "boot_changed": boot_changed,
        "in_boot_grace": in_boot_grace,
        "in_deployment_grace": in_deploy_grace,
        "deployment_id": ctx.deployment_id,
        "state_load_error": load_err,
        "services": [],
    }
    alerts = []

    for svc in inventory.get("services", []):
        sid = svc["id"]
        endpoint = f"{svc.get('origin_host')}:{svc.get('origin_port')}"
        st = doc.services.setdefault(sid, ServiceState())

        local = probe_service_local(svc, http_fn, tcp_fn, cmd_fn, sd_fn, docker_fn)
        external = probe_service_external(svc, http_fn) if svc.get("public_hostnames") else None
        ext_status = external.status if external else None
        diagnosis = classify(local.status, ext_status)
        healthy = _local_healthy(local)

        prev_state = st.state
        new_state, _ = next_state(healthy, in_grace, st, transient, outage, recovery)
        fingerprint = compute_fingerprint(sid, new_state, diagnosis, endpoint,
                                          ctx.boot_id, ctx.deployment_id)
        notify = (not dry_run) and should_notify(sid, new_state, prev_state, st, fingerprint, ctx.now, cooldown)

        if notify and notify_fn is not None:
            try:
                notify_fn(svc, new_state, diagnosis, st)
            except Exception:
                # Notification failure must never corrupt monitor state.
                notify = False

        if notify:
            st.last_alert_fingerprint = fingerprint
            st.last_alert_at = now_iso
        if new_state != st.state:
            st.last_transition = now_iso
        st.last_observation = {
            "local": local.status,
            "external": ext_status,
            "diagnosis": diagnosis,
            "code": local.code,
        }
        if healthy:
            st.last_success = now_iso
        else:
            st.last_failure = now_iso

        report["services"].append({
            "id": sid,
            "local": local.status,
            "external": ext_status,
            "diagnosis": diagnosis,
            "state": new_state,
            "consecutive_failures": st.consecutive_failures,
            "consecutive_successes": st.consecutive_successes,
            "would_alert": notify,
        })
        if notify:
            alerts.append(sid)

    doc.boot_id = ctx.boot_id
    doc.last_deployment_id = ctx.deployment_id
    doc.last_updated = now_iso
    store.save(doc)
    report["alerts"] = alerts
    return report


# --------------------------------------------------------------------------- #
# Notifications (safe: argument list, no shell=True, bounded, escaped)
# --------------------------------------------------------------------------- #
def _sanitize_id(service_id: str) -> str:
    # Reject anything but a narrow allowlist to prevent shell/markup injection.
    if not service_id or any(c in SERVICE_ID_ALLOWED for c in service_id):
        raise ValueError(f"invalid service id: {service_id!r}")
    return service_id


def _bounded(text: str, limit: int = MAX_MESSAGE_FIELD) -> str:
    text = (text or "").replace("\n", " ").replace("\r", " ")
    return text[:limit]


def notify_hermes(svc: dict, state: str, diagnosis: str, st,
                  hermes_user: str = "mbfd-aiops",
                  hermes_home: str = "/opt/mbfd/hermes",
                  reports_dir: str = "/opt/mbfd/site-monitor/reports",
                  timeout: int = 60) -> None:
    """Send a state-change alert via the proven Hermes Telegram path.

    The message body is written to a file and passed via --file; only the
    validated service id is interpolated into the subject. No shell=True is used.
    """
    sid = _sanitize_id(svc["id"])
    display = _bounded(str(svc.get("display_name", sid)))
    ts = time.strftime("%Y%m%d-%H%M%S")
    os.makedirs(reports_dir, exist_ok=True)
    path = os.path.join(reports_dir, f"hermes-{ts}-{sid}.txt")
    text = (
        f"Severity: {state}\n"
        f"Affected service: {display} ({sid})\n"
        f"Current state: {state}\n"
        f"Diagnosis: {_bounded(diagnosis)}\n"
        f"Consecutive failures: {st.consecutive_failures}\n"
        f"Boot ID: {_bounded(st.last_observation.get('boot_id', '') if isinstance(st.last_observation, dict) else '')}\n"
    )
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)

    env = os.environ.copy()
    env["HOME"] = "/var/lib/mbfd-aiops"
    env["HERMES_HOME"] = "/opt/mbfd/hermes/home"
    env["PATH"] = (
        "/var/lib/mbfd-aiops/.local/bin:/opt/mbfd/hermes/home/node/bin:"
        "/opt/mbfd/hermes/home/bin:/usr/local/sbin:/usr/local/bin:"
        "/usr/sbin:/usr/bin:/sbin:/bin"
    )
    env["ASSESSMENT_FILE"] = path
    # Only a narrow, validated set of env vars is forwarded to the sender.
    forwarded = {k: env[k] for k in ("HOME", "HERMES_HOME", "PATH", "ASSESSMENT_FILE")}
    subject = f"[MBFD Hermes {state}] {sid}"
    # The script is a fixed template; sid is allowlist-checked so single-quoting
    # it inside the script is safe. No shell=True is used.
    script = f'cd {hermes_home} && hermes send --to telegram --subject {subject!r} --file "$ASSESSMENT_FILE"'
    try:
        subprocess.run(
            ["sudo", "-u", hermes_user, "env"]
            + [f"{k}={v}" for k, v in forwarded.items()]
            + ["bash", "-lc", script],
            text=True,
            timeout=timeout,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            shell=False,
        )
    except (OSError, subprocess.SubprocessError):
        pass


def notify_test(svc: dict, state: str, diagnosis: str, st,
                hermes_user: str = "mbfd-aiops") -> None:
    """Clearly labelled TEST delivery that does NOT fabricate a real outage."""
    sid = _sanitize_id(svc["id"])
    print(f"[TEST NOTIFICATION] service={sid} state={state} diagnosis={_bounded(diagnosis)}")


def notify_noop(svc, state, diagnosis, st) -> None:
    return None


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #
def _load_inventory(path: str) -> dict:
    with open(path) as fh:
        return json.load(fh)


def main(argv=None) -> int:
    import argparse

    ap = argparse.ArgumentParser(description="Hermes inventory-driven monitor (v2)")
    ap.add_argument("--inventory", default="/opt/mbfd/runbooks/service-inventory.json")
    ap.add_argument("--state-dir", default=os.environ.get("MBFD_MONITOR_STATE_DIR", DEFAULT_STATE_DIR))
    ap.add_argument("--notify-mode", choices=["production", "suppressed", "test"],
                    default="suppressed",
                    help="production=send, suppressed=log only (shadow), test=labelled test msg")
    ap.add_argument("mode", nargs="?", default="run",
                    choices=["run", "validate", "status"])
    args = ap.parse_args(argv)

    inv = _load_inventory(args.inventory)
    ctx = read_runtime(inv.get("boot_grace_seconds", 600))
    store = StateStore(args.state_dir)

    try:
        lock = acquire_lock(args.state_dir)
    except LockHeld:
        print(json.dumps({"error": "lock-held", "state_dir": args.state_dir}))
        return 0

    try:
        if args.notify_mode == "production":
            notify_fn = notify_hermes
        elif args.notify_mode == "test":
            notify_fn = notify_test
        else:
            notify_fn = notify_noop

        if args.mode in ("validate", "status"):
            rep = run_monitor(inv, ctx, store, notify_fn=None, dry_run=True)
        else:
            rep = run_monitor(inv, ctx, store, notify_fn=notify_fn, dry_run=False)
    finally:
        try:
            lock.close()
        except OSError:
            pass

    if args.mode == "status":
        for s in rep["services"]:
            print(f"{s['id']:26} {s['state']:10} local={s['local']} ext={s['external']} diag={s['diagnosis']}")
        return 0
    print(json.dumps({
        "alerts": rep["alerts"],
        "in_boot_grace": rep["in_boot_grace"],
        "in_deployment_grace": rep["in_deployment_grace"],
        "boot_changed": rep["boot_changed"],
        "state_load_error": rep["state_load_error"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
