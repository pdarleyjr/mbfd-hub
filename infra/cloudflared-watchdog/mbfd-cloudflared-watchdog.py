#!/usr/bin/env python3
"""MBFD Cloudflare Tunnel watchdog.

Durable protection for the public Media Control path served through the
on-host ``cloudflared`` connector. It is deliberately conservative:

* It NEVER restarts Media Control, OBS, the podium, audio, or cameras.
* It restarts ONLY ``cloudflared`` (the tunnel connector).
* It does NOT act on a single transient failure: a configurable number of
  consecutive failed health checks must occur within a rolling window.
* A cooldown prevents restart storms, and a per-hour restart cap stops
  self-inflicted storms entirely (alert-only once exceeded).
* Recovery is verified immediately after any restart.

The canonical classroom-facing hostname is ``media.mbfdhub.com`` (origin
``http://localhost:8096``). ``media-control.mbfdhub.com`` is the
Cloudflare-Access-protected admin variant; both share the same tunnel.

Root cause this guards against (observed 2026-07-22 11:41 EDT): a stale/
degraded tunnel connection (``Application error 0x0 (remote)`` /
``accept stream listener encountered a failure``) caused mid-response request
cancellations (``stream ... canceled by remote with error code 0``) for static
assets on ``media.mbfdhub.com``. Restarting cloudflared re-registered four
fresh QUIC connections and cleared the failures.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path

import urllib.error
import urllib.request

LOGGER = logging.getLogger("mbfd-cloudflared-watchdog")

DEFAULT_PUBLIC_URL = os.getenv("MBFD_WATCH_PUBLIC_URL", "https://media.mbfdhub.com")
DEFAULT_HEALTH_PATH = os.getenv("MBFD_WATCH_HEALTH_PATH", "/api/status")
DEFAULT_SOCKETIO_PATH = os.getenv(
    "MBFD_WATCH_SOCKETIO_PATH", "/socket.io/?EIO=4&transport=polling"
)
DEFAULT_FAILURE_THRESHOLD = int(os.getenv("MBFD_WATCH_FAILURE_THRESHOLD", "3"))
DEFAULT_COOLDOWN_SECONDS = int(os.getenv("MBFD_WATCH_COOLDOWN_SECONDS", "300"))
DEFAULT_MAX_RESTARTS_PER_HOUR = int(os.getenv("MBFD_WATCH_MAX_RESTARTS_PER_HOUR", "3"))
DEFAULT_REQUEST_TIMEOUT = float(os.getenv("MBFD_WATCH_TIMEOUT", "8"))
DEFAULT_STATE_DIR = os.getenv(
    "MBFD_WATCH_STATE_DIR", "/var/lib/mbfd-cloudflared-watchdog"
)
CLOUDFLARED_UNIT = os.getenv("MBFD_WATCH_CLOUDFLARED_UNIT", "cloudflared.service")


@dataclass
class WatchdogConfig:
    public_url: str
    health_path: str
    socketio_path: str
    failure_threshold: int
    cooldown_seconds: int
    max_restarts_per_hour: int
    request_timeout: float
    state_dir: Path
    dry_run: bool


@dataclass
class CheckResult:
    healthy: bool
    detail: str


def _http_get(url: str, timeout: float) -> tuple[int | None, str]:
    req = urllib.request.Request(
        url, headers={"User-Agent": "mbfd-cloudflared-watchdog/1.0"}
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:  # noqa: S310 (trusted URL)
            return resp.status, ""
    except urllib.error.HTTPError as exc:
        return exc.code, f"http_error={exc.code}"
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        return None, f"network_error={exc.__class__.__name__}"


def check_public_path(cfg: WatchdogConfig) -> CheckResult:
    """A healthy public path returns 200 for /api/status and a reachable Socket.IO endpoint."""
    health_url = cfg.public_url.rstrip("/") + cfg.health_path
    code, detail = _http_get(health_url, cfg.request_timeout)
    if code != 200:
        return CheckResult(False, f"health {health_url} -> {code} {detail}".strip())
    socketio_url = cfg.public_url.rstrip("/") + cfg.socketio_path
    sio_code, sio_detail = _http_get(socketio_url, cfg.request_timeout)
    # Socket.IO without a valid session returns 400 (expected). 5xx / network errors are failures.
    if sio_code is None or (sio_code is not None and sio_code >= 500):
        return CheckResult(
            False, f"socket.io {socketio_url} -> {sio_code} {sio_detail}".strip()
        )
    return CheckResult(True, f"health=200 socket.io={sio_code}")


def registered_tunnel_connections(minutes: int = 5) -> int:
    """Best-effort count of recently registered tunnel connections from journald."""
    try:
        out = subprocess.run(
            [
                "journalctl",
                "-u",
                "cloudflared",
                "--since",
                f"{minutes} min ago",
                "--no-pager",
            ],
            check=False,
            capture_output=True,
            text=True,
            timeout=15,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return -1
    return out.stdout.count("Registered tunnel connection")


def load_state(state_file: Path) -> dict:
    if not state_file.exists():
        return {"consecutive_failures": 0, "last_restart": 0.0, "restarts": []}
    try:
        return json.loads(state_file.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return {"consecutive_failures": 0, "last_restart": 0.0, "restarts": []}


def save_state(state_file: Path, state: dict) -> None:
    state_file.parent.mkdir(parents=True, exist_ok=True)
    state_file.write_text(json.dumps(state), encoding="utf-8")


def restarts_in_last_hour(state: dict, now: float) -> int:
    cutoff = now - 3600.0
    return sum(1 for ts in state.get("restarts", []) if ts >= cutoff)


def restart_cloudflared(cfg: WatchdogConfig) -> bool:
    if cfg.dry_run:
        LOGGER.warning("DRY-RUN: would restart %s", CLOUDFLARED_UNIT)
        return False
    LOGGER.warning("restarting %s (tunnel connector only)", CLOUDFLARED_UNIT)
    res = subprocess.run(
        ["systemctl", "restart", CLOUDFLARED_UNIT],
        check=False,
        capture_output=True,
        text=True,
    )
    if res.returncode != 0:
        LOGGER.error("systemctl restart failed: %s", (res.stderr or "").strip())
        return False
    # Verify recovery: allow up to ~30s for the public path to come back.
    deadline = time.time() + 30
    while time.time() < deadline:
        if check_public_path(cfg).healthy:
            LOGGER.warning("recovery verified after restart")
            return True
        time.sleep(3)
    LOGGER.error("public path did NOT recover within 30s after restart")
    return False


def evaluate(cfg: WatchdogConfig) -> int:
    """Run one watchdog cycle. Returns a process exit code."""
    state_file = cfg.state_dir / "state.json"
    state = load_state(state_file)
    now = time.time()

    result = check_public_path(cfg)
    conns = registered_tunnel_connections()
    LOGGER.info(
        "check healthy=%s detail=%r registered_conns_5min=%s",
        result.healthy,
        result.detail,
        conns,
    )

    if result.healthy:
        state["consecutive_failures"] = 0
        save_state(state_file, state)
        return 0

    state["consecutive_failures"] = int(state.get("consecutive_failures", 0)) + 1
    failures = state["consecutive_failures"]
    LOGGER.warning(
        "public path unhealthy (%s); consecutive_failures=%d threshold=%d",
        result.detail,
        failures,
        cfg.failure_threshold,
    )

    if failures < cfg.failure_threshold:
        save_state(state_file, state)
        return 1  # unhealthy but below threshold; do not act yet

    # Threshold reached. Honour cooldown.
    last_restart = float(state.get("last_restart", 0.0))
    since_last = now - last_restart
    if since_last < cfg.cooldown_seconds:
        LOGGER.warning(
            "threshold reached but cooldown active (%.0fs < %ds); not restarting",
            since_last,
            cfg.cooldown_seconds,
        )
        save_state(state_file, state)
        return 1

    # Restart-storm protection.
    hourly = restarts_in_last_hour(state, now)
    if hourly >= cfg.max_restarts_per_hour:
        LOGGER.error(
            "restart-storm protection: %d restarts in the last hour (cap %d); "
            "NOT restarting. ALERT REQUIRED (Uptime Kuma / on-call).",
            hourly,
            cfg.max_restarts_per_hour,
        )
        save_state(state_file, state)
        return 2  # alert-only

    if cfg.dry_run:
        LOGGER.warning(
            "DRY-RUN: threshold reached and restart would occur now; not restarting."
        )
        save_state(state_file, state)
        return 1

    restarted = restart_cloudflared(cfg)
    if restarted:
        state["consecutive_failures"] = 0
    state["last_restart"] = now
    state.setdefault("restarts", []).append(now)
    # Trim restart history older than 2 hours.
    state["restarts"] = [ts for ts in state["restarts"] if ts >= now - 7200.0]
    save_state(state_file, state)
    return 0 if restarted else 2


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="MBFD Cloudflare tunnel watchdog")
    p.add_argument("--public-url", default=DEFAULT_PUBLIC_URL)
    p.add_argument("--health-path", default=DEFAULT_HEALTH_PATH)
    p.add_argument("--socketio-path", default=DEFAULT_SOCKETIO_PATH)
    p.add_argument("--failure-threshold", type=int, default=DEFAULT_FAILURE_THRESHOLD)
    p.add_argument("--cooldown-seconds", type=int, default=DEFAULT_COOLDOWN_SECONDS)
    p.add_argument(
        "--max-restarts-per-hour", type=int, default=DEFAULT_MAX_RESTARTS_PER_HOUR
    )
    p.add_argument("--timeout", type=float, default=DEFAULT_REQUEST_TIMEOUT)
    p.add_argument("--state-dir", default=DEFAULT_STATE_DIR)
    p.add_argument(
        "--dry-run", action="store_true", help="log actions without restarting"
    )
    p.add_argument(
        "--once-check-only", action="store_true", help="only report health, never act"
    )
    args = p.parse_args(argv)

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    cfg = WatchdogConfig(
        public_url=args.public_url,
        health_path=args.health_path,
        socketio_path=args.socketio_path,
        failure_threshold=args.failure_threshold,
        cooldown_seconds=args.cooldown_seconds,
        max_restarts_per_hour=args.max_restarts_per_hour,
        request_timeout=args.timeout,
        state_dir=Path(args.state_dir),
        dry_run=args.dry_run,
    )

    if args.once_check_only:
        result = check_public_path(cfg)
        conns = registered_tunnel_connections()
        print(
            f"healthy={result.healthy} detail={result.detail} "
            f"registered_conns_5min={conns}"
        )
        return 0 if result.healthy else 1

    return evaluate(cfg)


if __name__ == "__main__":
    sys.exit(main())
