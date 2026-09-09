#!/usr/bin/env python3
"""Stateful MBFD media/origin monitor with incident deduplication."""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import re
import stat
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from dataclasses import dataclass, asdict
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any


STATE_DIR = Path(os.getenv("MBFD_ORIGIN_MONITOR_STATE_DIR", "/var/lib/mbfd-origin-monitor"))
LOG_DIR = Path(os.getenv("MBFD_ORIGIN_MONITOR_LOG_DIR", "/var/log/mbfd-origin-monitor"))
STATE_FILE = STATE_DIR / "state.json"
STATUS_FILE = STATE_DIR / "status.json"
EVENT_FILE = LOG_DIR / "events.jsonl"
MAINTENANCE_DIR = Path("/run/mbfd-maintenance")
COOLDOWN_SECONDS = 900
MAX_MAINTENANCE_SECONDS = 900


class LoopbackCookiePolicy(http.cookiejar.DefaultCookiePolicy):
    """Permit the proxy's Secure HLS cookie only on the trusted loopback port."""

    def return_ok_secure(self, cookie: http.cookiejar.Cookie, request: Any) -> bool:
        if cookie.secure and request.type not in self.secure_protocols:
            return request.host in {"127.0.0.1:8120", "localhost:8120"}
        return True


HTTP_OPENER = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(
        http.cookiejar.CookieJar(policy=LoopbackCookiePolicy())
    )
)

BENIGN_PATTERNS = (
    "context canceled",
    "request ended abruptly",
    "canceled by remote",
    "client disconnected",
)
ACTIONABLE_PATTERNS = (
    "unable to reach origin service",
    "connection refused",
    "connection reset by peer",
    "tls handshake",
    "no such host",
    "temporary failure in name resolution",
    "authentication failed",
    " 502 ",
    " 503 ",
    " 504 ",
)


def iso_now() -> str:
    return datetime.now().astimezone().isoformat(timespec="seconds")


def load_json(path: Path, default: dict[str, Any]) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text())
        return value if isinstance(value, dict) else default
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        return default


def maintenance_metadata_valid(metadata: os.stat_result, now_epoch: int) -> bool:
    age = now_epoch - int(metadata.st_mtime)
    return (
        stat.S_ISREG(metadata.st_mode)
        and metadata.st_uid == 0
        and not metadata.st_mode & (stat.S_IWGRP | stat.S_IWOTH)
        and 0 <= age <= MAX_MAINTENANCE_SECONDS
    )


def maintenance_active(service: str, now_epoch: int | None = None) -> bool:
    if service not in {"media-control", "camera-hls"}:
        return False
    try:
        metadata = (MAINTENANCE_DIR / service).lstat()
    except OSError:
        return False
    return maintenance_metadata_valid(
        metadata,
        int(time.time()) if now_epoch is None else now_epoch,
    )


def atomic_json(path: Path, value: dict[str, Any]) -> None:
    temporary = path.with_name(f".{path.name}.tmp")
    temporary.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")
    os.replace(temporary, path)


@dataclass
class Probe:
    name: str
    service: str
    ok: bool
    status: str
    latency_ms: int
    source: str
    evidence: str


def fetch(url: str, timeout: float = 6.0, byte_range: bool = False) -> tuple[int, bytes, int, str]:
    headers = {"User-Agent": "MBFD-Origin-Monitor/2.0"}
    if byte_range:
        headers["Range"] = "bytes=0-4095"
    request = urllib.request.Request(url, headers=headers)
    started = time.monotonic()
    try:
        with HTTP_OPENER.open(request, timeout=timeout) as response:
            body = response.read(1_000_000)
            return response.status, body, round((time.monotonic() - started) * 1000), ""
    except urllib.error.HTTPError as error:
        return error.code, b"", round((time.monotonic() - started) * 1000), f"HTTP {error.code}"
    except Exception as error:  # Network exception class is safe; raw URLs/errors are not logged.
        return 0, b"", round((time.monotonic() - started) * 1000), type(error).__name__


