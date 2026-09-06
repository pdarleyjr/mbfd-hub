#!/usr/bin/env python3
"""MBFD Coding Controller — exclusive-session proxy for coding model.

v3 — Gateway-provider preparation:
- OpenAI-compatible inference uses the same admission and lifecycle path
- OpenAI model catalog is restricted to the approved coding model
- Catch-all routes cannot bypass the coding model allowlist
- Default production model is the accepted 32K coding alias

v2 — Corrective audit fixes:
- JSON decode error handling (400 instead of 500)
- float("inf")/NaN serialization guards
- Request body size limit (10 MiB)
- Client disconnect detection in streaming
- 1-second safety monitor during coding mode
- Background AI service suppression via systemctl
- Startup reconciliation for stale locks/residual models
- DRAINING state for unauthorized model detection
- Exclusive model enforcement during coding
- Restricted catch-all proxy paths
"""

import asyncio
import fcntl
import json
import logging
import math
import os
import subprocess
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from enum import Enum
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any

import httpx
from fastapi import FastAPI, Request, Response
from fastapi.responses import JSONResponse, StreamingResponse

OLLAMA_UPSTREAM = os.environ.get("OLLAMA_UPSTREAM", "http://127.0.0.1:11434")
LISTEN_HOST = os.environ.get("CONTROLLER_HOST", "127.0.0.1")
LISTEN_PORT = int(os.environ.get("CONTROLLER_PORT", "11436"))
LOCK_PATH = os.environ.get("LOCK_PATH", "/run/lock/mbfd-coding-controller.lock")
LOG_DIR = os.environ.get("LOG_DIR", "/var/log/mbfd-coding-controller")
INACTIVITY_TIMEOUT_S = int(os.environ.get("INACTIVITY_TIMEOUT_S", "1200"))
MONITOR_INTERVAL_S = float(os.environ.get("MONITOR_INTERVAL_S", "1"))
MONITOR_IDLE_INTERVAL_S = float(os.environ.get("MONITOR_IDLE_INTERVAL_S", "10"))
COOLDOWN_S = int(os.environ.get("COOLDOWN_S", "60"))
MIN_AVAILABLE_RAM_GIB = float(os.environ.get("MIN_AVAILABLE_RAM_GIB", "16"))
PREFERRED_AVAILABLE_RAM_GIB = float(os.environ.get("PREFERRED_AVAILABLE_RAM_GIB", "20"))
MAX_SWAP_DELTA_GIB = float(os.environ.get("MAX_SWAP_DELTA_GIB", "1"))
MAX_REQUEST_BODY_BYTES = int(os.environ.get("MAX_REQUEST_BODY_BYTES", str(10 * 1024 * 1024)))

APPROVED_MODEL = os.environ.get("APPROVED_MODEL", "mbfd-code:32k")
GENERAL_MODEL = os.environ.get("GENERAL_MODEL", "qwen3.6:35b")

SUPPRESS_UNITS = [
    u.strip()
    for u in os.environ.get(
        "SUPPRESS_UNITS",
        "mbfd-hermes-bounded-summary.timer",
    ).split(",")
    if u.strip()
]

_log_handler = RotatingFileHandler(
    Path(LOG_DIR) / "controller.log", maxBytes=10_485_760, backupCount=5
)
_log_handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
logging.basicConfig(level=logging.INFO, handlers=[_log_handler, logging.StreamHandler()])
log = logging.getLogger("mbfd-coding-controller")


class ControllerState(str, Enum):
    NORMAL = "NORMAL"
    STARTING = "STARTING"
    CODING = "CODING"
    DRAINING = "DRAINING"
    STOPPING = "STOPPING"


@dataclass
class SessionState:
    state: ControllerState = ControllerState.NORMAL
    lock_fd: int | None = None
    last_activity: float = 0.0
    swap_baseline_gib: float = 0.0
    ram_baseline_gib: float = 0.0
    ram_minimum_gib: float = float("inf")
    streaming_active: bool = False
    cooldown_until: float = 0.0
    abort_reason: str = ""
    model_loaded: bool = False
    suppressed_units: dict[str, str] = field(default_factory=dict)


state = SessionState()
http_client: httpx.AsyncClient | None = None
monitor_task: asyncio.Task | None = None
shutdown_event = asyncio.Event()
_session_lock = asyncio.Lock()


