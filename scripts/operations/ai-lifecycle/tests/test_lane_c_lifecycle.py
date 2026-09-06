from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
from pathlib import Path

import pytest

HELPER = Path(__file__).resolve().parents[1] / "lane_c_lifecycle.py"


def _load():
    spec = importlib.util.spec_from_file_location("lane_c_lifecycle", HELPER)
    module = importlib.util.module_from_spec(spec)
    sys.modules["lane_c_lifecycle"] = module
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


mod = _load()


def _mount(source: str, destination: str, *, rw: bool = True) -> dict[str, object]:
    return {
        "type": "bind" if source.startswith("/") else "volume",
        "source": source,
        "destination": destination,
        "rw": rw,
    }


def _container(spec, *, running: bool = True) -> dict[str, object]:
    mounts: list[dict[str, object]] = []
    if spec.name == "open-webui":
        mounts.append(
            _mount(
                "/mnt/mbfd-storage/docker-data/volumes/mbfd-ai_openwebui_data/_data",
                "/app/backend/data",
            )
        )
    elif spec.name == "qdrant":
        mounts.append(
            _mount(
                "/mnt/mbfd-storage/docker-data/volumes/mbfd-ai_qdrant_data/_data",
                "/qdrant/storage",
            )
        )
    elif spec.name == "mbfd-office-doc-agent":
        mounts.extend(
            [
                _mount(
                    "mbfd-office-doc-agent-data",
                    "/app/data",
                ),
                _mount(
                    "mbfd-office-doc-agent-db",
                    "/app/data/db",
                ),
                _mount(
                    "mbfd-office-doc-agent-logs",
                    "/app/logs",
                ),
            ]
        )
    elif spec.name == "mbfd-onlyoffice":
        mounts.extend(
            _mount(source, destination, rw=False)
            for source, destination in mod.ONLYOFFICE_AI_MOUNTS.items()
        )
    return {
        "name": spec.name,
        "running": running,
        "health": "healthy",
        "restart": "unless-stopped",
        "project": spec.project,
        "service": spec.service,
        "mounts": mounts,
        "networks": [],
    }


def _preflight_snapshot() -> dict[str, object]:
    specs = (*mod.RETIRE_SPECS, *mod.KEEP_SPECS)
    return {
        "schema": mod.SNAPSHOT_SCHEMA,
        "host": "mbfdhub",
        "containers": {spec.name: _container(spec) for spec in specs},
        "qdrant_collections": sorted(mod.EXPECTED_QDRANT_COLLECTIONS),
        "timers": {
            "mbfd-owui-rag-sync.timer": {
                "enabled": "enabled",
                "active": "active",
            }
        },
        "file_hashes": dict(mod.EXPECTED_FILE_HASHES),
        "connection_files": sorted(mod.EXPECTED_CONNECTION_FILES),
        "connection_key_present": True,
        "listeners": {name: True for name in mod.RETIRE_LISTENERS},
        "volumes": sorted(mod.PRESERVED_VOLUMES),
        "paths": {path: True for path in mod.ONLYOFFICE_AI_MOUNTS},
        "office_source": {
            "head": mod.EXPECTED_OFFICE_SOURCE_SHA,
            "dirty_paths": [],
        },
    }


def _post_snapshot() -> dict[str, object]:
    snapshot = _preflight_snapshot()
    containers = snapshot["containers"]
    assert isinstance(containers, dict)
    for spec in mod.RETIRE_SPECS:
        containers[spec.name]["running"] = False
        containers[spec.name]["restart"] = "no"
    onlyoffice = containers["mbfd-onlyoffice"]
    onlyoffice["mounts"] = []
    snapshot["timers"] = {
        "mbfd-owui-rag-sync.timer": {
            "enabled": "disabled",
            "active": "inactive",
        }
    }
    snapshot["paths"] = {path: False for path in mod.ONLYOFFICE_AI_MOUNTS}
    snapshot["connection_files"] = []
    snapshot["connection_key_present"] = False
    snapshot["listeners"] = {name: False for name in mod.RETIRE_LISTENERS}
    return snapshot


def _onlyoffice_compose() -> str:
    lines = [
        "services:",
        "  onlyoffice:",
        "    image: onlyoffice/documentserver:9.3.1.2",
        "    volumes:",
    ]
    lines.extend(
        f'      - "{source}:{destination}:ro"'
        for source, destination in mod.ONLYOFFICE_AI_MOUNTS.items()
    )
    lines.extend(
        [
            "      - mbfd-onlyoffice-data:/var/www/onlyoffice/Data",
            "  nextcloud:",
            "    image: nextcloud:stable",
            "",
        ]
    )
    return "\n".join(lines)


def test_preflight_accepts_exact_frozen_inventory():
    mod.validate_preflight(_preflight_snapshot())