def http_probe(name: str, service: str, url: str) -> Probe:
    code, body, latency, error = fetch(url)
    ok = code == 200 and len(body) > 0
    return Probe(
        name=name,
        service=service,
        ok=ok,
        status="Healthy" if ok else "Failed",
        latency_ms=latency,
        source="synthetic_http",
        evidence=f"status={code} bytes={len(body)} error={error or 'none'}",
    )


def camera_program_probe(url: str) -> Probe:
    code, body, latency, error = fetch(url)
    failures: list[str] = []
    value: dict[str, Any] = {}
    if code == 200 and body:
        try:
            parsed = json.loads(body)
            if isinstance(parsed, dict):
                value = parsed
            else:
                failures.append("invalid_payload")
        except (json.JSONDecodeError, UnicodeDecodeError):
            failures.append("invalid_json")
    else:
        failures.append("status")

    sources = value.get("sources", {})
    anpviz = sources.get("anpviz", {}) if isinstance(sources, dict) else {}
    if not isinstance(anpviz, dict):
        anpviz = {}
    required = {
        "camera": value.get("camera_online") is True,
        "preview": value.get("preview_online") is True,
        "camera_audio": value.get("camera_audio_online") is True,
        "program_video": anpviz.get("video_online") is True,
        "tonor_microphone": anpviz.get("microphone_connected") is True,
        "program_audio": anpviz.get("audio_online") is True,
        "sync": anpviz.get("synchronization_status") == "locked",
        "audio_probe": anpviz.get("audio_level_probe_healthy") is True,
    }
    failures.extend(name for name, present in required.items() if not present)

    now = datetime.now().astimezone()
    for field in ("last_update", "last_audio_frame_at"):
        raw = anpviz.get(field)
        try:
            observed = datetime.fromisoformat(str(raw).replace("Z", "+00:00"))
            if abs((now - observed).total_seconds()) > 30:
                failures.append(field + "_stale")
        except (TypeError, ValueError):
            failures.append(field + "_missing")

    ok = not failures
    return Probe(
        name="camera-program",
        service="camera-hls",
        ok=ok,
        status="Healthy" if ok else "Failed",
        latency_ms=latency,
        source="camera_api",
        evidence=(
            f"status={code} camera={str(required['camera']).lower()} "
            f"video={str(required['program_video']).lower()} "
            f"tonor={str(required['tonor_microphone']).lower()} "
            f"audio={str(required['program_audio']).lower()} "
            f"sync={str(required['sync']).lower()} "
            f"failures={','.join(failures) or 'none'} error={error or 'none'}"
        ),
    )


def playlist_marker(body: bytes) -> tuple[str, str]:
    text = body.decode("utf-8", errors="replace")
    part = re.findall(r'#EXT-X-PRELOAD-HINT:TYPE=PART,URI="([^"]+)"', text)
    segment = re.findall(r"^([^#\r\n]+\.mp4)$", text, re.MULTILINE)
    marker = part[-1] if part else (segment[-1] if segment else "")
    return marker, segment[-1] if segment else ""


def playlist_probe(name: str, service: str, path: str) -> tuple[Probe, dict[str, Any]]:
    base = "http://127.0.0.1:8120"
    code1, body1, latency1, error1 = fetch(base + path)
    marker1, segment1 = playlist_marker(body1)
    time.sleep(1.2)
    code2, body2, latency2, error2 = fetch(base + path)
    marker2, segment2 = playlist_marker(body2)
    advancing = bool(marker1 and marker2 and marker1 != marker2)
    segment_ok = False
    segment_status = 0
    segment_bytes = 0
    if segment2:
        segment_url = base + path.rsplit("/", 1)[0] + "/" + segment2
        segment_status, segment_body, _, _ = fetch(segment_url, byte_range=True)
        segment_bytes = len(segment_body)
        segment_ok = segment_status in (200, 206) and segment_bytes > 0
    ok = code1 == 200 and code2 == 200 and advancing and segment_ok
    evidence = (
        f"playlist_status={code2} advancing={str(advancing).lower()} "
        f"segment_status={segment_status} segment_bytes={segment_bytes} "
        f"errors={error1 or 'none'},{error2 or 'none'}"
    )
    return (
        Probe(
            name=name,
            service=service,
            ok=ok,
            status="Healthy" if ok else "Failed",
            latency_ms=max(latency1, latency2),
            source="synthetic_hls",
            evidence=evidence,
        ),
        {"marker": marker2, "segment": segment2},
    )


