#!/usr/bin/env python3
"""Validate MBFD Hub availability separately from its authentication redirects."""

from __future__ import annotations

import ssl
import sys
import json
import re
import stat
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Callable


@dataclass(frozen=True)
class ProbeResponse:
    status: int
    location: str = ""


@dataclass(frozen=True)
class ContractCheck:
    name: str
    url: str
    expected_status: int
    redirect_target: str | None = None


@dataclass(frozen=True)
class ProbeResult:
    name: str
    ok: bool
    status: int | None
    location: str
    reason: str
    expected_status: int


CONTRACT = (
    ContractCheck("application-up", "https://mbfdhub.com/up", 200),
    ContractCheck("www-application-up", "https://www.mbfdhub.com/up", 200),
    ContractCheck("canonical-login", "https://mbfdhub.com/login", 200),
    ContractCheck("www-canonical-login", "https://www.mbfdhub.com/login", 200),
    ContractCheck(
        "main",
        "https://mbfdhub.com/",
        302,
        "https://mbfdhub.com/login",
    ),
    ContractCheck(
        "www",
        "https://www.mbfdhub.com/",
        302,
        "https://www.mbfdhub.com/login",
    ),
    ContractCheck(
        "admin",
        "https://www.mbfdhub.com/admin",
        302,
        "https://www.mbfdhub.com/login",
    ),
    ContractCheck(
        "admin-login",
        "https://www.mbfdhub.com/admin/login",
        302,
        "https://www.mbfdhub.com/login",
    ),
)

MAINTENANCE_MARKER = Path("/run/mbfd-maintenance/mbfd-hub.json")
MAX_MAINTENANCE_SECONDS = 900


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def fetch_url(url: str) -> ProbeResponse:
    opener = urllib.request.build_opener(
        NoRedirect,
        urllib.request.HTTPSHandler(context=ssl.create_default_context()),
    )
    request = urllib.request.Request(
        url,
        method="HEAD",
        headers={"User-Agent": "MBFD-Site-Monitor/3.0", "Cookie": ""},
    )
    try:
        response = opener.open(request, timeout=12)
        return ProbeResponse(response.status, response.headers.get("location", ""))
    except urllib.error.HTTPError as error:
        return ProbeResponse(error.code, error.headers.get("location", ""))


def canonical_url(value: str, base: str) -> str | None:
    absolute = urllib.parse.urljoin(base, value)
    parsed = urllib.parse.urlsplit(absolute)
    if (
        parsed.scheme != "https"
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.port is not None
        or parsed.query
        or parsed.fragment
    ):
        return None
    path = parsed.path or "/"
    return urllib.parse.urlunsplit(("https", parsed.hostname.lower(), path, "", ""))


def evaluate(
    check: ContractCheck,
    fetch: Callable[[str], ProbeResponse],
    planned_maintenance: bool = False,
) -> ProbeResult:
    try:
        response = fetch(check.url)
    except Exception:
        return ProbeResult(
            check.name,
            False,
            None,
            "",
            "connection_failure",
            check.expected_status,
        )

    if response.status != check.expected_status:
        if planned_maintenance and response.status == 503:
            return ProbeResult(
                check.name,
                True,
                response.status,
                response.location,
                "planned_maintenance",
                check.expected_status,
            )
        return ProbeResult(
            check.name,
            False,
            response.status,
            response.location,
            "unexpected_status",
            check.expected_status,
        )

    if check.redirect_target is None:
        return ProbeResult(
            check.name,
            True,
            response.status,
            response.location,
            "ok",
            check.expected_status,
        )

    source = canonical_url(check.url, check.url)
    target = canonical_url(response.location, check.url) if response.location else None
    expected_target = canonical_url(check.redirect_target, check.url)
    if target is not None and target == source:
        return ProbeResult(
            check.name,
            False,
            response.status,
            response.location,
            "redirect_loop",
            check.expected_status,
        )
    if target is None or target != expected_target:
        return ProbeResult(
            check.name,
            False,
            response.status,
            response.location,
            "unexpected_redirect",
            check.expected_status,
        )

    try:
        terminal = fetch(target)
    except Exception:
        return ProbeResult(
            check.name,
            False,
            response.status,
            response.location,
            "connection_failure",
            check.expected_status,
        )

    if 300 <= terminal.status < 400:
        next_target = (
            canonical_url(terminal.location, target) if terminal.location else None
        )
        reason = "redirect_loop" if next_target in {source, target} else "unexpected_redirect"
        return ProbeResult(
            check.name,
            False,
            response.status,
            response.location,
            reason,
            check.expected_status,
        )
    if terminal.status != 200:
        return ProbeResult(
            check.name,
            False,
            response.status,
            response.location,
            "redirect_destination_unhealthy",
            check.expected_status,
        )

    return ProbeResult(
        check.name,
        True,
        response.status,
        target,
        "ok",
        check.expected_status,
    )


def validate_marker_payload(payload: object, now_epoch: int) -> bool:
    if not isinstance(payload, dict):
        return False
    started = payload.get("started_at_epoch")
    expires = payload.get("expires_at_epoch")
    if isinstance(started, bool) or not isinstance(started, int):
        return False
    if isinstance(expires, bool) or not isinstance(expires, int):
        return False
    return (
        payload.get("schema_version") == 1
        and payload.get("service") == "mbfd-hub"
        and isinstance(payload.get("release_sha"), str)
        and re.fullmatch(r"[0-9a-f]{40}", payload["release_sha"]) is not None
        and 0 < expires - started <= MAX_MAINTENANCE_SECONDS
        and started <= now_epoch <= expires
    )


def marker_active(path: Path = MAINTENANCE_MARKER, now_epoch: int | None = None) -> bool:
    try:
        metadata = path.lstat()
        if not stat.S_ISREG(metadata.st_mode):
            return False
        if metadata.st_uid != 0 or metadata.st_mode & (stat.S_IWGRP | stat.S_IWOTH):
            return False
        with path.open("r", encoding="utf-8") as handle:
            payload = json.load(handle)
    except (OSError, ValueError, TypeError):
        return False
    return validate_marker_payload(payload, int(time.time()) if now_epoch is None else now_epoch)


def run_contract(
    fetch: Callable[[str], ProbeResponse] = fetch_url,
    planned_maintenance: bool = False,
) -> list[ProbeResult]:
    return [evaluate(check, fetch, planned_maintenance) for check in CONTRACT]


def format_result(result: ProbeResult) -> str:
    actual = "connection_failure" if result.status is None else str(result.status)
    location = f" location={result.location}" if result.location else ""
    if result.reason == "planned_maintenance":
        return f"INFO http_probe name={result.name} actual={actual} reason=planned_maintenance"
    if result.ok:
        return f"OK http_probe name={result.name} actual={actual}{location}"
    return (
        f"ISSUE http_probe name={result.name} expected={result.expected_status} "
        f"actual={actual} reason={result.reason}{location}"
    )


def main() -> int:
    for result in run_contract(planned_maintenance=marker_active()):
        print(format_result(result))
    return 0


if __name__ == "__main__":
    sys.exit(main())