def _safe_float(v: float) -> float | None:
    if v is None or not math.isfinite(v):
        return None
    return round(v, 1)


def read_meminfo() -> dict[str, float]:
    info: dict[str, float] = {}
    try:
        with open("/proc/meminfo") as f:
            for line in f:
                parts = line.split()
                if len(parts) >= 2:
                    key = parts[0].rstrip(":")
                    info[key] = float(parts[1]) / (1024 * 1024)
    except OSError:
        pass
    return info


def get_available_ram_gib() -> float:
    mi = read_meminfo()
    return mi.get("MemAvailable", 0.0)


def get_swap_used_gib() -> float:
    mi = read_meminfo()
    return mi.get("SwapTotal", 0.0) - mi.get("SwapFree", 0.0)


def read_pressure() -> dict[str, float]:
    result: dict[str, float] = {}
    try:
        with open("/proc/pressure/memory") as f:
            for line in f:
                parts = line.split()
                if parts[0] in ("some", "full"):
                    for p in parts[1:]:
                        if p.startswith("avg10="):
                            result[f"{parts[0]}_avg10"] = float(p.split("=")[1])
    except OSError:
        pass
    return result


async def ollama_get(path: str) -> dict[str, Any]:
    assert http_client is not None
    resp = await http_client.get(f"{OLLAMA_UPSTREAM}{path}", timeout=10.0)
    resp.raise_for_status()
    return resp.json()


async def ollama_post(path: str, body: dict[str, Any], timeout: float = 30.0) -> dict[str, Any]:
    assert http_client is not None
    resp = await http_client.post(f"{OLLAMA_UPSTREAM}{path}", json=body, timeout=timeout)
    resp.raise_for_status()
    return resp.json()


async def unload_model(model: str) -> None:
    try:
        await ollama_post("/api/generate", {"model": model, "keep_alive": 0})
        log.info("model_unloaded model=%s", model)
    except Exception as e:
        log.warning("model_unload_failed model=%s error=%s", model, e)


async def wait_model_absent(model: str, timeout_s: float = 120.0) -> bool:
    deadline = time.monotonic() + timeout_s
    while time.monotonic() < deadline:
        try:
            ps = await ollama_get("/api/ps")
            names = [m["name"] for m in ps.get("models", [])]
            if model not in names:
                return True
        except Exception:
            pass
        await asyncio.sleep(2.0)
    return False


async def wait_model_present(model: str, timeout_s: float = 300.0) -> bool:
    deadline = time.monotonic() + timeout_s
    while time.monotonic() < deadline:
        try:
            ps = await ollama_get("/api/ps")
            names = [m["name"] for m in ps.get("models", [])]
            if model in names:
                return True
        except Exception:
            pass
        await asyncio.sleep(2.0)
    return False


def acquire_lock() -> bool:
    try:
        fd = os.open(LOCK_PATH, os.O_CREAT | os.O_RDWR, 0o660)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state.lock_fd = fd
        log.info("lock_acquired path=%s", LOCK_PATH)
        return True
    except (OSError, IOError) as e:
        log.warning("lock_failed error=%s", e)
        return False


def release_lock() -> None:
    if state.lock_fd is not None:
        try:
            fcntl.flock(state.lock_fd, fcntl.LOCK_UN)
            os.close(state.lock_fd)
        except OSError:
            pass
        state.lock_fd = None
        log.info("lock_released")


async def suppress_background_services() -> dict[str, str]:
    prior: dict[str, str] = {}
    for unit in SUPPRESS_UNITS:
        try:
            result = subprocess.run(
                ["sudo", "/usr/bin/systemctl", "is-active", unit],
                capture_output=True, text=True, timeout=10,
            )
            prior_state = result.stdout.strip()
            prior[unit] = prior_state

            if prior_state == "active":
                subprocess.run(
                    ["sudo", "/usr/bin/systemctl", "stop", unit],
                    capture_output=True, text=True, timeout=30,
                )
                log.info("service_suppressed unit=%s prior_state=%s", unit, prior_state)
            else:
                log.info("service_already_inactive unit=%s state=%s", unit, prior_state)
        except Exception as e:
            log.warning("service_suppress_failed unit=%s error=%s", unit, e)
            prior[unit] = "unknown"
    return prior