def cloudflared_signals(minutes: int = 5) -> dict[str, Any]:
    command = [
        "journalctl",
        "-u",
        "cloudflared.service",
        "--since",
        f"-{minutes} minutes",
        "--no-pager",
        "-o",
        "cat",
    ]
    try:
        result = subprocess.run(command, check=False, capture_output=True, text=True, timeout=10)
    except (OSError, subprocess.TimeoutExpired) as error:
        return {"complete": False, "error": type(error).__name__, "services": {}}
    if result.returncode != 0:
        return {"complete": False, "error": "journalctl_failed", "services": {}}
    lines = result.stdout.lower().splitlines()
    service_signals = {
        "media-control": {"benign": 0, "actionable": 0},
        "camera-hls": {"benign": 0, "actionable": 0},
    }
    for line in lines:
        if "localhost:8096" in line or "media.mbfdhub.com" in line or "media-control.mbfdhub.com" in line:
            service = "media-control"
        elif "localhost:8120" in line or "cameras.mbfdhub.com" in line:
            service = "camera-hls"
        else:
            continue
        if any(pattern in line for pattern in BENIGN_PATTERNS):
            service_signals[service]["benign"] += 1
        if any(pattern in line for pattern in ACTIONABLE_PATTERNS):
            service_signals[service]["actionable"] += 1
    return {"complete": True, "error": None, "services": service_signals}


def service_state(
    service: str,
    probes: list[Probe],
    signals: dict[str, Any],
    previous: dict[str, Any],
) -> dict[str, Any]:
    failed = [probe for probe in probes if not probe.ok]
    maintenance = maintenance_active(service)
    previous_failures = int(previous.get("consecutive_failures", 0))
    consecutive_failures = previous_failures + 1 if failed else 0
    actionable = int(signals.get("actionable", 0))

    if maintenance:
        current = "Maintenance"
        severity = "informational"
        impact = "Planned maintenance window; synthetic state retained"
    elif failed and actionable:
        current = "Failed"
        severity = "high"
        impact = "Cloudflare origin and synthetic checks both failed"
    elif failed and consecutive_failures >= 2:
        current = "Failed"
        severity = "high"
        impact = "Sustained synthetic failure may affect users"
    elif failed:
        current = "Degraded"
        severity = "warning"
        impact = "First synthetic failure; confirmation pending"
    elif actionable:
        current = "Recovered"
        severity = "warning"
        impact = "Historical origin transport errors observed; service is currently reachable"
    else:
        current = "Healthy"
        severity = "informational"
        impact = "No current user impact detected"

    prior_state = previous.get("current_state", "Unknown")
    recovery = current in ("Healthy", "Recovered") and prior_state in ("Degraded", "Failed")
    incident_id = previous.get("correlation_id")
    if current in ("Degraded", "Failed") and prior_state not in ("Degraded", "Failed"):
        incident_id = str(uuid.uuid4())
    if not incident_id:
        incident_id = str(uuid.uuid4())

    now = iso_now()
    last_notification = previous.get("last_notification_time")
    cooldown_active = False
    if last_notification and not recovery:
        try:
            cooldown_active = datetime.now().astimezone() - datetime.fromisoformat(last_notification) < timedelta(seconds=COOLDOWN_SECONDS)
        except ValueError:
            pass
    notify = recovery or (severity in ("warning", "high") and (not cooldown_active or current != prior_state))

    evidence = [f"{probe.name}: {probe.evidence}" for probe in probes]
    evidence.append(
        f"cloudflared_window=5m benign_cancellations={signals.get('benign', 0)} "
        f"actionable_errors={actionable}"
    )
    completeness = "complete" if signals.get("complete", True) else "partial"
    confidence = "high" if completeness == "complete" and probes else "medium"

    return {
        "event_occurrence_time": now,
        "collection_time": now,
        "report_generation_time": now,
        "time_zone": datetime.now().astimezone().tzname(),
        "current_state": current,
        "historical_state": prior_state,
        "recovery_time": now if recovery else previous.get("recovery_time"),
        "data_source": sorted({probe.source for probe in probes} | {"cloudflared_journal"}),
        "data_completeness": completeness,
        "confidence": confidence,
        "severity": severity,
        "affected_service": service,
        "user_impact": impact,
        "correlation_id": incident_id,
        "deduplication_key": f"origin:{service}",
        "suppression_key": f"origin:{service}:{current.lower()}",
        "notification_disposition": "send" if notify else "deduplicated",
        "recovery_notification": recovery,
        "consecutive_failures": consecutive_failures,
        "benign_hls_cancellations": int(signals.get("benign", 0)),
        "actionable_origin_errors": actionable,
        "evidence": evidence,
        "runbook": "/opt/mbfd/runbooks/mbfd-origin-monitor-status.sh",
        "last_notification_time": now if notify else last_notification,
    }


