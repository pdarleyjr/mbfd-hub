#!/usr/bin/env python3
"""Fail-closed Lane C inventory, plan, and post-retirement verifier.

This helper intentionally performs no lifecycle mutation.  It captures only
allowlisted, secret-free metadata, validates it against the Phase 1 freeze, and
can produce a clean OnlyOffice Compose candidate with the three unsafe AI bind
mounts removed.  The Release Captain remains responsible for snapshots,
credential retirement, service changes, and rollback.
"""

from __future__ import annotations

import argparse
import dataclasses
import hashlib
import json
import os
import socket
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from collections.abc import Iterable, Sequence
from pathlib import Path
from typing import Any

SNAPSHOT_SCHEMA = "mbfd.ai-lifecycle.lane-c.v1"
EXPECTED_HOST = "mbfdhub"
EXPECTED_OFFICE_SOURCE_SHA = "201c5ff337797494c55d8d21979e1021bc6d9062"
ARCHIVE_PARENT = "/mnt/mbfd-storage/backups/on-demand"


class LifecycleError(Exception):
    """Raised when live state differs from the frozen retirement boundary."""


@dataclasses.dataclass(frozen=True)
class ContainerSpec:
    name: str
    project: str
    service: str
    classification: str


RETIRE_SPECS = (
    ContainerSpec("open-webui", "mbfd-ai", "open-webui", "retire"),
    ContainerSpec("qdrant", "mbfd-ai", "qdrant", "retire-exclusive"),
    ContainerSpec("tika", "mbfd-ai-extras", "tika", "retire-exclusive"),
    ContainerSpec("mcpo", "mbfd-ai-extras", "mcpo", "retire-exclusive"),
    ContainerSpec(
        "mbfd-office-doc-agent",
        "mbfd-office-doc-agent",
        "app",
        "retire-exclusive",
    ),
    ContainerSpec(
        "doc-generator", "mbfd-ai-extras", "doc-generator", "retire-exclusive"
    ),
    ContainerSpec("piper-tts", "mbfd-ai-extras", "tts", "retire-exclusive"),
    ContainerSpec("whisper-stt", "mbfd-ai-extras", "whisper", "retire-exclusive"),
    ContainerSpec(
        "nextcloud-user-fs",
        "mbfd-ai-extras",
        "nextcloud-user-fs",
        "retire-only-after-caller-recheck",
    ),
    ContainerSpec(
        "nextcloud-write",
        "mbfd-ai-extras",
        "nextcloud-write",
        "retire-only-after-caller-recheck",
    ),
    ContainerSpec(
        "media-control-tools",
        "mbfd-ai-extras",
        "media-control-tools",
        "retire-only-after-caller-recheck",
    ),
)

KEEP_SPECS = (
    ContainerSpec("searxng", "mbfd-ai-extras", "searxng", "keep-shared-hermes"),
    ContainerSpec("searxng-valkey", "mbfd-ai-extras", "valkey", "keep-shared-searxng"),
    ContainerSpec("comfyui", "mbfd-ai-extras", "comfyui", "keep-specialist"),
    ContainerSpec("cmd-whisper", "infra", "cmd-whisper", "keep-command-stt"),
    ContainerSpec(
        "mbfd-nextcloud", "mbfd-workspace", "nextcloud", "keep-core-workspace"
    ),
    ContainerSpec(
        "mbfd-onlyoffice", "mbfd-workspace", "onlyoffice", "keep-core-workspace"
    ),
    ContainerSpec("media-control", "media-control", "media-control", "keep-core"),
)

ONLYOFFICE_AI_MOUNTS = {
    "/opt/mbfd-workspace/onlyoffice-ai/index.html": (
        "/var/www/onlyoffice/documentserver/sdkjs-plugins/"
        "{9DC93CDB-B576-4F0C-B55E-FCC9C48DD007}/index.html"
    ),
    "/opt/mbfd-workspace/onlyoffice-ai/index.html.gz": (
        "/var/www/onlyoffice/documentserver/sdkjs-plugins/"
        "{9DC93CDB-B576-4F0C-B55E-FCC9C48DD007}/index.html.gz"
    ),
    "/opt/mbfd-workspace/onlyoffice-ai/mbfd-default-model.js": (
        "/var/www/onlyoffice/documentserver/sdkjs-plugins/"
        "{9DC93CDB-B576-4F0C-B55E-FCC9C48DD007}/scripts/mbfd-default-model.js"
    ),
}