async def restore_background_services(prior: dict[str, str]) -> None:
    for unit, prior_state in prior.items():
        if prior_state != "active":
            continue
        try:
            subprocess.run(
                ["sudo", "/usr/bin/systemctl", "start", unit],
                capture_output=True, text=True, timeout=30,
            )
            log.info("service_restored unit=%s", unit)
        except Exception as e:
            log.warning("service_restore_failed unit=%s error=%s", unit, e)


async def enter_coding_mode() -> tuple[bool, str]:
    async with _session_lock:
        if state.state == ControllerState.CODING:
            return True, "already active"

        if state.state in (ControllerState.STARTING, ControllerState.DRAINING):
            return False, f"controller busy ({state.state.value})"

        if time.monotonic() < state.cooldown_until:
            remaining = int(state.cooldown_until - time.monotonic())
            return False, f"cooldown active, {remaining}s remaining"

        if not acquire_lock():
            return False, "could not acquire exclusive lock"

        state.state = ControllerState.STARTING
        state.swap_baseline_gib = get_swap_used_gib()
        state.ram_baseline_gib = get_available_ram_gib()
        state.ram_minimum_gib = state.ram_baseline_gib
        log.info(
            "baseline ram=%.1fGiB swap=%.1fGiB",
            state.ram_baseline_gib, state.swap_baseline_gib,
        )

        try:
            state.suppressed_units = await suppress_background_services()

            ps = await ollama_get("/api/ps")
            loaded = [m["name"] for m in ps.get("models", [])]

            for m in loaded:
                if m != APPROVED_MODEL:
                    await unload_model(m)

            for m in loaded:
                if m != APPROVED_MODEL:
                    if not await wait_model_absent(m, timeout_s=60.0):
                        log.warning("model_did_not_unload model=%s", m)

            ps_check = await ollama_get("/api/ps")
            remaining = [m["name"] for m in ps_check.get("models", [])]
            if remaining:
                log.warning("models_still_loaded after_unload: %s", remaining)
                for m in remaining:
                    await unload_model(m)
                    await wait_model_absent(m, timeout_s=30.0)

            warm = {
                "model": APPROVED_MODEL,
                "messages": [{"role": "user", "content": "hi"}],
                "stream": False,
                "options": {"num_predict": 1},
            }
            await ollama_post("/api/chat", warm, timeout=300.0)

            if not await wait_model_present(APPROVED_MODEL):
                release_lock()
                state.state = ControllerState.NORMAL
                return False, f"failed to load {APPROVED_MODEL}"

            ps2 = await ollama_get("/api/ps")
            loaded2 = [m["name"] for m in ps2.get("models", [])]
            unauthorized = [m for m in loaded2 if m != APPROVED_MODEL]
            if unauthorized:
                log.warning("unauthorized_models_after_load: %s", unauthorized)
                await unload_model(APPROVED_MODEL)
                await wait_model_absent(APPROVED_MODEL, timeout_s=30.0)
                await restore_background_services(state.suppressed_units)
                state.suppressed_units = {}
                release_lock()
                state.state = ControllerState.NORMAL
                return False, f"unauthorized models detected: {unauthorized}"

            ram_now = get_available_ram_gib()
            if ram_now < MIN_AVAILABLE_RAM_GIB:
                await unload_model(APPROVED_MODEL)
                await wait_model_absent(APPROVED_MODEL, timeout_s=30.0)
                await restore_background_services(state.suppressed_units)
                state.suppressed_units = {}
                release_lock()
                state.state = ControllerState.NORMAL
                return False, f"available RAM {ram_now:.1f}GiB below abort floor {MIN_AVAILABLE_RAM_GIB}GiB"

            swap_now = get_swap_used_gib()
            swap_delta = swap_now - state.swap_baseline_gib
            if swap_delta > MAX_SWAP_DELTA_GIB:
                await unload_model(APPROVED_MODEL)
                await wait_model_absent(APPROVED_MODEL, timeout_s=30.0)
                await restore_background_services(state.suppressed_units)
                state.suppressed_units = {}
                release_lock()
                state.state = ControllerState.NORMAL
                return False, f"swap delta {swap_delta:.1f}GiB exceeds {MAX_SWAP_DELTA_GIB}GiB"

            state.state = ControllerState.CODING
            state.model_loaded = True
            state.last_activity = time.monotonic()
            state.abort_reason = ""
            log.info("coding_mode_entered model=%s ram=%.1fGiB", APPROVED_MODEL, ram_now)
            return True, "coding mode active"

        except Exception as e:
            log.error("enter_coding_mode_error: %s: %s", type(e).__name__, e)
            try:
                await restore_background_services(state.suppressed_units)
                state.suppressed_units = {}
            except Exception:
                pass
            release_lock()
            state.state = ControllerState.NORMAL
            return False, str(e)