def test_preflight_rejects_retained_service_not_running():
    snapshot = _preflight_snapshot()
    snapshot["containers"]["cmd-whisper"]["running"] = False
    with pytest.raises(mod.LifecycleError, match="retained container cmd-whisper"):
        mod.validate_preflight(snapshot)


def test_preflight_rejects_new_qdrant_collection():
    snapshot = _preflight_snapshot()
    snapshot["qdrant_collections"].append("shared-production-data")
    with pytest.raises(mod.LifecycleError, match="Qdrant collection drift"):
        mod.validate_preflight(snapshot)


def test_preflight_rejects_compose_hash_drift():
    snapshot = _preflight_snapshot()
    path = "/opt/ai-stack/docker-compose.yml"
    snapshot["file_hashes"][path] = "0" * 64
    with pytest.raises(mod.LifecycleError, match="hash drift"):
        mod.validate_preflight(snapshot)


def test_preflight_rejects_connection_file_inventory_drift():
    snapshot = _preflight_snapshot()
    snapshot["connection_files"].append("/opt/ai-stack/openwebui-extras.env.unknown")
    with pytest.raises(mod.LifecycleError, match="connection-file inventory drift"):
        mod.validate_preflight(snapshot)


def test_preflight_rejects_missing_retirement_listener():
    snapshot = _preflight_snapshot()
    snapshot["listeners"]["open-webui"] = False
    with pytest.raises(mod.LifecycleError, match="listener drift"):
        mod.validate_preflight(snapshot)


def test_preflight_rejects_missing_office_volume_mount():
    snapshot = _preflight_snapshot()
    snapshot["containers"]["mbfd-office-doc-agent"]["mounts"] = []
    with pytest.raises(mod.LifecycleError, match="office document agent mount drift"):
        mod.validate_preflight(snapshot)


def test_postcheck_accepts_stopped_retirement_set_and_retained_data():
    mod.validate_postcheck(_post_snapshot())


def test_postcheck_rejects_onlyoffice_ai_mount_left_active():
    snapshot = _post_snapshot()
    source, destination = next(iter(mod.ONLYOFFICE_AI_MOUNTS.items()))
    snapshot["containers"]["mbfd-onlyoffice"]["mounts"] = [
        _mount(source, destination, rw=False)
    ]
    with pytest.raises(mod.LifecycleError, match="OnlyOffice AI mount remains"):
        mod.validate_postcheck(snapshot)


def test_postcheck_rejects_deleted_rollback_volume():
    snapshot = _post_snapshot()
    snapshot["volumes"].remove("mbfd-ai_openwebui_data")
    with pytest.raises(mod.LifecycleError, match="preserved volume missing"):
        mod.validate_postcheck(snapshot)


def test_postcheck_rejects_surviving_connection_blob():
    snapshot = _post_snapshot()
    snapshot["connection_files"] = ["/opt/ai-stack/openwebui-extras.env"]
    with pytest.raises(mod.LifecycleError, match="connection blob remains"):
        mod.validate_postcheck(snapshot)

    snapshot = _post_snapshot()
    snapshot["connection_key_present"] = True
    with pytest.raises(mod.LifecycleError, match="connection blob remains"):
        mod.validate_postcheck(snapshot)


def test_postcheck_rejects_retired_listener():
    snapshot = _post_snapshot()
    snapshot["listeners"]["office-agent"] = True
    with pytest.raises(mod.LifecycleError, match="listener remains open"):
        mod.validate_postcheck(snapshot)


def test_scrub_onlyoffice_removes_exact_three_mounts_only():
    before = _onlyoffice_compose()
    after = mod.scrub_onlyoffice_ai_mounts(before)
    assert "/onlyoffice-ai/" not in after
    assert "mbfd-onlyoffice-data:/var/www/onlyoffice/Data" in after
    assert "nextcloud:stable" in after
    assert len(before.splitlines()) - len(after.splitlines()) == 3


def test_scrub_onlyoffice_rejects_missing_mount():
    before = _onlyoffice_compose().replace(
        next(iter(mod.ONLYOFFICE_AI_MOUNTS)), "/unexpected/path"
    )
    with pytest.raises(mod.LifecycleError, match="expected exactly one"):
        mod.scrub_onlyoffice_ai_mounts(before)


def test_scrub_onlyoffice_rejects_unexpected_ai_reference():
    before = _onlyoffice_compose().replace(
        "  nextcloud:",
        "      - /opt/mbfd-workspace/onlyoffice-ai/unexpected.js:/tmp/unexpected.js:ro\n"
        "  nextcloud:",
    )
    with pytest.raises(mod.LifecycleError, match="unexpected OnlyOffice AI reference"):
        mod.scrub_onlyoffice_ai_mounts(before)


