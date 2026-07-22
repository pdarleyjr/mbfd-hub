from __future__ import annotations

import importlib.util
import json
import sys
from pathlib import Path

import pytest

HELPER = Path(__file__).resolve().parents[1] / "mbfd_backup_snapshot.py"


def _load():
    spec = importlib.util.spec_from_file_location("mbfd_backup_snapshot", HELPER)
    module = importlib.util.module_from_spec(spec)
    sys.modules["mbfd_backup_snapshot"] = module
    spec.loader.exec_module(module)
    return module


mod = _load()


def _snap(short: str, host: str = "mbfdhub", tags: list[str] | None = None) -> dict:
    return {
        "short_id": short,
        "hostname": host,
        "tags": tags if tags is not None else ["mbfd-ecosystem"],
    }


def _js(*snaps: dict) -> str:
    return json.dumps(list(snaps))


def test_verify_exact_match_returns_short_id():
    assert (
        mod.verify_snapshot(
            _js(_snap("abc12345")), "abc12345", "mbfdhub", "mbfd-ecosystem"
        )
        == "abc12345"
    )


def test_verify_prefix_match_returns_full_short_id():
    assert (
        mod.verify_snapshot(_js(_snap("abc12345")), "abc1", "mbfdhub", "mbfd-ecosystem")
        == "abc12345"
    )


def test_verify_empty_requested_id_fails():
    with pytest.raises(mod.SnapshotError):
        mod.verify_snapshot("[]", "", "mbfdhub", "mbfd-ecosystem")


def test_verify_missing_snapshot_fails():
    with pytest.raises(mod.SnapshotError, match="not found"):
        mod.verify_snapshot(
            _js(_snap("abc12345")), "zzz99999", "mbfdhub", "mbfd-ecosystem"
        )


def test_verify_wrong_host_fails():
    with pytest.raises(mod.SnapshotError):
        mod.verify_snapshot(
            _js(_snap("abc12345", host="other-host")),
            "abc12345",
            "mbfdhub",
            "mbfd-ecosystem",
        )


def test_verify_wrong_tag_fails():
    with pytest.raises(mod.SnapshotError):
        mod.verify_snapshot(
            _js(_snap("abc12345", tags=["other-tag"])),
            "abc12345",
            "mbfdhub",
            "mbfd-ecosystem",
        )


def test_verify_never_falls_back_to_latest():
    # Two snapshots present; requested id absent. Must NOT return any latest fallback.
    with pytest.raises(mod.SnapshotError, match="not found"):
        mod.verify_snapshot(
            _js(_snap("aaaa1111"), _snap("bbbb2222")),
            "cccc3333",
            "mbfdhub",
            "mbfd-ecosystem",
        )


def test_verify_ambiguous_prefix_fails_closed():
    with pytest.raises(mod.SnapshotError, match="ambiguous"):
        mod.verify_snapshot(
            _js(_snap("abc12345"), _snap("abc99999")),
            "abc",
            "mbfdhub",
            "mbfd-ecosystem",
        )


def test_verify_invalid_json_fails():
    with pytest.raises(mod.SnapshotError, match="invalid"):
        mod.verify_snapshot("not-json", "abc1", "mbfdhub", "mbfd-ecosystem")


def test_verify_non_list_json_fails():
    with pytest.raises(mod.SnapshotError, match="not a list"):
        mod.verify_snapshot('{"short_id": "abc1"}', "abc1", "mbfdhub", "mbfd-ecosystem")


def test_capture_returns_single_snapshot():
    assert (
        mod.capture_snapshot(_js(_snap("abc12345")), "mbfdhub", "mbfd-ecosystem")
        == "abc12345"
    )


def test_capture_rejects_zero_snapshots():
    with pytest.raises(mod.SnapshotError, match="got 0"):
        mod.capture_snapshot("[]", "mbfdhub", "mbfd-ecosystem")


def test_capture_rejects_multiple_snapshots():
    with pytest.raises(mod.SnapshotError, match="got 2"):
        mod.capture_snapshot(
            _js(_snap("abc12345"), _snap("def67890")), "mbfdhub", "mbfd-ecosystem"
        )


def test_capture_rejects_wrong_tag():
    with pytest.raises(mod.SnapshotError, match="got 0"):
        mod.capture_snapshot(
            _js(_snap("abc12345", tags=["other"])), "mbfdhub", "mbfd-ecosystem"
        )