EXPECTED_QDRANT_COLLECTIONS = {
    "open-webui_files",
    "open-webui_knowledge",
}

EXPECTED_CONNECTION_FILES = {
    "/opt/ai-stack/openwebui-extras.env",
    "/opt/ai-stack/openwebui-extras.env.bak.1779878348",
    "/opt/ai-stack/openwebui-extras.env.bak.1779878372",
    "/opt/ai-stack/openwebui-extras.env.bak-20260609-121231",
    "/opt/ai-stack/openwebui-extras.env.bak.img.1780224356",
    "/opt/ai-stack/openwebui-extras.env.bak-kilo-20260615-165330",
}

RETIRE_LISTENERS = {
    "open-webui": 3030,
    "qdrant": 6333,
    "office-agent": 8789,
}

SAFE_DOCKER_INSPECT_FORMAT = (
    '{"name":{{json .Name}},'
    '"running":{{json .State.Running}},'
    '"health":{{if .State.Health}}{{json .State.Health.Status}}'
    '{{else}}"not-configured"{{end}},'
    '"restart":{{json .HostConfig.RestartPolicy.Name}},'
    '"project":{{json (index .Config.Labels "com.docker.compose.project")}},'
    '"service":{{json (index .Config.Labels "com.docker.compose.service")}},'
    '"mounts":{{json .Mounts}},'
    '"networks":{{json .NetworkSettings.Networks}}}'
)

# Docker evaluates the split internally and returns key names only. Credential
# values never cross the process boundary into this helper.
SAFE_ENV_KEY_FORMAT = '{{range .Config.Env}}{{println (index (split . "=") 0)}}{{end}}'

EXPECTED_FILE_HASHES = {
    "/opt/ai-stack/docker-compose.yml": (
        "282c217415e7ed31c7b94e6542055a2d2ee51715ccc4adc6ca1aec279785b786"
    ),
    "/opt/ai-stack/extras/docker-compose.yml": (
        "b829b05bcc66fca6db1665ea260ae37ba0deee7efa6063d04507f2906f98e811"
    ),
    "/opt/mbfd-office-doc-agent/docker-compose.yml": (
        "c8822edfb1101b57a4713da073d7c5d75926f9ba3357ea72a841bd6d970088c1"
    ),
    "/opt/mbfd-workspace/docker-compose.yml": (
        "425600f504781c92a6b70bf16d5a64a340df9d1f8968fe61f1e04204158dff32"
    ),
    "/etc/systemd/system/mbfd-owui-rag-sync.timer": (
        "d0d98e2f5a20f27c1813235c762f7cd25bf910f9cf43f27765cb4a2247358572"
    ),
    "/etc/systemd/system/mbfd-owui-rag-sync.service": (
        "bd5a37224095cce2ac479d5ecfb4c26239d17f5093de0f177308922a99641267"
    ),
    "/opt/mbfd-workspace/onlyoffice-ai/index.html": (
        "acda5b26de284c37e7cb6d5b32a9c050338e0663dc99968f7b4a2b9dcc62e5fb"
    ),
    "/opt/mbfd-workspace/onlyoffice-ai/index.html.gz": (
        "1e463d1a3792f623dca27021b874db8b129abb6833813f4471a9061356ab4789"
    ),
    "/opt/mbfd-workspace/onlyoffice-ai/mbfd-default-model.js": (
        "ec0e5091ea5151fafb4c53544d6ac1c68586ba7c032967139c4e758a753a537c"
    ),
}

PRESERVED_VOLUMES = {
    "mbfd-ai_openwebui_data",
    "mbfd-ai_qdrant_data",
    "mbfd-ai-mcp-memory-data",
    "mbfd-ai-tts-voices",
    "mbfd-ai-whisper-models",
    "mbfd-office-doc-agent-data",
    "mbfd-office-doc-agent-db",
    "mbfd-office-doc-agent-logs",
}