async def exit_coding_mode(reason: str = "normal") -> None:
    async with _session_lock:
        if state.state not in (
            ControllerState.CODING, ControllerState.STARTING, ControllerState.DRAINING,
        ):
            if not state.model_loaded:
                return

        state.state = ControllerState.STOPPING
        state.streaming_active = False

        if state.model_loaded:
            await unload_model(APPROVED_MODEL)
            await wait_model_absent(APPROVED_MODEL, timeout_s=30.0)
            state.model_loaded = False

        warm = {
            "model": GENERAL_MODEL,
            "messages": [{"role": "user", "content": "Reply: ok"}],
            "stream": False,
            "options": {"num_predict": 4},
        }
        try:
            await ollama_post("/api/chat", warm, timeout=300.0)
            log.info("general_model_warmed")
        except Exception as e:
            log.warning("general_model_warm_failed: %s", e)

        try:
            await restore_background_services(state.suppressed_units)
        except Exception as e:
            log.warning("restore_services_failed: %s", e)
        state.suppressed_units = {}

        if reason not in ("normal", "manual"):
            state.cooldown_until = time.monotonic() + COOLDOWN_S

        release_lock()
        state.state = ControllerState.NORMAL
        log.info("coding_mode_exited reason=%s", reason)


def validate_model_name(model: str) -> bool:
    if not model or not isinstance(model, str):
        return False
    cleaned = model.strip().lower()
    if cleaned != APPROVED_MODEL.lower():
        return False
    if ".." in model or "/" in model or "\\" in model:
        return False
    return True


async def safety_monitor() -> None:
    log.info("safety_monitor_started")
    while not shutdown_event.is_set():
        try:
            if state.state == ControllerState.CODING:
                interval = MONITOR_INTERVAL_S
            else:
                interval = MONITOR_IDLE_INTERVAL_S

            await asyncio.sleep(interval)

            if state.state != ControllerState.CODING:
                continue

            ram = get_available_ram_gib()
            if math.isfinite(ram):
                state.ram_minimum_gib = min(state.ram_minimum_gib, ram)

            if ram < MIN_AVAILABLE_RAM_GIB:
                log.warning("safety_abort ram=%.1fGiB floor=%.1fGiB", ram, MIN_AVAILABLE_RAM_GIB)
                state.abort_reason = f"RAM {ram:.1f}GiB below {MIN_AVAILABLE_RAM_GIB}GiB floor"
                state.state = ControllerState.DRAINING
                await exit_coding_mode("safety_ram")
                continue

            swap_now = get_swap_used_gib()
            swap_delta = swap_now - state.swap_baseline_gib
            if swap_delta > MAX_SWAP_DELTA_GIB:
                log.warning("safety_abort swap_delta=%.1fGiB max=%.1fGiB", swap_delta, MAX_SWAP_DELTA_GIB)
                state.abort_reason = f"swap delta {swap_delta:.1f}GiB exceeds {MAX_SWAP_DELTA_GIB}GiB"
                state.state = ControllerState.DRAINING
                await exit_coding_mode("safety_swap")
                continue

            pressure = read_pressure()
            if pressure.get("some_avg10", 0) > 25.0:
                log.warning("safety_abort pressure some_avg10=%.1f", pressure["some_avg10"])
                state.abort_reason = "sustained memory pressure"
                state.state = ControllerState.DRAINING
                await exit_coding_mode("safety_pressure")
                continue

            try:
                ps = await ollama_get("/api/ps")
                loaded = [m["name"] for m in ps.get("models", [])]
                unauthorized = [m for m in loaded if m != APPROVED_MODEL]
                if unauthorized:
                    log.warning("unauthorized_models_detected: %s", unauthorized)
                    state.abort_reason = f"unauthorized models: {unauthorized}"
                    state.state = ControllerState.DRAINING
                    await exit_coding_mode("unauthorized_model")
                    continue
            except Exception as e:
                log.warning("safety_monitor_ps_error: %s", e)

            if state.last_activity > 0 and not state.streaming_active:
                idle = time.monotonic() - state.last_activity
                if idle > INACTIVITY_TIMEOUT_S:
                    log.info("inactivity_timeout idle=%.0fs", idle)
                    await exit_coding_mode("inactivity")
                    continue

        except asyncio.CancelledError:
            break
        except Exception as e:
            log.error("safety_monitor_error: %s", e)

    log.info("safety_monitor_stopped")