def optional_publisher_state(
    service: str,
    probes: list[Probe],
    previous: dict[str, Any],
) -> dict[str, Any]:
    passed = [probe for probe in probes if probe.ok]
    if len(passed) == len(probes):
        current = "Available"
        severity = "informational"
        impact = "Optional publisher is available"
    elif not passed:
        current = "Inactive"
        severity = "informational"
        impact = "Optional publisher is not active; canonical camera service is unaffected"
    else:
        current = "Degraded"
        severity = "warning"
        impact = "Optional publisher is partially available or unhealthy"

    prior_state = previous.get("current_state", "Unknown")
    recovery = current in ("Available", "Inactive") and prior_state == "Degraded"
    incident_id = previous.get("correlation_id")
    if current == "Degraded" and prior_state != "Degraded":
        incident_id = str(uuid.uuid4())
    if not incident_id:
        incident_id = str(uuid.uuid4())

    now = iso_now()
    last_notification = previous.get("last_notification_time")
    notify = recovery or (current == "Degraded" and current != prior_state)
    return {
        "event_occurrence_time": now,
        "collection_time": now,
        "report_generation_time": now,
        "time_zone": datetime.now().astimezone().tzname(),
        "current_state": current,
        "historical_state": prior_state,
        "recovery_time": now if recovery else previous.get("recovery_time"),
        "data_source": sorted({probe.source for probe in probes}),
        "data_completeness": "complete",
        "confidence": "high",
        "severity": severity,
        "affected_service": service,
        "user_impact": impact,
        "correlation_id": incident_id,
        "deduplication_key": f"origin:{service}",
        "suppression_key": f"origin:{service}:{current.lower()}",
        "notification_disposition": "send" if notify else "deduplicated",
        "recovery_notification": recovery,
        "consecutive_failures": 1 if current == "Degraded" else 0,
        "benign_hls_cancellations": 0,
        "actionable_origin_errors": 0,
        "evidence": [f"{probe.name}: {probe.evidence}" for probe in probes],
        "runbook": "/opt/mbfd/runbooks/mbfd-origin-monitor-status.sh",
        "last_notification_time": now if notify else last_notification,
    }


