#!/usr/bin/env python3
"""Fail-closed source provenance and live-parity checks for gateway releases."""

from __future__ import annotations

import argparse
import dataclasses
import datetime as dt
import hashlib
import json
import re
import subprocess
import sys
from collections.abc import Mapping, Sequence
from pathlib import Path

SOURCE_ARTIFACTS = (
    "mbfd_ai_gateway.py",
    "mbfd-ai-gateway.json",
    "ollama-ai-proxy.service",
    "migrate-ollama-ai-proxy.sh",
    "provision-mbfd-ai-gateway-consumers.sh",
    "verify-ollama-ai-proxy.sh",
    "mbfd-ai-gateway-smoke.py",
    "mbfd_ai_gateway_release.py",
)
LIVE_ARTIFACTS = (
    "mbfd_ai_gateway.py",
    "mbfd-ai-gateway.json",
    "ollama-ai-proxy.service",
)
SHA_PATTERN = re.compile(r"^[0-9a-f]{40}$")


class SourceValidationError(ValueError):
    """The release candidate or deployed state is not safe to use."""


@dataclasses.dataclass(frozen=True, slots=True)
class SourceRelease:
    repo_root: Path
    source_dir: Path
    source_sha: str
    protected_ref: str
    artifacts: Mapping[str, str]


def _git_repository_boundary(path: Path) -> Path:
    """Return the nearest repository root without asking Git to trust it first."""
    resolved = path.resolve()
    for candidate in (resolved, *resolved.parents):
        if (candidate / ".git").exists():
            return candidate
    return resolved


