#!/usr/bin/env python3
"""Narrow canonical release: exact gateway code, preserved live topology/unit.

Invoked by migrate-ollama-ai-proxy.sh --admission-only. Never provisions consumers,
changes credentials/listeners/backends, or installs the broader mainline unit.
"""

from __future__ import annotations

import argparse
import copy
import datetime as dt
import hashlib
import http.client
import json
import os
import re
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

import mbfd_ai_gateway as gateway
import mbfd_ai_gateway_release as source

FILES = {
    "mbfd_ai_gateway.py": Path("/opt/ollama-ai-proxy/mbfd_ai_gateway.py"),
    "mbfd-ai-gateway.json": Path("/etc/ollama-ai-proxy/gateway.json"),
    "ollama-ai-proxy.service": Path("/etc/systemd/system/ollama-ai-proxy.service"),
}
STATE = Path("/etc/ollama-ai-proxy/deployment-source.json")


def digest(path: Path) -> str:
    if path.is_symlink() or not path.is_file():
        raise ValueError("release file absent or symlinked")
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_digest(value: dict) -> str:
    return hashlib.sha256(
        json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()


def admission_config(original: dict, sha: str) -> dict:
    if not re.fullmatch("[a-f0-9]{40}", sha):
        raise ValueError("invalid release SHA")
    revised = copy.deepcopy(original)
    policy = revised.get("heavy_workloads", {}).get("prm-sports-medium", {})
    if (
        policy.get("max_swap_activity_pages") != 4096
        or "max_swap_pages_per_second" in policy
    ):
        raise ValueError("unexpected Sports swap baseline; explicit review required")
    del policy["max_swap_activity_pages"]
    # Explicit new policy: 64 pages/s (256 KiB/s on a 4 KiB-page host).
    # This is below 4096 pages/minute; it is NOT a claim that the old
    # request-dependent measurement ever had a one-minute sample window.
    policy["max_swap_pages_per_second"] = 64
    revised["release_sha"] = sha
    return revised


def reverse_admission_config(revised: dict, previous_release_sha: str | None) -> dict:
    original = copy.deepcopy(revised)
    policy = original["heavy_workloads"]["prm-sports-medium"]
    if (
        policy.pop("max_swap_pages_per_second") != 64
        or "max_swap_activity_pages" in policy
    ):
        raise ValueError("unexpected admission migration")
    policy["max_swap_activity_pages"] = 4096
    if previous_release_sha is None:
        original.pop("release_sha", None)
    else:
        original["release_sha"] = previous_release_sha
    return original


def verify_live(candidate, state: dict, files=FILES) -> None:
    if (
        state.get("scope") != "admission-only-v1"
        or state["source_sha"] != candidate.source_sha
    ):
        raise ValueError("scoped release identity mismatch")
    if state["artifacts"] != dict(candidate.artifacts):
        raise ValueError("scoped source artifacts mismatch")
    runtime = state["runtime_artifacts"]
    if set(runtime) != set(FILES):
        raise ValueError("scoped live artifact list mismatch")
    for name, path in files.items():
        if digest(path) != runtime[name]:
            raise ValueError("scoped live hash mismatch: " + name)
    if runtime["mbfd_ai_gateway.py"] != candidate.artifacts["mbfd_ai_gateway.py"]:
        raise ValueError("gateway code does not match exact merged source")
    config = json.loads(files["mbfd-ai-gateway.json"].read_text())
    if config["release_sha"] != candidate.source_sha:
        raise ValueError("gateway provenance header identity mismatch")
    restored = reverse_admission_config(config, state["baseline_config_release_sha"])
    if canonical_digest(restored) != state["baseline_config_canonical_sha256"]:
        raise ValueError("unrelated runtime topology changed")
    if runtime["ollama-ai-proxy.service"] != state["baseline_unit_sha256"]:
        raise ValueError("preserved unit changed")


def request(config, token, method="GET", capability=None):
    connection = http.client.HTTPConnection("127.0.0.1", config.port, timeout=10)
    headers = {"Authorization": "Bearer " + token}
    body = None
    path = "/health/live"
    if capability:
        path = "/api/chat"
        headers["X-MBFD-Capability"] = capability
        headers["Content-Type"] = "application/json"
        body = json.dumps({"model": capability, "messages": [], "stream": False})
    try:
        connection.request(method, path, body=body, headers=headers)
        response = connection.getresponse()
        response.read(65536)
        return response.status
    finally:
        connection.close()


def smoke(config) -> None:
    if request(config, "") != 401 or request(config, "invalid-canary-token") != 401:
        raise ValueError("gateway authentication regression")
    for consumer in config.consumers.values():
        token = consumer.credential
        if request(config, token) != 200:
            raise ValueError("registered consumer health regression")
        if request(config, token, "POST", "unregistered-admission-canary") != 403:
            raise ValueError("capability authorization regression")
    actual = subprocess.run(
        ["ss", "-ltnH", "( sport = :11440 )"],
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    listeners = sorted(line.split()[3] for line in actual.splitlines())
    if listeners != sorted(f"{host}:{config.port}" for host in config.listeners):
        raise ValueError("gateway listener scope changed")


def install_atomic(data: bytes, path: Path, *, reader_group: int | None = None) -> None:
    fd, temporary = tempfile.mkstemp(prefix=".admission-release-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        # Configuration/state are always root-only. Only source code may be
        # readable by the service group; no caller can request world access.
        os.chmod(temporary, 0o600 if reader_group is None else 0o640)
        if reader_group is not None:
            os.chown(temporary, 0, reader_group)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def restore_previous(backup: Path) -> None:
    for name, live in {**FILES, "deployment-source.json": STATE}.items():
        saved = backup / name
        digest(saved)
        fd, temporary = tempfile.mkstemp(prefix=".admission-release-", dir=live.parent)
        os.close(fd)
        try:
            # Restore the protected backup's original permissions as well as
            # bytes. New secret files never use this restoration-only path.
            shutil.copy2(saved, temporary)
            os.replace(temporary, live)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
    subprocess.run(["systemctl", "restart", "ollama-ai-proxy.service"], check=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("source_dir", type=Path)
    parser.add_argument("expected_sha")
    parser.add_argument("--expected-live-config-sha256")
    parser.add_argument("--verify-only", action="store_true")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError("root required")
    import grp

    service_gid = grp.getgrnam("ollama-proxy").gr_gid
    candidate = source.validate_source(
        source_dir=args.source_dir,
        expected_sha=args.expected_sha,
        protected_ref="refs/remotes/origin/main",
        state_file=STATE,
        allow_initialize=False,
    )
    os.environ["CREDENTIALS_DIRECTORY"] = "/etc/ollama-ai-proxy"
    previous = json.loads(STATE.read_text())
    if args.verify_only:
        verify_live(candidate, previous)
        smoke(gateway.load_config(FILES["mbfd-ai-gateway.json"]))
        print("SCOPED_GATEWAY_CODE_TOPOLOGY_AUTH=PASS")
        return 0
    if digest(FILES["mbfd-ai-gateway.json"]) != args.expected_live_config_sha256:
        raise ValueError("operator-reviewed live config baseline changed")
    for name in ["mbfd_ai_gateway.py", "ollama-ai-proxy.service"]:
        if digest(FILES[name]) != previous.get("artifacts", {}).get(name):
            raise ValueError("previous source marker/live code or unit mismatch")
    original = json.loads(FILES["mbfd-ai-gateway.json"].read_text())
    revised = admission_config(original, candidate.source_sha)
    # The new rate must also be the canonical source policy, not a CLI override.
    source_config = json.loads((args.source_dir / "mbfd-ai-gateway.json").read_text())
    if (
        source_config["heavy_workloads"]["prm-sports-medium"][
            "max_swap_pages_per_second"
        ]
        != 64
    ):
        raise ValueError("source policy differs from scoped migration")
    backup = Path("/var/backups/mbfd-ai-gateway") / (
        dt.datetime.now(dt.UTC).strftime("%Y%m%dT%H%M%SZ") + "-admission-only"
    )
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    for name, live in {**FILES, "deployment-source.json": STATE}.items():
        digest(live)
        shutil.copy2(live, backup / name)
    checksum = subprocess.run(
        [
            "sha256sum",
            *[str(backup / name) for name in [*FILES, "deployment-source.json"]],
        ],
        check=True,
        capture_output=True,
    ).stdout
    (backup / "SHA256SUMS").write_bytes(checksum)
    subprocess.run(
        ["sha256sum", "-c", str(backup / "SHA256SUMS")], check=True, capture_output=True
    )
    revised_bytes = (json.dumps(revised, indent=2) + "\n").encode()
    config_candidate = backup / "config.candidate.json"
    config_candidate.write_bytes(revised_bytes)
    os.chmod(config_candidate, 0o600)
    gateway.load_config(config_candidate)
    state = source.build_state(candidate)
    state.update(
        scope="admission-only-v1",
        baseline_config_canonical_sha256=canonical_digest(original),
        baseline_config_release_sha=original.get("release_sha"),
        baseline_unit_sha256=digest(FILES["ollama-ai-proxy.service"]),
        backup=str(backup),
    )
    try:
        install_atomic(
            (args.source_dir / "mbfd_ai_gateway.py").read_bytes(),
            FILES["mbfd_ai_gateway.py"],
            reader_group=service_gid,
        )
        install_atomic(revised_bytes, FILES["mbfd-ai-gateway.json"])
        state["runtime_artifacts"] = {
            name: digest(path) for name, path in FILES.items()
        }
        install_atomic(
            (json.dumps(state, sort_keys=True) + "\n").encode(), STATE
        )
        verify_live(candidate, state)
        subprocess.run(["systemctl", "restart", "ollama-ai-proxy.service"], check=True)
        for attempt in range(40):
            try:
                smoke(gateway.load_config(FILES["mbfd-ai-gateway.json"]))
                break
            except (OSError, ValueError):
                if attempt == 39:
                    raise
                time.sleep(0.25)
        verify_live(candidate, state)
    except BaseException:
        restore_previous(backup)
        print("SCOPED_GATEWAY_RELEASE=ROLLED_BACK")
        raise
    print(
        "SCOPED_GATEWAY_RELEASE=PASS source_sha="
        + candidate.source_sha
        + " backup="
        + str(backup)
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print("SCOPED_GATEWAY_RELEASE=FAIL type=" + type(error).__name__)
        raise SystemExit(2) from None