async def startup_reconciliation() -> None:
    log.info("startup_reconciliation_begin")

    try:
        if os.path.exists(LOCK_PATH):
            try:
                fd = os.open(LOCK_PATH, os.O_RDWR, 0o660)
                try:
                    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    fcntl.flock(fd, fcntl.LOCK_UN)
                    os.close(fd)
                    log.info("stale_lock_detected clearing")
                    os.unlink(LOCK_PATH)
                except (OSError, IOError):
                    os.close(fd)
                    log.info("lock_held_by_other_process")
            except OSError:
                pass
    except Exception as e:
        log.warning("stale_lock_check_error: %s", e)

    try:
        ps = await ollama_get("/api/ps")
        loaded = [m["name"] for m in ps.get("models", [])]
        if APPROVED_MODEL in loaded:
            log.warning("residual_coding_model_detected unloading")
            await unload_model(APPROVED_MODEL)
            await wait_model_absent(APPROVED_MODEL, timeout_s=30.0)
    except Exception as e:
        log.warning("startup_ps_check_error: %s", e)

    try:
        ps = await ollama_get("/api/ps")
        loaded = [m["name"] for m in ps.get("models", [])]
        if GENERAL_MODEL not in loaded:
            warm = {
                "model": GENERAL_MODEL,
                "messages": [{"role": "user", "content": "Reply: ok"}],
                "stream": False,
                "options": {"num_predict": 4},
            }
            try:
                await ollama_post("/api/chat", warm, timeout=300.0)
                log.info("general_model_warmed_on_startup")
            except Exception as e:
                log.warning("general_model_startup_warm_failed: %s", e)
    except Exception as e:
        log.warning("startup_general_model_check_error: %s", e)

    log.info("startup_reconciliation_complete")


@asynccontextmanager
async def lifespan(app: FastAPI):
    global http_client, monitor_task
    http_client = httpx.AsyncClient(timeout=httpx.Timeout(600.0, connect=10.0))
    monitor_task = asyncio.create_task(safety_monitor())
    log.info("controller_started host=%s port=%d upstream=%s", LISTEN_HOST, LISTEN_PORT, OLLAMA_UPSTREAM)
    asyncio.create_task(startup_reconciliation())
    yield
    shutdown_event.set()
    if state.state in (ControllerState.CODING, ControllerState.STARTING, ControllerState.DRAINING):
        await exit_coding_mode("shutdown")
    if monitor_task:
        monitor_task.cancel()
        try:
            await monitor_task
        except asyncio.CancelledError:
            pass
    if http_client:
        await http_client.aclose()
    log.info("controller_stopped")


app = FastAPI(title="MBFD Coding Controller", lifespan=lifespan)


async def _read_body_safe(request: Request) -> bytes:
    body = await request.body()
    if len(body) > MAX_REQUEST_BODY_BYTES:
        raise ValueError(f"request body exceeds {MAX_REQUEST_BODY_BYTES} bytes")
    return body


async def _parse_json_safe(request: Request) -> dict[str, Any]:
    body = await _read_body_safe(request)
    try:
        data = json.loads(body)
    except (json.JSONDecodeError, UnicodeDecodeError) as e:
        raise ValueError(f"invalid JSON: {e}") from e
    if not isinstance(data, dict):
        raise ValueError("request body must be a JSON object")
    return data


@app.get("/health")
async def health():
    ram = get_available_ram_gib()
    return {
        "status": "ok",
        "controller_state": state.state.value,
        "coding_active": state.state == ControllerState.CODING,
        "model": APPROVED_MODEL,
        "available_ram_gib": _safe_float(ram),
    }


