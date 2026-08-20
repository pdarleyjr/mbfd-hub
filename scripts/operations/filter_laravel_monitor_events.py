#!/usr/bin/env python3
"""Reduce new Laravel log entries to evidence-backed monitor events."""

from __future__ import annotations

import re
import sys
from typing import NamedTuple


ENTRY_HEADER = re.compile(
    r"^\[[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:]+\] "
    r"production\.(?:ERROR|CRITICAL|ALERT|EMERGENCY):",
    re.MULTILINE,
)
APP_FRAME = re.compile(r"^#[0-9]+ .*(?:/app/|/routes/|/tmp/)")


class FilterResult(NamedTuple):
    output: str
    suppressed_count: int


def _entries(log_text: str) -> list[str]:
    matches = list(ENTRY_HEADER.finditer(log_text))
    return [
        log_text[match.start() : matches[index + 1].start() if index + 1 < len(matches) else None]
        for index, match in enumerate(matches)
    ]


def _is_known_non_incident(entry: str) -> bool:
    normalized = entry.lower()
    header = entry.splitlines()[0].lower()

    if "a target fifa bronze or gold record already exists" in header:
        return True
    if "vendor/psy/psysh/" in normalized:
        return True
    if "requested room does not exist" in header and "/tmp/video-conference-fixture.php" in normalized:
        return True

    rejected_webhook = (
        "signature verification failed" in header
        or "authorization header is empty" in header
    )
    webhook_trace = (
        "livekitconferenceprovider.php" in normalized
        and "verifywebhook" in normalized
    ) or "livekitwebhookcontroller.php" in normalized
    return rejected_webhook and webhook_trace


def _summarize(entry: str) -> str:
    lines = entry.rstrip().splitlines()
    summary = [lines[0]]
    evidence_frames = [line.strip() for line in lines[1:] if APP_FRAME.match(line.strip())]
    summary.extend(f"evidence_frame={frame}" for frame in evidence_frames[:3])
    return "\n".join(summary)


def filter_events(log_text: str) -> FilterResult:
    retained: list[str] = []
    suppressed_count = 0

    for entry in _entries(log_text):
        if _is_known_non_incident(entry):
            suppressed_count += 1
            continue
        retained.append(_summarize(entry))

    return FilterResult(output="\n".join(retained), suppressed_count=suppressed_count)


def main() -> int:
    result = filter_events(sys.stdin.read())
    if result.output:
        print(result.output)
    if result.suppressed_count:
        print(f"INFO filtered_non_incident_events={result.suppressed_count}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
