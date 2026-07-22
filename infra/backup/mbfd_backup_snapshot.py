#!/usr/bin/env python3
"""Resolve / verify an EXACT Restic snapshot ID.

Used by the MBFD ecosystem backup + restore-smoke so the restore step always
restores the precise snapshot the backup captured -- never an independently
selected "latest" snapshot (which can be stale or wrong if the restic cache is
out of date or the backup hasn't fully registered).

Two modes:

  verify  --id ID --host H --tag T   read restic `snapshots --json` on stdin and
                                     confirm ID matches a snapshot with host H
                                     and tag T. Never falls back to latest.
                                     Fails on missing / empty / ambiguous.

  capture --host H --tag T           read restic `snapshots --json` (already
                                     filtered to the just-created backup) and
                                     return the single short_id. Used by the
                                     backup script to record the exact id.

Logs only safe metadata (snapshot short ids, host, tag, counts).
"""

from __future__ import annotations

import argparse
import json
import sys
from typing import Any


class SnapshotError(Exception):
    pass


def _parse_snapshots(snapshots_json: str) -> list[dict[str, Any]]:
    try:
        data = json.loads(snapshots_json or "[]")
    except json.JSONDecodeError as exc:
        raise SnapshotError(f"invalid restic snapshots json: {exc}") from exc
    if not isinstance(data, list):
        raise SnapshotError("restic snapshots json is not a list")
    return [s for s in data if isinstance(s, dict)]


def _matches_host_tag(s: dict[str, Any], expected_host: str, expected_tag: str) -> bool:
    if expected_host and str(s.get("hostname", "")) != expected_host:
        return False
    tags = s.get("tags", []) or []
    if expected_tag and expected_tag not in tags:
        return False
    return True


def verify_snapshot(
    snapshots_json: str,
    requested_id: str,
    expected_host: str,
    expected_tag: str,
) -> str:
    """Return the short_id matching requested_id (exact or prefix) + host/tag.

    Never falls back to 'latest'. Raises SnapshotError on empty, missing,
    or ambiguous matches.
    """
    if not requested_id or not requested_id.strip():
        raise SnapshotError("requested snapshot id is empty")
    requested = requested_id.strip()
    snapshots = _parse_snapshots(snapshots_json)
    matches = [
        str(s.get("short_id", ""))
        for s in snapshots
        if _matches_host_tag(s, expected_host, expected_tag)
        and (
            str(s.get("short_id", "")) == requested
            or str(s.get("short_id", "")).startswith(requested)
        )
    ]
    matches = [m for m in matches if m]
    if not matches:
        raise SnapshotError(
            f"snapshot {requested} not found with host={expected_host} tag={expected_tag}"
        )
    if len(set(matches)) > 1:
        raise SnapshotError(
            f"ambiguous snapshot id {requested}: matches {sorted(set(matches))}"
        )
    return matches[0]


def capture_snapshot(snapshots_json: str, expected_host: str, expected_tag: str) -> str:
    """Return the single short_id from a just-created backup's snapshots list.

    The caller must pass restic output already filtered to the new backup
    (e.g. `restic snapshots --host H --tag T --latest 1 --json`). Exactly one
    match is required; otherwise fail closed.
    """
    snapshots = [
        s
        for s in _parse_snapshots(snapshots_json)
        if _matches_host_tag(s, expected_host, expected_tag)
    ]
    if len(snapshots) != 1:
        raise SnapshotError(
            f"expected exactly 1 captured snapshot (host={expected_host} tag={expected_tag}), "
            f"got {len(snapshots)}"
        )
    short = str(snapshots[0].get("short_id", ""))
    if not short:
        raise SnapshotError("captured snapshot has no short_id")
    return short


def _main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(
        description="Resolve/verify an exact Restic snapshot id"
    )
    sub = p.add_subparsers(dest="mode", required=True)
    v = sub.add_parser(
        "verify", help="verify a requested id against restic snapshots json"
    )
    v.add_argument("--id", required=True)
    v.add_argument("--host", required=True)
    v.add_argument("--tag", required=True)
    c = sub.add_parser("capture", help="capture the single just-created snapshot id")
    c.add_argument("--host", required=True)
    c.add_argument("--tag", required=True)
    args = p.parse_args(argv)

    snapshots_json = sys.stdin.read()
    try:
        if args.mode == "verify":
            print(verify_snapshot(snapshots_json, args.id, args.host, args.tag))
        else:
            print(capture_snapshot(snapshots_json, args.host, args.tag))
    except SnapshotError as exc:
        print(f"FATAL: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(_main())