@app.get("/controller/status")
async def controller_status():
    idle = None
    if state.last_activity and math.isfinite(state.last_activity):
        idle_val = time.monotonic() - state.last_activity
        if math.isfinite(idle_val):
            idle = round(idle_val, 0)

    cooldown_remaining = 0
    if math.isfinite(state.cooldown_until):
        cr = state.cooldown_until - time.monotonic()
        if math.isfinite(cr):
            cooldown_remaining = max(0, int(cr))

    return {
        "state": state.state.value,
        "model": APPROVED_MODEL,
        "model_loaded": state.model_loaded,
        "last_activity": _safe_float(state.last_activity),
        "idle_seconds": idle,
        "ram_baseline_gib": _safe_float(state.ram_baseline_gib),
        "ram_minimum_gib": _safe_float(state.ram_minimum_gib),
        "ram_current_gib": _safe_float(get_available_ram_gib()),
        "swap_baseline_gib": _safe_float(state.swap_baseline_gib),
        "swap_current_gib": _safe_float(get_swap_used_gib()),
        "abort_reason": state.abort_reason,
        "cooldown_remaining_s": cooldown_remaining,
        "suppressed_units": state.suppressed_units,
    }


@app.post("/controller/end-session")
async def end_session():
    if state.state == ControllerState.CODING:
        state.state = ControllerState.DRAINING
        await exit_coding_mode("manual")
        return {"status": "ended"}
    return {"status": "not active", "controller_state": state.state.value}


@app.get("/api/tags")
async def api_tags():
    return {
        "models": [
            {
                "name": APPROVED_MODEL,
                "model": APPROVED_MODEL,
                "size": 0,
                "digest": "",
                "modified_at": "",
            }
        ]
    }


@app.post("/api/show")
async def api_show(request: Request):
    try:
        body = await _parse_json_safe(request)
    except ValueError as e:
        return JSONResponse({"error": str(e)}, status_code=400)

    model = body.get("model", "")
    if not validate_model_name(model):
        return JSONResponse({"error": f"model '{model}' is not available"}, status_code=400)
    body["model"] = APPROVED_MODEL
    assert http_client is not None
    resp = await http_client.post(f"{OLLAMA_UPSTREAM}/api/show", json=body, timeout=30.0)
    return Response(content=resp.content, status_code=resp.status_code, media_type=resp.headers.get("content-type", "application/json"))


@app.get("/api/ps")
async def api_ps():
    assert http_client is not None
    resp = await http_client.get(f"{OLLAMA_UPSTREAM}/api/ps", timeout=10.0)
    resp.raise_for_status()
    data = resp.json()
    if state.state == ControllerState.CODING:
        data["models"] = [m for m in data.get("models", []) if m.get("name") == APPROVED_MODEL]
    elif state.state == ControllerState.NORMAL:
        # The gateway performs an Ollama /api/ps cold-start check before it
        # forwards inference. NORMAL means this lifecycle provider is ready to
        # perform admission and load the approved model, not that the physical
        # model is already resident. Present a virtual readiness entry so the
        # gateway can reach enter_coding_mode(); the controller's real resource
        # and exclusivity checks remain authoritative.
        data["models"] = [
            {
                "name": APPROVED_MODEL,
                "model": APPROVED_MODEL,
                "size": 0,
                "digest": "",
                "details": {},
                "expires_at": "",
                "size_vram": 0,
                "provider_state": "admission_ready",
            }
        ]
    else:
        # STARTING/DRAINING/STOPPING are deliberately not admission-ready.
        data["models"] = []
    return data