def test_scrub_onlyoffice_rejects_duplicate_mount():
    first_line = next(
        line for line in _onlyoffice_compose().splitlines() if "onlyoffice-ai" in line
    )
    before = _onlyoffice_compose().replace(first_line, f"{first_line}\n{first_line}")
    with pytest.raises(mod.LifecycleError, match="expected exactly one"):
        mod.scrub_onlyoffice_ai_mounts(before)


def test_release_plan_is_bounded_and_preserves_shared_services():
    plan = mod.build_release_plan("/mnt/mbfd-storage/backups/on-demand/example")
    mod.validate_release_plan(plan)
    rendered = json.dumps([step.as_dict() for step in plan])
    assert "docker compose down" not in rendered
    assert "docker volume rm" not in rendered
    assert "docker system prune" not in rendered
    assert "cmd-whisper" in rendered
    assert "searxng" in rendered
    assert "comfyui" in rendered
    assert "new credentials" in rendered.lower()
    assert "TOOL_SERVER_CONNECTIONS" in rendered
    for credential_owner in (
        "nextcloud-user-fs",
        "doc-generator",
        "nextcloud-write",
        "media-control-tools",
    ):
        assert credential_owner in rendered


def test_release_plan_rejects_archive_outside_on_demand_root():
    with pytest.raises(mod.LifecycleError, match="archive root"):
        mod.build_release_plan("/tmp/lane-c")


def test_snapshot_serialization_never_contains_environment_values():
    snapshot = _preflight_snapshot()
    serialized = json.dumps(snapshot).lower()
    assert "config.env" not in serialized
    assert "authorization" not in serialized
    assert "bearer" not in serialized


class FakeRunner:
    def __init__(self):
        self.calls: list[list[str]] = []

    def run(self, argv, *, allow_failure=False):
        del allow_failure
        argv = list(argv)
        self.calls.append(argv)
        if argv[:3] == ["docker", "ps", "-a"]:
            return "\n".join(spec.name for spec in (*mod.RETIRE_SPECS, *mod.KEEP_SPECS))
        if argv[:2] == ["docker", "inspect"]:
            if argv[3] == mod.SAFE_ENV_KEY_FORMAT:
                assert argv[4:] == ["open-webui"]
                return "PATH\nTOOL_SERVER_CONNECTIONS\n"
            assert argv[2] == "--format"
            assert "Config.Env" not in argv[3]
            result = []
            specs = {spec.name: spec for spec in (*mod.RETIRE_SPECS, *mod.KEEP_SPECS)}
            for name in argv[4:]:
                spec = specs[name]
                fixture = _container(spec)
                result.append(
                    {
                        "name": f"/{name}",
                        "running": True,
                        "health": "healthy",
                        "restart": "unless-stopped",
                        "project": spec.project,
                        "service": spec.service,
                        "mounts": [
                            {
                                "Type": item["type"],
                                "Source": item["source"],
                                "Destination": item["destination"],
                                "RW": item["rw"],
                            }
                            for item in fixture["mounts"]
                        ],
                        "networks": {"safe-network": {}},
                    }
                )
            return "\n".join(json.dumps(item) for item in result)
        if argv[:3] == ["docker", "volume", "ls"]:
            return "\n".join(sorted(mod.PRESERVED_VOLUMES))
        if argv[0] == "hostname":
            return "mbfdhub"
        if argv[:2] == ["systemctl", "is-enabled"]:
            return "enabled"
        if argv[:2] == ["systemctl", "is-active"]:
            return "active"
        if "rev-parse" in argv:
            return mod.EXPECTED_OFFICE_SOURCE_SHA
        if "status" in argv:
            return ""
        raise AssertionError(f"unexpected fake command: {argv}")


def test_collect_snapshot_uses_allowlisted_docker_fields(monkeypatch):
    monkeypatch.setattr(
        mod,
        "_qdrant_collections",
        lambda: (True, sorted(mod.EXPECTED_QDRANT_COLLECTIONS)),
    )
    runner = FakeRunner()
    snapshot = mod.collect_snapshot(runner)
    assert snapshot["schema"] == mod.SNAPSHOT_SCHEMA
    assert snapshot["host"] == "mbfdhub"
    assert snapshot["qdrant_reachable"] is True
    assert snapshot["volumes"] == sorted(mod.PRESERVED_VOLUMES)
    assert snapshot["containers"]["open-webui"]["health"] == "healthy"
    assert "env" not in json.dumps(snapshot).lower()
    inspect_call = next(
        call for call in runner.calls if call[:2] == ["docker", "inspect"]
    )
    assert inspect_call[2] == "--format"
    assert "Config.Env" not in inspect_call[3]
    env_key_call = next(
        call
        for call in runner.calls
        if call[:2] == ["docker", "inspect"] and call[3] == mod.SAFE_ENV_KEY_FORMAT
    )
    assert env_key_call[4:] == ["open-webui"]
    assert snapshot["connection_key_present"] is True


