#!/usr/bin/env python3
"""Deterministic EOC watchdog with stateful severity and Telegram delivery.

This program never invokes an LLM. It polls only loopback EOC endpoints,
persists sanitized evidence, and sends transition/cooldown notifications by
using Hermes only as a transport client.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

DEFAULT_HEALTH_URL = "http://127.0.0.1:8220/api/v1/sources/health"
DEFAULT_ROBOTS_URL = "http://127.0.0.1:4177/api/v1/robots"
DEFAULT_STATE_DIR = "/var/lib/mbfd-watchdog"
FAILURE_THRESHOLD = 3
COOLDOWN_SECONDS = 30 * 60
ZERO_RECORD_PHRASES = (
    "no current records",
    "no data",
    "no records found",
    "no results",
    "no records",
)
HERMES_ENV = {
    "HOME": "/var/lib/mbfd-aiops",
    "HERMES_HOME": "/opt/mbfd/hermes/home",
    "PATH": (
        "/var/lib/mbfd-aiops/.local/bin:/opt/mbfd/hermes/home/node/bin:"
        "/opt/mbfd/hermes/home/bin:/opt/mbfd/hermes/hermes-agent/venv/bin:"
        "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
    ),
}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def atomic_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        # Group read is intentional for the mbfd-aiops operational evidence path.
        os.chmod(temporary, 0o640)  # codeql[py/overly-permissive-file]
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def load_json(path: Path, default: Any) -> Any:
    try:
        with path.open(encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError, TypeError):
        return default


def fetch_json(url: str, timeout_seconds: int = 10) -> tuple[Any | None, str | None]:
    request = urllib.request.Request(
        url, headers={"Accept": "application/json", "User-Agent": "MBFD-Watchdog/1.0"}
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout_seconds) as response:
            if response.status != 200:
                return None, f"http_{response.status}"
            body = response.read(1_048_577)
            if len(body) > 1_048_576:
                return None, "response_too_large"
            return json.loads(body.decode("utf-8")), None
    except urllib.error.HTTPError as exc:
        return None, f"http_{exc.code}"
    except urllib.error.URLError as exc:
        return None, f"transport_{type(exc.reason).__name__}"
    except TimeoutError:
        return None, "timeout"
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None, "invalid_json"
    except OSError as exc:
        return None, f"transport_{type(exc).__name__}"


def extract_sources(payload: Any) -> tuple[list[dict[str, Any]], str | None]:
    if isinstance(payload, list):
        sources = payload
    elif isinstance(payload, dict) and isinstance(payload.get("sources"), list):
        sources = payload["sources"]
    else:
        return [], "unexpected_schema"
    if not all(isinstance(item, dict) for item in sources):
        return [], "unexpected_schema"
    return sources, None


def classify_source(source: dict[str, Any]) -> str:
    state = str(source.get("state") or "").lower()
    message = str(source.get("message") or "").lower()
    if state in {"invalid", "schema_error"}:
        return "schema_break"
    if state == "healthy" and any(phrase in message for phrase in ZERO_RECORD_PHRASES):
        return "zero_record"
    if state == "healthy":
        return "healthy"
    return "degraded"


def sanitize_source(source: dict[str, Any], classification: str) -> dict[str, Any]:
    source_id = str(source.get("source_id") or "unknown")[:120]
    message = " ".join(str(source.get("message") or "").split())[:200]
    return {
        "source_id": source_id,
        "classification": classification,
        "reported_state": str(source.get("state") or "unknown")[:80],
        "message": message,
    }


def incident_severity(classification: str, failures: int) -> str:
    if classification == "schema_break":
        return "P1"
    if classification == "degraded" and failures >= FAILURE_THRESHOLD:
        return "P1"
    if classification in {"degraded", "zero_record"}:
        return "P2"
    return "P3"


def evaluate(
    sources: list[dict[str, Any]], previous: dict[str, Any], now_epoch: float
) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    old_sources = previous.get("sources", {}) if isinstance(previous, dict) else {}
    current: dict[str, Any] = {}
    notifications: list[dict[str, Any]] = []

    for raw in sources:
        source_id = str(raw.get("source_id") or "unknown")[:120]
        classification = classify_source(raw)
        old = old_sources.get(source_id, {}) if isinstance(old_sources, dict) else {}
        failures = 0 if classification in {"healthy", "zero_record"} else int(old.get("failures", 0)) + 1
        severity = incident_severity(classification, failures)
        signature = f"{source_id}:source_health:{classification}"
        old_severity = str(old.get("severity", "P3"))
        old_classification = str(old.get("classification", "unknown"))
        last_notified = float(old.get("last_notified_epoch", 0) or 0)
        notify_reason = None
        if severity == "P1":
            if old_severity != "P1" or old_classification != classification:
                notify_reason = "new_or_worsened"
            elif now_epoch - last_notified >= COOLDOWN_SECONDS:
                notify_reason = "cooldown_repeat"
        elif classification in {"healthy", "zero_record"} and old_severity == "P1":
            notify_reason = "recovered"

        if notify_reason:
            notifications.append(
                {
                    "source_id": source_id,
                    "severity": "RECOVERY" if notify_reason == "recovered" else severity,
                    "classification": classification,
                    "reason": notify_reason,
                    "incident_id": signature,
                    "failures": failures,
                }
            )
            last_notified = now_epoch

        sanitized = sanitize_source(raw, classification)
        sanitized.update(
            {
                "failures": failures,
                "severity": severity,
                "incident_id": signature,
                "last_notified_epoch": last_notified,
            }
        )
        current[source_id] = sanitized

    for source_id, old in old_sources.items():
        if source_id not in current and old.get("severity") == "P1":
            notifications.append(
                {
                    "source_id": source_id,
                    "severity": "RECOVERY",
                    "classification": "removed_from_feed",
                    "reason": "state_transition",
                    "incident_id": f"{source_id}:source_health:removed",
                    "failures": 0,
                }
            )

    return {"sources": current}, notifications


def send_telegram(report_path: Path, subject: str) -> str:
    environment = os.environ.copy()
    environment.update(HERMES_ENV)
    try:
        completed = subprocess.run(
            ["hermes", "send", "--to", "telegram", "--subject", subject, "--file", str(report_path)],
            env=environment,
            cwd="/opt/mbfd/hermes",
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            timeout=15,
            check=False,
        )
    except subprocess.TimeoutExpired:
        return "timeout"
    except OSError as exc:
        return f"transport_{type(exc).__name__}"
    return "sent" if completed.returncode == 0 else f"failed_exit_{completed.returncode}"


def run(args: argparse.Namespace) -> int:
    state_dir = Path(args.state_dir)
    state_path = state_dir / f"{args.mode}-state.json"
    report_path = state_dir / f"{args.mode}-latest.json"
    previous = load_json(state_path, {})
    now_epoch = time.time()
    started = utc_now()

    payload, fetch_error = fetch_json(args.health_url)
    sources: list[dict[str, Any]] = []
    schema_error = None
    if fetch_error is None:
        sources, schema_error = extract_sources(payload)

    synthetic_error = fetch_error or schema_error
    if synthetic_error:
        sources = [
            {
                "source_id": "eoc-health-endpoint",
                "state": "degraded",
                "message": synthetic_error,
            }
        ]
    elif not sources:
        sources = [
            {
                "source_id": "eoc-source-catalog",
                "state": "schema_error",
                "message": "no_sources_returned",
            }
        ]

    new_state, notifications = evaluate(sources, previous, now_epoch)
    robots = {"checked": False, "status": "not_applicable"}
    if args.mode == "scrape-audit":
        robots_payload, robots_error = fetch_json(args.robots_url)
        robots = {
            "checked": True,
            "status": "available" if robots_error is None else "unavailable_ignored",
            "detail": "valid_json" if robots_error is None else robots_error,
            "records_observed": len(robots_payload) if isinstance(robots_payload, list) else None,
        }
        # Source checks own paging. Scrape audit is evidence/trend only to prevent
        # duplicate alerts for the same source incident.
        notifications = []

    classifications = [item["classification"] for item in new_state["sources"].values()]
    counts = {name: classifications.count(name) for name in ("healthy", "degraded", "schema_break", "zero_record")}
    report = {
        "schema_version": 1,
        "timestamp": started,
        "detector": args.mode,
        "severity": "P1" if any(item["severity"] == "P1" for item in new_state["sources"].values()) else ("P2" if any(item["severity"] == "P2" for item in new_state["sources"].values()) else "P3"),
        "evidence_reference": str(report_path),
        "llm_invoked": False,
        "request_id": None,
        "outcome": "detected" if synthetic_error or counts["degraded"] or counts["schema_break"] else "healthy",
        "fallback_status": "not_applicable",
        "notification_result": "not_required",
        "counts": counts,
        "source_count": len(new_state["sources"]),
        "robots": robots,
        "notifications": notifications,
        "sources": list(new_state["sources"].values()),
    }
    atomic_json(state_path, {**new_state, "updated_at": started})
    atomic_json(report_path, report)

    if notifications and not args.no_notify:
        alert_text_path = state_dir / f"{args.mode}-notification.txt"
        lines = [
            "MBFD deterministic EOC watchdog",
            f"Detector: {args.mode}",
            f"Observed: {started}",
            f"Evidence: {report_path}",
            "LLM invoked: false",
            "",
        ]
        for item in notifications:
            lines.append(
                f"{item['severity']} {item['source_id']}: {item['classification']} "
                f"({item['reason']}, consecutive={item['failures']})"
            )
        alert_text_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
        # Group read is intentional for the mbfd-aiops notification transport.
        os.chmod(alert_text_path, 0o640)  # codeql[py/overly-permissive-file]
        result = send_telegram(alert_text_path, f"[MBFD EOC {report['severity']}] deterministic watchdog")
        report["notification_result"] = result
        if result != "sent":
            report["fallback_status"] = "local_evidence_preserved"
        atomic_json(report_path, report)

    print(json.dumps({key: report[key] for key in ("timestamp", "detector", "severity", "evidence_reference", "llm_invoked", "request_id", "outcome", "fallback_status", "notification_result")}, sort_keys=True))
    return 0


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("source-check", "scrape-audit"), required=True)
    parser.add_argument("--state-dir", default=DEFAULT_STATE_DIR)
    parser.add_argument("--health-url", default=DEFAULT_HEALTH_URL)
    parser.add_argument("--robots-url", default=DEFAULT_ROBOTS_URL)
    parser.add_argument("--no-notify", action="store_true")
    return parser.parse_args(argv)


if __name__ == "__main__":
    raise SystemExit(run(parse_args()))