async def _proxy_inference(request: Request, endpoint: str):
    try:
        body = await _parse_json_safe(request)
    except ValueError as e:
        return JSONResponse({"error": str(e)}, status_code=400)

    model = body.get("model", "")
    if not validate_model_name(model):
        return JSONResponse({"error": f"model '{model}' is not available through this endpoint"}, status_code=400)

    body["model"] = APPROVED_MODEL

    if state.state != ControllerState.CODING:
        if state.state in (ControllerState.STARTING, ControllerState.DRAINING, ControllerState.STOPPING):
            return JSONResponse({"error": "controller busy, try again later"}, status_code=503)
        ok, msg = await enter_coding_mode()
        if not ok:
            log.warning("coding_mode_enter_failed: %s", msg)
            return JSONResponse({"error": f"coding mode unavailable: {msg}"}, status_code=503)

    state.last_activity = time.monotonic()
    state.streaming_active = True

    is_stream = body.get("stream", True)

    assert http_client is not None
    try:
        if is_stream:
            async def generate():
                try:
                    async with http_client.stream(
                        "POST",
                        f"{OLLAMA_UPSTREAM}{endpoint}",
                        json=body,
                        timeout=httpx.Timeout(600.0, connect=10.0),
                    ) as upstream:
                        if upstream.status_code != 200:
                            error_body = await upstream.aread()
                            try:
                                error_data = json.loads(error_body)
                            except (json.JSONDecodeError, UnicodeDecodeError):
                                error_data = {"error": error_body.decode("utf-8", errors="replace")}
                            yield json.dumps(error_data) + "\n"
                            return
                        async for chunk in upstream.aiter_lines():
                            if chunk:
                                yield chunk + "\n"
                except asyncio.CancelledError:
                    log.info("client_disconnected endpoint=%s", endpoint)
                except httpx.StreamClosed:
                    pass
                except Exception as e:
                    log.warning("stream_error endpoint=%s error=%s", endpoint, e)
                finally:
                    state.streaming_active = False
                    state.last_activity = time.monotonic()

            return StreamingResponse(
                generate(),
                media_type=(
                    "text/event-stream"
                    if endpoint.startswith("/v1/")
                    else "application/x-ndjson"
                ),
            )
        else:
            resp = await http_client.post(
                f"{OLLAMA_UPSTREAM}{endpoint}",
                json=body,
                timeout=httpx.Timeout(600.0, connect=10.0),
            )
            state.streaming_active = False
            state.last_activity = time.monotonic()
            return Response(
                content=resp.content,
                status_code=resp.status_code,
                media_type=resp.headers.get("content-type", "application/json"),
            )
    except httpx.TimeoutException:
        state.streaming_active = False
        state.last_activity = time.monotonic()
        return JSONResponse({"error": "upstream timeout"}, status_code=504)
    except httpx.ConnectError:
        state.streaming_active = False
        state.last_activity = time.monotonic()
        return JSONResponse({"error": "upstream connection failed"}, status_code=502)
    except Exception as e:
        state.streaming_active = False
        state.last_activity = time.monotonic()
        log.error("proxy_error endpoint=%s error=%s", endpoint, e)
        return JSONResponse({"error": "upstream error"}, status_code=502)


@app.post("/api/chat")
async def api_chat(request: Request):
    return await _proxy_inference(request, "/api/chat")


@app.post("/api/generate")
async def api_generate(request: Request):
    return await _proxy_inference(request, "/api/generate")


@app.get("/v1/models")
async def v1_models():
    return {
        "object": "list",
        "data": [
            {
                "id": APPROVED_MODEL,
                "object": "model",
                "owned_by": "mbfd-coding-controller",
            }
        ],
    }


@app.post("/v1/chat/completions")
async def v1_chat_completions(request: Request):
    return await _proxy_inference(request, "/v1/chat/completions")


@app.post("/v1/completions")
async def v1_completions(request: Request):
    return await _proxy_inference(request, "/v1/completions")


ALLOWED_PROXY_PATHS = frozenset({
    "api/version",
})


@app.api_route("/{path:path}", methods=["GET", "POST", "PUT", "DELETE", "HEAD", "OPTIONS"])
async def catch_all(request: Request, path: str):
    if path not in ALLOWED_PROXY_PATHS:
        return JSONResponse({"error": f"path '/{path}' not permitted"}, status_code=403)

    assert http_client is not None
    url = f"{OLLAMA_UPSTREAM}/{path}"
    headers = {k: v for k, v in request.headers.items() if k.lower() not in ("host", "content-length", "transfer-encoding")}

    try:
        body = await _read_body_safe(request)
    except ValueError as e:
        return JSONResponse({"error": str(e)}, status_code=413)

    resp = await http_client.request(
        method=request.method,
        url=url,
        headers=headers,
        content=body,
        timeout=httpx.Timeout(60.0, connect=10.0),
    )
    return Response(content=resp.content, status_code=resp.status_code, media_type=resp.headers.get("content-type"))


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=LISTEN_HOST, port=LISTEN_PORT, log_level="info", workers=1)