class FakeResponse:
    def __init__(self, payload: object):
        self.payload = json.dumps(payload).encode()

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self):
        return self.payload


def test_qdrant_collection_reader_accepts_loopback_json(monkeypatch):
    payload = {
        "result": {
            "collections": [
                {"name": "open-webui_knowledge"},
                {"name": "open-webui_files"},
            ]
        }
    }
    monkeypatch.setattr(
        mod.urllib.request, "urlopen", lambda *_a, **_kw: FakeResponse(payload)
    )
    assert mod._qdrant_collections() == (
        True,
        ["open-webui_files", "open-webui_knowledge"],
    )


def test_qdrant_collection_reader_fails_closed_without_network(monkeypatch):
    def fail(*_args, **_kwargs):
        raise OSError("offline")

    monkeypatch.setattr(mod.urllib.request, "urlopen", fail)
    assert mod._qdrant_collections() == (False, [])


def test_json_round_trip_and_offline_cli_validation(tmp_path, capsys):
    snapshot_path = tmp_path / "snapshot.json"
    mod._write_json(snapshot_path, _preflight_snapshot())
    assert mod._load_json(snapshot_path)["host"] == "mbfdhub"
    assert (
        mod._main(
            [
                "validate",
                "--phase",
                "preflight",
                "--input",
                str(snapshot_path),
            ]
        )
        == 0
    )
    assert "LANE_C_RETIREMENT_PREFLIGHT=PASS" in capsys.readouterr().out


def test_postcheck_cli_and_plan_json(tmp_path, capsys):
    snapshot_path = tmp_path / "post.json"
    output_path = tmp_path / "accepted.json"
    mod._write_json(snapshot_path, _post_snapshot())
    assert (
        mod._main(
            [
                "validate",
                "--phase",
                "postcheck",
                "--input",
                str(snapshot_path),
                "--output",
                str(output_path),
            ]
        )
        == 0
    )
    assert output_path.is_file()
    assert (
        mod._main(
            [
                "plan",
                "--archive-root",
                "/mnt/mbfd-storage/backups/on-demand/lane-c-test",
                "--json",
            ]
        )
        == 0
    )
    output = capsys.readouterr().out
    assert "Regression and postcheck" in output


def test_scrub_cli_writes_separate_hash_bound_candidate(tmp_path, capsys):
    source = tmp_path / "docker-compose.yml"
    output = tmp_path / "docker-compose.cleaned.yml"
    source.write_text(_onlyoffice_compose(), encoding="utf-8", newline="\n")
    expected = hashlib.sha256(source.read_bytes()).hexdigest()
    assert (
        mod._main(
            [
                "scrub-onlyoffice-compose",
                "--input",
                str(source),
                "--output",
                str(output),
                "--expected-sha256",
                expected,
            ]
        )
        == 0
    )
    assert output.is_file()
    assert "/onlyoffice-ai/" not in output.read_text(encoding="utf-8")
    assert "ONLYOFFICE_AI_MOUNT_SCRUB_CANDIDATE=PASS" in capsys.readouterr().out


def test_scrub_cli_rejects_in_place_and_hash_drift(tmp_path, capsys):
    source = tmp_path / "docker-compose.yml"
    source.write_text(_onlyoffice_compose(), encoding="utf-8", newline="\n")
    assert (
        mod._main(
            [
                "scrub-onlyoffice-compose",
                "--input",
                str(source),
                "--output",
                str(source),
                "--expected-sha256",
                "0" * 64,
            ]
        )
        == 1
    )
    assert "refusing in-place" in capsys.readouterr().err


def test_capture_command_can_write_injected_safe_snapshot(
    tmp_path, monkeypatch, capsys
):
    output = tmp_path / "capture.json"
    monkeypatch.setattr(mod, "collect_snapshot", _preflight_snapshot)
    args = type("Args", (), {"output": str(output)})()
    assert mod._command_capture(args) == 0
    assert mod._load_json(output)["schema"] == mod.SNAPSHOT_SCHEMA
    assert "LANE_C_SECRET_FREE_CAPTURE=PASS" in capsys.readouterr().err


def test_runner_captures_success_and_redacts_failed_command_output():
    runner = mod.Runner()
    assert runner.run([sys.executable, "-c", "print('ok')"]) == "ok"
    assert runner.run([sys.executable, "-c", "print(' M preserved-file')"]).startswith(
        " M "
    )
    with pytest.raises(mod.LifecycleError, match="exit 7"):
        runner.run([sys.executable, "-c", "raise SystemExit(7)"])


def test_load_json_rejects_invalid_payload(tmp_path):
    path = tmp_path / "bad.json"
    path.write_text("not-json", encoding="utf-8")
    with pytest.raises(mod.LifecycleError, match="cannot read snapshot"):
        mod._load_json(path)