@dataclasses.dataclass(frozen=True)
class PlanStep:
    number: int
    title: str
    action: str
    mutates: bool
    gate: str

    def as_dict(self) -> dict[str, object]:
        return dataclasses.asdict(self)


def _require_mapping(value: object, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise LifecycleError(f"{label} must be an object")
    return value


def _container(snapshot: dict[str, Any], name: str) -> dict[str, Any]:
    containers = _require_mapping(snapshot.get("containers"), "containers")
    value = containers.get(name)
    if value is None:
        raise LifecycleError(f"container {name} is absent")
    return _require_mapping(value, f"container {name}")


def _assert_container_identity(
    snapshot: dict[str, Any], spec: ContainerSpec, *, must_run: bool
) -> None:
    container = _container(snapshot, spec.name)
    if container.get("project") != spec.project:
        raise LifecycleError(
            f"container {spec.name} project drift: "
            f"{container.get('project')!r} != {spec.project!r}"
        )
    if container.get("service") != spec.service:
        raise LifecycleError(
            f"container {spec.name} service drift: "
            f"{container.get('service')!r} != {spec.service!r}"
        )
    if must_run and container.get("running") is not True:
        adjective = "retained" if spec in KEEP_SPECS else "retirement candidate"
        raise LifecycleError(f"{adjective} container {spec.name} is not running")


def _mount_pairs(container: dict[str, Any]) -> set[tuple[str, str]]:
    result: set[tuple[str, str]] = set()
    mounts = container.get("mounts", [])
    if not isinstance(mounts, list):
        raise LifecycleError("container mounts must be an array")
    for mount in mounts:
        if not isinstance(mount, dict):
            continue
        source = str(mount.get("source", ""))
        destination = str(mount.get("destination", ""))
        result.add((source, destination))
    return result


def _source_matches(mount_source: str, expected_name_or_path: str) -> bool:
    return mount_source == expected_name_or_path or mount_source.rstrip("/").endswith(
        f"/{expected_name_or_path}/_data"
    )


def _assert_preserved_volumes(snapshot: dict[str, Any]) -> None:
    volumes = snapshot.get("volumes", [])
    if not isinstance(volumes, list):
        raise LifecycleError("volumes must be an array")
    present = {str(item) for item in volumes}
    for volume in sorted(PRESERVED_VOLUMES - present):
        raise LifecycleError(f"preserved volume missing: {volume}")


def validate_preflight(snapshot: dict[str, Any]) -> None:
    """Validate the exact pre-retirement state frozen in Phase 1."""

    if snapshot.get("schema") != SNAPSHOT_SCHEMA:
        raise LifecycleError("snapshot schema mismatch")
    if snapshot.get("host") != EXPECTED_HOST:
        raise LifecycleError("host identity mismatch")

    for spec in (*RETIRE_SPECS, *KEEP_SPECS):
        _assert_container_identity(snapshot, spec, must_run=True)

    collections = snapshot.get("qdrant_collections", [])
    if not isinstance(collections, list):
        raise LifecycleError("qdrant_collections must be an array")
    if set(map(str, collections)) != EXPECTED_QDRANT_COLLECTIONS:
        raise LifecycleError("Qdrant collection drift; exclusivity is not proven")

    timers = _require_mapping(snapshot.get("timers"), "timers")
    timer = _require_mapping(
        timers.get("mbfd-owui-rag-sync.timer"), "mbfd-owui-rag-sync.timer"
    )
    if timer.get("enabled") != "enabled" or timer.get("active") != "active":
        raise LifecycleError("OpenWebUI RAG timer state drift")

    hashes = _require_mapping(snapshot.get("file_hashes"), "file_hashes")
    for path, expected in EXPECTED_FILE_HASHES.items():
        if hashes.get(path) != expected:
            raise LifecycleError(f"source hash drift: {path}")

    connection_files = snapshot.get("connection_files", [])
    if not isinstance(connection_files, list):
        raise LifecycleError("connection_files must be an array")
    if set(map(str, connection_files)) != EXPECTED_CONNECTION_FILES:
        raise LifecycleError("OpenWebUI connection-file inventory drift")
    if snapshot.get("connection_key_present") is not True:
        raise LifecycleError("OpenWebUI connection-key inventory drift")

    listeners = _require_mapping(snapshot.get("listeners"), "listeners")
    for name in RETIRE_LISTENERS:
        if listeners.get(name) is not True:
            raise LifecycleError(f"retirement listener drift: {name}")

    _assert_preserved_volumes(snapshot)

    paths = _require_mapping(snapshot.get("paths"), "paths")
    for path in ONLYOFFICE_AI_MOUNTS:
        if paths.get(path) is not True:
            raise LifecycleError(f"expected OnlyOffice AI seed is absent: {path}")

    office_source = _require_mapping(snapshot.get("office_source"), "office_source")
    if office_source.get("head") != EXPECTED_OFFICE_SOURCE_SHA:
        raise LifecycleError("office document agent source SHA drift")
    if office_source.get("dirty_paths") != []:
        raise LifecycleError("office document agent source is dirty")

    open_webui_pairs = _mount_pairs(_container(snapshot, "open-webui"))
    if (
        "/mnt/mbfd-storage/docker-data/volumes/mbfd-ai_openwebui_data/_data",
        "/app/backend/data",
    ) not in open_webui_pairs:
        raise LifecycleError("OpenWebUI data mount drift")

    qdrant_pairs = _mount_pairs(_container(snapshot, "qdrant"))
    if (
        "/mnt/mbfd-storage/docker-data/volumes/mbfd-ai_qdrant_data/_data",
        "/qdrant/storage",
    ) not in qdrant_pairs:
        raise LifecycleError("Qdrant data mount drift")

    office_pairs = _mount_pairs(_container(snapshot, "mbfd-office-doc-agent"))
    expected_office_destinations = {"/app/data", "/app/data/db", "/app/logs"}
    actual_office_destinations = {destination for _, destination in office_pairs}
    if not expected_office_destinations.issubset(actual_office_destinations):
        raise LifecycleError("office document agent mount drift")

    onlyoffice_pairs = _mount_pairs(_container(snapshot, "mbfd-onlyoffice"))
    for source, destination in ONLYOFFICE_AI_MOUNTS.items():
        if (source, destination) not in onlyoffice_pairs:
            raise LifecycleError(f"OnlyOffice AI mount drift: {source}")


def validate_postcheck(snapshot: dict[str, Any]) -> None:
    """Validate the bounded stopped state while rollback data remains."""

    if snapshot.get("schema") != SNAPSHOT_SCHEMA:
        raise LifecycleError("snapshot schema mismatch")
    if snapshot.get("host") != EXPECTED_HOST:
        raise LifecycleError("host identity mismatch")

    containers = _require_mapping(snapshot.get("containers"), "containers")
    for spec in RETIRE_SPECS:
        value = containers.get(spec.name)
        if value is None:
            continue
        container = _require_mapping(value, f"container {spec.name}")
        if container.get("running") is True:
            raise LifecycleError(f"retired container is still running: {spec.name}")
        if container.get("restart") != "no":
            raise LifecycleError(
                f"retired container automatic restart remains enabled: {spec.name}"
            )

    for spec in KEEP_SPECS:
        _assert_container_identity(snapshot, spec, must_run=True)

    timers = _require_mapping(snapshot.get("timers"), "timers")
    timer = _require_mapping(
        timers.get("mbfd-owui-rag-sync.timer"), "mbfd-owui-rag-sync.timer"
    )
    if timer.get("active") != "inactive" or timer.get("enabled") not in {
        "disabled",
        "not-found",
    }:
        raise LifecycleError("OpenWebUI RAG timer can still start automatically")

    _assert_preserved_volumes(snapshot)

    onlyoffice_pairs = _mount_pairs(_container(snapshot, "mbfd-onlyoffice"))
    for source, destination in ONLYOFFICE_AI_MOUNTS.items():
        if (source, destination) in onlyoffice_pairs:
            raise LifecycleError(f"OnlyOffice AI mount remains active: {source}")

    paths = _require_mapping(snapshot.get("paths"), "paths")
    for path in ONLYOFFICE_AI_MOUNTS:
        if paths.get(path) is not False:
            raise LifecycleError(f"unsafe OnlyOffice AI seed remains: {path}")

    connection_files = snapshot.get("connection_files", [])
    if not isinstance(connection_files, list):
        raise LifecycleError("connection_files must be an array")
    if connection_files:
        raise LifecycleError("OpenWebUI connection blob remains on disk")
    if snapshot.get("connection_key_present") is not False:
        raise LifecycleError("OpenWebUI connection blob remains in container config")

    listeners = _require_mapping(snapshot.get("listeners"), "listeners")
    for name in RETIRE_LISTENERS:
        if listeners.get(name) is not False:
            raise LifecycleError(f"retired listener remains open: {name}")


def scrub_onlyoffice_ai_mounts(compose_text: str) -> str:
    """Remove exactly the three frozen browser-AI bind mounts.

    The function refuses unknown references, duplicates, or omissions.  It does
    not parse or alter any environment values and leaves every core OnlyOffice
    volume untouched.
    """

    expected_lines: dict[str, str] = {}
    for source, destination in ONLYOFFICE_AI_MOUNTS.items():
        expected_lines[source] = f"{source}:{destination}:ro"

    matches: dict[str, list[int]] = {source: [] for source in expected_lines}
    unexpected: list[int] = []
    lines = compose_text.splitlines(keepends=True)
    for index, line in enumerate(lines):
        if "/onlyoffice-ai/" not in line:
            continue
        normalized = line.strip()
        if normalized.startswith("-"):
            normalized = normalized[1:].strip()
        normalized = normalized.strip("\"'")
        matched_source = None
        for source, expected in expected_lines.items():
            if normalized == expected:
                matched_source = source
                break
        if matched_source is None:
            unexpected.append(index + 1)
        else:
            matches[matched_source].append(index)

    if unexpected:
        raise LifecycleError(
            "unexpected OnlyOffice AI reference at line(s): "
            + ", ".join(map(str, unexpected))
        )
    for source, indexes in matches.items():
        if len(indexes) != 1:
            raise LifecycleError(
                f"expected exactly one OnlyOffice AI mount for {source}; "
                f"found {len(indexes)}"
            )

    remove_indexes = {indexes[0] for indexes in matches.values()}
    return "".join(
        line for index, line in enumerate(lines) if index not in remove_indexes
    )


def build_release_plan(archive_root: str) -> list[PlanStep]:
    normalized = archive_root.rstrip("/")
    if not normalized.startswith(f"{ARCHIVE_PARENT}/"):
        raise LifecycleError(f"archive root must be a unique child of {ARCHIVE_PARENT}")

    keep_names = ", ".join(spec.name for spec in KEEP_SPECS)
    return [
        PlanStep(
            1,
            "Exact preflight",
            "Capture and validate secret-free container, mount, timer, source, "
            "volume, and Qdrant metadata. Recheck every conditional tool caller.",
            False,
            "All frozen hashes and exclusive-owner facts still match.",
        ),
        PlanStep(
            2,
            "Protected snapshot",
            f"Create root-only archive {normalized}; save safe config metadata, "
            "an online SQLite backup, Qdrant native snapshots, office data, and "
            "checksums. Exclude every env file and connection blob.",
            True,
            "Archive verifies and contains no exposed credential material.",
        ),
        PlanStep(
            3,
            "Disable polling",
            "Archive safe RAG-sync source metadata, then disable and stop "
            "mbfd-owui-rag-sync.timer before any OpenWebUI stop.",
            True,
            "Timer is inactive and disabled; snapshot already passed.",
        ),
        PlanStep(
            4,
            "Stop OpenWebUI-owned runtime",
            "Set automatic restart to no, stop OpenWebUI, then stop Qdrant, Tika, "
            "MCPO, office agent, doc-generator, Piper, and general whisper. Stop "
            "the three internal tools only after the final caller recheck.",
            True,
            "No compose down, volume removal, image pruning, or network removal.",
        ),
        PlanStep(
            5,
            "Remove unsafe OnlyOffice AI surface",
            "Generate and validate a Compose candidate with exactly three AI bind "
            "mounts removed; recreate only mbfd-onlyoffice; verify core document "
            "editing; then delete the three unsafe seed files without archiving "
            "their contents.",
            True,
            "Nextcloud and core OnlyOffice volumes remain untouched.",
        ),
        PlanStep(
            6,
            "Credential retirement",
            "Delete the TOOL_SERVER_CONNECTIONS source in the active OpenWebUI "
            "connection environment and all reviewed historical copies after "
            "recording only hashes/key names. Explicitly rotate or retire the "
            "nextcloud-user-fs, doc-generator, nextcloud-write, and "
            "media-control-tools credentials. Any rollback requires new "
            "credentials at every restored service and retained caller.",
            True,
            "No compromised credential or browser bearer is restored.",
        ),
        PlanStep(
            7,
            "Regression and postcheck",
            f"Prove retained services are still running: {keep_names}. After the "
            "stop-regression gate, remove only the exact retired containers so "
            "credential-bearing Docker metadata cannot survive; prove retired "
            "listeners and connection files are absent and every rollback volume "
            "remains present.",
            True,
            "Postcheck passes before any image, network, or volume is removed.",
        ),
    ]


def validate_release_plan(plan: Iterable[PlanStep]) -> None:
    steps = list(plan)
    if [step.number for step in steps] != list(range(1, len(steps) + 1)):
        raise LifecycleError("release plan numbering is not contiguous")
    rendered = json.dumps([step.as_dict() for step in steps]).lower()
    forbidden = (
        "docker compose down",
        "docker volume rm",
        "docker system prune",
        "docker builder prune",
        "rm -rf /mnt/mbfd-storage",
    )
    for fragment in forbidden:
        if fragment in rendered:
            raise LifecycleError(f"unsafe broad action in release plan: {fragment}")
    for spec in KEEP_SPECS:
        if spec.name.lower() not in rendered:
            raise LifecycleError(f"retained service omitted from plan: {spec.name}")
    for required in (
        "tool_server_connections",
        "nextcloud-user-fs",
        "doc-generator",
        "nextcloud-write",
        "media-control-tools",
        "new credentials",
    ):
        if required not in rendered:
            raise LifecycleError(f"credential boundary omitted from plan: {required}")


class Runner:
    def run(self, argv: Sequence[str], *, allow_failure: bool = False) -> str:
        try:
            completed = subprocess.run(
                list(argv),
                check=not allow_failure,
                capture_output=True,
                text=True,
                encoding="utf-8",
            )
        except FileNotFoundError as exc:
            raise LifecycleError(f"required command is unavailable: {argv[0]}") from exc
        except subprocess.CalledProcessError as exc:
            raise LifecycleError(
                f"command failed without safe output: {argv[0]} (exit {exc.returncode})"
            ) from exc
        # Preserve leading Git porcelain status columns while dropping only
        # trailing command record separators.
        return completed.stdout.rstrip()


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _normalize_container(raw: dict[str, Any]) -> dict[str, Any]:
    mounts = []
    for mount in raw.get("mounts", []) or []:
        if not isinstance(mount, dict):
            continue
        mounts.append(
            {
                "type": mount.get("Type", ""),
                "source": mount.get("Source", ""),
                "destination": mount.get("Destination", ""),
                "rw": bool(mount.get("RW", False)),
            }
        )
    networks = (raw.get("networks", {}) or {}).keys()
    return {
        "name": str(raw.get("name", "")).lstrip("/"),
        "running": bool(raw.get("running", False)),
        "health": raw.get("health", "not-configured"),
        "restart": raw.get("restart", ""),
        "project": raw.get("project", ""),
        "service": raw.get("service", ""),
        "mounts": mounts,
        "networks": sorted(networks),
    }


def _timer_state(runner: Runner, unit: str) -> dict[str, str]:
    enabled = runner.run(["systemctl", "is-enabled", unit], allow_failure=True)
    active = runner.run(["systemctl", "is-active", unit], allow_failure=True)
    return {
        "enabled": enabled.splitlines()[0] if enabled else "not-found",
        "active": active.splitlines()[0] if active else "inactive",
    }


def _qdrant_collections() -> tuple[bool, list[str]]:
    try:
        with urllib.request.urlopen(
            "http://127.0.0.1:6333/collections", timeout=5
        ) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except (OSError, urllib.error.URLError, json.JSONDecodeError):
        return False, []
    collections = payload.get("result", {}).get("collections", [])
    names = sorted(
        str(item.get("name"))
        for item in collections
        if isinstance(item, dict) and item.get("name")
    )
    return True, names


def _listener_open(port: int) -> bool:
    try:
        with socket.create_connection(("127.0.0.1", port), timeout=2):
            return True
    except OSError:
        return False


def collect_snapshot(runner: Runner | None = None) -> dict[str, Any]:
    """Collect only allowlisted metadata; never read container environments."""

    runner = runner or Runner()
    all_names = [spec.name for spec in (*RETIRE_SPECS, *KEEP_SPECS)]
    listed = set(
        filter(
            None,
            runner.run(["docker", "ps", "-a", "--format", "{{.Names}}"])
            .strip()
            .splitlines(),
        )
    )
    present_names = [name for name in all_names if name in listed]
    containers: dict[str, Any] = {}
    if present_names:
        safe_output = runner.run(
            [
                "docker",
                "inspect",
                "--format",
                SAFE_DOCKER_INSPECT_FORMAT,
                *present_names,
            ]
        )
        for line in safe_output.splitlines():
            item = _require_mapping(json.loads(line), "safe docker inspect record")
            normalized = _normalize_container(item)
            containers[normalized["name"]] = normalized

    volumes = sorted(
        filter(
            None,
            runner.run(["docker", "volume", "ls", "--format", "{{.Name}}"])
            .strip()
            .splitlines(),
        )
    )
    qdrant_reachable, qdrant_collections = _qdrant_collections()

    file_hashes = {
        path: _sha256(Path(path))
        for path in EXPECTED_FILE_HASHES
        if Path(path).is_file()
    }
    paths = {path: Path(path).is_file() for path in ONLYOFFICE_AI_MOUNTS}
    connection_files = sorted(
        str(path)
        for path in Path("/opt/ai-stack").glob("openwebui-extras.env*")
        if path.is_file()
    )
    connection_key_present = False
    if "open-webui" in listed:
        env_keys = runner.run(
            ["docker", "inspect", "--format", SAFE_ENV_KEY_FORMAT, "open-webui"]
        ).splitlines()
        connection_key_present = "TOOL_SERVER_CONNECTIONS" in env_keys
    listeners = {name: _listener_open(port) for name, port in RETIRE_LISTENERS.items()}

    office_root = "/opt/mbfd-office-doc-agent"
    office_head = runner.run(
        [
            "git",
            "-c",
            f"safe.directory={office_root}",
            "-C",
            office_root,
            "rev-parse",
            "HEAD",
        ],
        allow_failure=True,
    )
    status = runner.run(
        [
            "git",
            "-c",
            f"safe.directory={office_root}",
            "-C",
            office_root,
            "status",
            "--porcelain=v1",
            "--untracked-files=all",
        ],
        allow_failure=True,
    )
    dirty_paths = sorted(line[3:] for line in status.splitlines() if len(line) >= 4)

    return {
        "schema": SNAPSHOT_SCHEMA,
        "host": runner.run(["hostname"]),
        "containers": containers,
        "qdrant_reachable": qdrant_reachable,
        "qdrant_collections": qdrant_collections,
        "timers": {
            "mbfd-owui-rag-sync.timer": _timer_state(runner, "mbfd-owui-rag-sync.timer")
        },
        "file_hashes": file_hashes,
        "connection_files": connection_files,
        "connection_key_present": connection_key_present,
        "listeners": listeners,
        "volumes": volumes,
        "paths": paths,
        "office_source": {
            "head": office_head,
            "dirty_paths": dirty_paths,
        },
    }


def _write_json(path: Path, payload: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(
        prefix=f".{path.name}.", dir=str(path.parent)
    )
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(payload, handle, indent=2, sort_keys=True)
            handle.write("\n")
        os.replace(temporary, path)
    except BaseException:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
        raise


def _load_json(path: Path) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise LifecycleError(f"cannot read snapshot: {path}") from exc
    return _require_mapping(payload, "snapshot")


def _command_capture(args: argparse.Namespace) -> int:
    snapshot = collect_snapshot()
    if args.output:
        _write_json(Path(args.output), snapshot)
    else:
        json.dump(snapshot, sys.stdout, indent=2, sort_keys=True)
        sys.stdout.write("\n")
    print("LANE_C_SECRET_FREE_CAPTURE=PASS", file=sys.stderr)
    return 0


def _command_validate(args: argparse.Namespace) -> int:
    snapshot = _load_json(Path(args.input)) if args.input else collect_snapshot()
    if args.phase == "preflight":
        validate_preflight(snapshot)
        print("LANE_C_RETIREMENT_PREFLIGHT=PASS")
    else:
        validate_postcheck(snapshot)
        print("LANE_C_RETIREMENT_POSTCHECK=PASS")
    if args.output:
        _write_json(Path(args.output), snapshot)
    return 0


def _command_plan(args: argparse.Namespace) -> int:
    plan = build_release_plan(args.archive_root)
    validate_release_plan(plan)
    if args.json:
        json.dump([step.as_dict() for step in plan], sys.stdout, indent=2)
        sys.stdout.write("\n")
    else:
        for step in plan:
            print(f"{step.number}. {step.title}: {step.action}")
            print(f"   Gate: {step.gate}")
        print("LANE_C_RELEASE_PLAN=PASS")
    return 0


def _command_scrub(args: argparse.Namespace) -> int:
    source = Path(args.input)
    destination = Path(args.output)
    if source.resolve() == destination.resolve():
        raise LifecycleError("refusing in-place Compose mutation")
    if not args.expected_sha256:
        raise LifecycleError("--expected-sha256 is required")
    actual_hash = _sha256(source)
    if actual_hash != args.expected_sha256:
        raise LifecycleError("OnlyOffice Compose input hash drift")
    cleaned = scrub_onlyoffice_ai_mounts(source.read_text(encoding="utf-8"))
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(cleaned, encoding="utf-8", newline="\n")
    print("ONLYOFFICE_AI_MOUNT_SCRUB_CANDIDATE=PASS")
    print(f"OUTPUT_SHA256={_sha256(destination)}")
    return 0


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Secret-free MBFD AI Lane C lifecycle guard"
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    capture = subparsers.add_parser("capture", help="capture allowlisted metadata")
    capture.add_argument("--output")
    capture.set_defaults(func=_command_capture)

    validate = subparsers.add_parser(
        "validate", help="validate live or previously captured metadata"
    )
    validate.add_argument("--phase", choices=("preflight", "postcheck"), required=True)
    validate.add_argument("--input")
    validate.add_argument("--output")
    validate.set_defaults(func=_command_validate)

    plan = subparsers.add_parser("plan", help="render the bounded execution order")
    plan.add_argument("--archive-root", required=True)
    plan.add_argument("--json", action="store_true")
    plan.set_defaults(func=_command_plan)

    scrub = subparsers.add_parser(
        "scrub-onlyoffice-compose",
        help="write a candidate with exactly the three unsafe AI mounts removed",
    )
    scrub.add_argument("--input", required=True)
    scrub.add_argument("--output", required=True)
    scrub.add_argument("--expected-sha256", required=True)
    scrub.set_defaults(func=_command_scrub)
    return parser


def _main(argv: list[str] | None = None) -> int:
    args = _build_parser().parse_args(argv)
    try:
        return int(args.func(args))
    except (LifecycleError, OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"FATAL: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(_main())