def run() -> int:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    state = load_json(STATE_FILE, {"services": {}, "playlist": {}})
    signals = cloudflared_signals()
    signal_services = signals.get("services", {})

    media_probes = [
        http_probe("media-internal", "media-control", "http://127.0.0.1:8096/api/version"),
        http_probe("media-cloudflare", "media-control", "https://media.mbfdhub.com/api/version"),
    ]

    # Only anpviz-main is the required camera program. The guest publisher is
    # optional and receives its own Available/Inactive/Degraded state below.
    camera_probes: list[Probe] = [
        http_probe(
            "anpviz-master",
            "camera-hls",
            "http://127.0.0.1:8120/hls/anpviz-main/index.m3u8",
        ),
        camera_program_probe("http://127.0.0.1:8120/api/status"),
    ]
    guest_probes: list[Probe] = [
        http_probe(
            "guest-master",
            "guest-computer",
            "http://127.0.0.1:8120/hls/guest-computer/index.m3u8",
        ),
    ]
    playlist_state: dict[str, Any] = {}
    for name, path in (
        ("anpviz-video", "/hls/anpviz-main/video1_stream.m3u8"),
        ("anpviz-audio", "/hls/anpviz-main/audio2_stream.m3u8"),
    ):
        probe, marker = playlist_probe(name, "camera-hls", path)
        camera_probes.append(probe)
        playlist_state[name] = marker
    for name, path in (
        ("guest-video", "/hls/guest-computer/video1_stream.m3u8"),
        ("guest-audio", "/hls/guest-computer/audio2_stream.m3u8"),
    ):
        probe, marker = playlist_probe(name, "guest-computer", path)
        guest_probes.append(probe)
        playlist_state[name] = marker
    camera_probes.append(
        http_probe(
            "camera-cloudflare",
            "camera-hls",
            "https://cameras.mbfdhub.com/hls/anpviz-main/index.m3u8",
        )
    )

    services = state.setdefault("services", {})
    events = [
        service_state(
            "media-control",
            media_probes,
            {"complete": signals.get("complete", False), **signal_services.get("media-control", {})},
            services.get("media-control", {}),
        ),
        service_state(
            "camera-hls",
            camera_probes,
            {"complete": signals.get("complete", False), **signal_services.get("camera-hls", {})},
            services.get("camera-hls", {}),
        ),
        optional_publisher_state(
            "guest-computer",
            guest_probes,
            services.get("guest-computer", {}),
        ),
    ]

    for event in events:
        services[event["affected_service"]] = event
    state["playlist"] = playlist_state
    state["last_run"] = iso_now()
    atomic_json(STATE_FILE, state)

    required_states = {
        event["current_state"]
        for event in events
        if event["affected_service"] in ("media-control", "camera-hls")
    }
    guest_state = next(
        event["current_state"]
        for event in events
        if event["affected_service"] == "guest-computer"
    )
    if "Failed" in required_states:
        monitor_state = "Failed"
    elif "Degraded" in required_states or guest_state == "Degraded":
        monitor_state = "Degraded"
    elif "Recovered" in required_states:
        monitor_state = "Recovered"
    else:
        monitor_state = "Healthy"
    status = {
        "monitor_state": monitor_state,
        "last_collection_time": state["last_run"],
        "data_completeness": "complete" if signals.get("complete") else "partial",
        "services": {event["affected_service"]: event["current_state"] for event in events},
    }
    atomic_json(STATUS_FILE, status)
    with EVENT_FILE.open("a", encoding="utf-8") as stream:
        for event in events:
            serialized = json.dumps(event, sort_keys=True)
            stream.write(serialized + "\n")
            print(serialized)

    if "Failed" in required_states:
        return 2
    if required_states & {"Degraded", "Recovered", "Unknown"} or guest_state == "Degraded":
        return 1
    return 0


def self_test() -> int:
    assert any(pattern in "incoming request ended abruptly: context canceled" for pattern in BENIGN_PATTERNS)
    assert not any(pattern in "incoming request ended abruptly: context canceled" for pattern in ACTIONABLE_PATTERNS)
    assert any(pattern in "unable to reach origin service: connection refused" for pattern in ACTIONABLE_PATTERNS)
    marker, segment = playlist_marker(
        b'#EXT-X-PART:DURATION=0.2,URI="part1.mp4"\nsegment1.mp4\n'
        b'#EXT-X-PRELOAD-HINT:TYPE=PART,URI="part2.mp4"\n'
    )
    assert marker == "part2.mp4" and segment == "segment1.mp4"
    inactive = optional_publisher_state(
        "guest-computer",
        [Probe("guest-master", "guest-computer", False, "Failed", 1, "test", "absent")],
        {},
    )
    assert inactive["current_state"] == "Inactive"
    assert inactive["severity"] == "informational"
    degraded = optional_publisher_state(
        "guest-computer",
        [
            Probe("guest-master", "guest-computer", True, "Healthy", 1, "test", "ready"),
            Probe("guest-audio", "guest-computer", False, "Failed", 1, "test", "missing"),
        ],
        {},
    )
    assert degraded["current_state"] == "Degraded"
    print("origin_monitor_self_test=pass")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    return self_test() if args.self_test else run()


if __name__ == "__main__":
    sys.exit(main())