def _run_git(repo: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess:
    safe_directory = _git_repository_boundary(repo)
    result = subprocess.run(
        ["git", "-c", f"safe.directory={safe_directory}", *args],
        cwd=repo,
        check=False,
        capture_output=True,
        text=True,
    )
    if check and result.returncode != 0:
        detail = (result.stderr or result.stdout).strip().splitlines()
        message = detail[-1] if detail else f"git exited {result.returncode}"
        raise SourceValidationError(f"git validation failed: {message}")
    return result


def _git_text(repo: Path, *args: str) -> str:
    return _run_git(repo, *args).stdout.strip()


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _load_state(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise SourceValidationError(
            f"deployment state cannot be read: {error}"
        ) from error
    if not isinstance(value, dict) or value.get("schema_version") != 1:
        raise SourceValidationError("deployment state schema_version must be 1")
    source_sha = value.get("source_sha")
    if not isinstance(source_sha, str) or not SHA_PATTERN.fullmatch(source_sha):
        raise SourceValidationError("deployment state source_sha is invalid")
    return value


def validate_source(
    *,
    source_dir: Path,
    expected_sha: str,
    protected_ref: str,
    state_file: Path,
    allow_initialize: bool,
) -> SourceRelease:
    """Validate an exact, clean protected source and reject stale deployment."""
    if not SHA_PATTERN.fullmatch(expected_sha):
        raise SourceValidationError(
            "expected source SHA must be 40 lowercase hex characters"
        )
    source_dir = source_dir.resolve()
    if not source_dir.is_dir():
        raise SourceValidationError("source directory does not exist")

    root_text = _git_text(source_dir, "rev-parse", "--show-toplevel")
    repo_root = Path(root_text).resolve()
    required_source_dir = (repo_root / "scripts" / "operations").resolve()
    if source_dir != required_source_dir:
        raise SourceValidationError(
            "source directory must be the repository scripts/operations directory"
        )

    head_sha = _git_text(repo_root, "rev-parse", "HEAD")
    if head_sha != expected_sha:
        raise SourceValidationError(
            f"candidate HEAD {head_sha} does not equal expected SHA {expected_sha}"
        )
    protected_sha = _git_text(repo_root, "rev-parse", protected_ref)
    if protected_sha != expected_sha:
        raise SourceValidationError(
            f"protected ref {protected_ref} is {protected_sha}, not {expected_sha}"
        )
    dirty = _git_text(repo_root, "status", "--porcelain=v1", "--untracked-files=no")
    if dirty:
        raise SourceValidationError("candidate has tracked changes")

    artifacts: dict[str, str] = {}
    for name in SOURCE_ARTIFACTS:
        path = source_dir / name
        if not path.is_file() or path.is_symlink():
            raise SourceValidationError(f"required source artifact is missing: {name}")
        relative = path.relative_to(repo_root).as_posix()
        _run_git(repo_root, "ls-files", "--error-unmatch", "--", relative)
        artifacts[name] = _sha256(path)

    state_file = state_file.resolve()
    if not state_file.exists():
        if not allow_initialize:
            raise SourceValidationError(
                "deployment state is absent; initial source convergence requires "
                "the explicit initialization flag"
            )
    else:
        state = _load_state(state_file)
        deployed_sha = state["source_sha"]
        ancestry = _run_git(
            repo_root,
            "merge-base",
            "--is-ancestor",
            deployed_sha,
            expected_sha,
            check=False,
        )
        if ancestry.returncode != 0:
            raise SourceValidationError(
                f"candidate {expected_sha} is not a descendant of deployed source "
                f"{deployed_sha}"
            )

    return SourceRelease(
        repo_root=repo_root,
        source_dir=source_dir,
        source_sha=expected_sha,
        protected_ref=protected_ref,
        artifacts=artifacts,
    )


def build_state(candidate: SourceRelease, *, deployed_at: str | None = None) -> dict:
    """Build a secret-free immutable deployment-state document."""
    timestamp = deployed_at or dt.datetime.now(dt.UTC).isoformat().replace(
        "+00:00", "Z"
    )
    try:
        parsed = dt.datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
    except ValueError as error:
        raise SourceValidationError(
            "deployed_at must be an ISO-8601 timestamp"
        ) from error
    if parsed.tzinfo is None:
        raise SourceValidationError("deployed_at must include a timezone")
    commit_time = _git_text(
        candidate.repo_root, "show", "-s", "--format=%cI", candidate.source_sha
    )
    return {
        "schema_version": 1,
        "source_sha": candidate.source_sha,
        "source_commit_time": commit_time,
        "protected_ref": candidate.protected_ref,
        "deployed_at": timestamp,
        "artifacts": dict(sorted(candidate.artifacts.items())),
    }


def verify_live(
    candidate: SourceRelease,
    state_file: Path,
    live_files: Mapping[str, Path],
) -> None:
    """Require the marker, exact source, and every installed artifact to agree."""
    state = _load_state(state_file.resolve())
    if state["source_sha"] != candidate.source_sha:
        raise SourceValidationError(
            "deployment state source SHA does not match candidate"
        )
    if state.get("protected_ref") != candidate.protected_ref:
        raise SourceValidationError(
            "deployment state protected ref does not match candidate"
        )
    state_artifacts = state.get("artifacts")
    if not isinstance(state_artifacts, dict):
        raise SourceValidationError("deployment state artifacts must be an object")
    if set(live_files) != set(LIVE_ARTIFACTS):
        raise SourceValidationError("live artifact mapping is incomplete or unexpected")
    for name in SOURCE_ARTIFACTS:
        if state_artifacts.get(name) != candidate.artifacts[name]:
            raise SourceValidationError(f"deployment state hash differs for {name}")
    for name, live_path in live_files.items():
        try:
            live_hash = _sha256(live_path.resolve(strict=True))
        except OSError as error:
            raise SourceValidationError(
                f"live artifact cannot be read: {name}"
            ) from error
        if live_hash != candidate.artifacts[name]:
            raise SourceValidationError(f"live hash differs from source for {name}")


def _candidate_from_args(args: argparse.Namespace) -> SourceRelease:
    return validate_source(
        source_dir=Path(args.source_dir),
        expected_sha=args.expected_sha,
        protected_ref=args.protected_ref,
        state_file=Path(args.state_file),
        allow_initialize=args.allow_initialize,
    )


def _add_source_arguments(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--source-dir", required=True)
    parser.add_argument("--expected-sha", required=True)
    parser.add_argument("--protected-ref", default="refs/remotes/origin/main")
    parser.add_argument("--state-file", required=True)
    parser.add_argument("--allow-initialize", action="store_true")


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    commands = parser.add_subparsers(dest="command", required=True)
    validate_parser = commands.add_parser("validate-source")
    _add_source_arguments(validate_parser)
    state_parser = commands.add_parser("build-state")
    _add_source_arguments(state_parser)
    state_parser.add_argument("--deployed-at")
    verify_parser = commands.add_parser("verify-live")
    _add_source_arguments(verify_parser)
    verify_parser.add_argument("--live-script", required=True)
    verify_parser.add_argument("--live-config", required=True)
    verify_parser.add_argument("--live-unit", required=True)
    args = parser.parse_args(argv)

    try:
        candidate = _candidate_from_args(args)
        if args.command == "validate-source":
            print(f"SOURCE_PROVENANCE=PASS source_sha={candidate.source_sha}")
        elif args.command == "build-state":
            print(
                json.dumps(
                    build_state(candidate, deployed_at=args.deployed_at),
                    sort_keys=True,
                    separators=(",", ":"),
                )
            )
        else:
            verify_live(
                candidate,
                Path(args.state_file),
                {
                    "mbfd_ai_gateway.py": Path(args.live_script),
                    "mbfd-ai-gateway.json": Path(args.live_config),
                    "ollama-ai-proxy.service": Path(args.live_unit),
                },
            )
            print(f"SOURCE_LIVE_PARITY=PASS source_sha={candidate.source_sha}")
    except SourceValidationError as error:
        print(f"SOURCE_PROVENANCE=FAIL detail={error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
