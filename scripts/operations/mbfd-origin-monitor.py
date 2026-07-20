#!/usr/bin/env python3
"""Stateful MBFD media/origin monitor with incident deduplication."""

from __future__ import annotations

import argparse
import json
import os
import re
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
        with urllib.request.urlopen(request, timeout=timeout) as response:
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


def playlist_marker(body: bytes) -> tuple[str, str]:
    text = body.decode("utf-8", errors="replace")
    part = re.findall(r'#EXT-X-PRELOAD-HINT:TYPE=PART,URI="([^"]+)"', text)
    segment = re.findall(r"^([^#\r\n]+\.mp4)$", text, re.MULTILINE)
    marker = part[-1] if part else (segment[-1] if segment else "")
    return marker, segment[-1] if segment else ""


def playlist_probe(name: str, path: str) -> tuple[Probe, dict[str, Any]]:
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
            service="camera-hls",
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
    maintenance = (MAINTENANCE_DIR / service).exists()
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

    camera_probes: list[Probe] = []
    playlist_state: dict[str, Any] = {}
    for name, path in (
        ("cam1-video", "/hls/cam1/video1_stream.m3u8"),
        ("cam1-audio", "/hls/cam1/audio2_stream.m3u8"),
        ("cam3-video", "/hls/cam3/video1_stream.m3u8"),
        ("cam3-audio", "/hls/cam3/audio2_stream.m3u8"),
    ):
        probe, marker = playlist_probe(name, path)
        camera_probes.append(probe)
        playlist_state[name] = marker
    camera_probes.append(
        http_probe("camera-cloudflare", "camera-hls", "https://cameras.mbfdhub.com/hls/cam1/video1_stream.m3u8")
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
    ]

    for event in events:
        services[event["affected_service"]] = event
    state["playlist"] = playlist_state
    state["last_run"] = iso_now()
    atomic_json(STATE_FILE, state)

    current_states = {event["current_state"] for event in events}
    if "Failed" in current_states:
        monitor_state = "Failed"
    elif "Degraded" in current_states:
        monitor_state = "Degraded"
    elif "Recovered" in current_states:
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

    if "Failed" in current_states:
        return 2
    if current_states & {"Degraded", "Recovered", "Unknown"}:
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
    print("origin_monitor_self_test=pass")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    return self_test() if args.self_test else run()


if __name__ == "__main__":
    sys.exit(main())
