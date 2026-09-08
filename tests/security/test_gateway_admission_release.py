from __future__ import annotations

import copy
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

OPERATIONS = Path(__file__).parents[2] / "scripts" / "operations"
sys.path.insert(0, str(OPERATIONS))
SPEC = importlib.util.spec_from_file_location(
    "admission_release", OPERATIONS / "mbfd_ai_gateway_admission_release.py"
)
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


class TestScopedAdmissionRelease(unittest.TestCase):
    def test_backup_restore_restores_exact_bytes_before_restart(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            backup = root / "backup"
            backup.mkdir()
            files = {name: root / name for name in release.FILES}
            state = root / "deployment-source.json"
            for name, path in {**files, "deployment-source.json": state}.items():
                (backup / name).write_bytes(("accepted-" + name).encode())
                path.write_bytes(b"broken-candidate")
            with (
                mock.patch.object(release, "FILES", files),
                mock.patch.object(release, "STATE", state),
                mock.patch.object(release.subprocess, "run") as restart,
            ):
                release.restore_previous(backup)
                restart.assert_called_once_with(
                    ["systemctl", "restart", "ollama-ai-proxy.service"], check=True
                )
            for name, path in {**files, "deployment-source.json": state}.items():
                self.assertEqual(path.read_bytes(), (backup / name).read_bytes())
            self.assertEqual(list(root.glob(".admission-release-*")), [])

    def test_verifier_detects_code_unit_and_unrelated_topology_drift(self):
        import json

        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            files = {name: root / name for name in release.FILES}
            original = {
                "listeners": ["127.0.0.1"],
                "heavy_workloads": {
                    "prm-sports-medium": {"max_swap_activity_pages": 4096}
                },
            }
            revised = release.admission_config(original, "a" * 40)
            for path in files.values():
                path.write_bytes(b"accepted source")
            files["mbfd-ai-gateway.json"].write_text(json.dumps(revised))
            runtime = {name: release.digest(path) for name, path in files.items()}
            candidate = SimpleNamespace(
                source_sha="a" * 40,
                artifacts={"mbfd_ai_gateway.py": runtime["mbfd_ai_gateway.py"]},
            )
            state = {
                "scope": "admission-only-v1",
                "source_sha": candidate.source_sha,
                "artifacts": candidate.artifacts,
                "runtime_artifacts": runtime.copy(),
                "baseline_config_release_sha": None,
                "baseline_config_canonical_sha256": release.canonical_digest(original),
                "baseline_unit_sha256": runtime["ollama-ai-proxy.service"],
            }
            release.verify_live(candidate, state, files)
            for name, path in files.items():
                accepted = path.read_bytes()
                path.write_bytes(b"drift")
                with self.assertRaises(ValueError):
                    release.verify_live(candidate, state, files)
                path.write_bytes(accepted)
            revised["listeners"] = ["0.0.0.0"]
            files["mbfd-ai-gateway.json"].write_text(json.dumps(revised))
            state["runtime_artifacts"]["mbfd-ai-gateway.json"] = release.digest(
                files["mbfd-ai-gateway.json"]
            )
            with self.assertRaisesRegex(ValueError, "unrelated runtime topology"):
                release.verify_live(candidate, state, files)

    def test_only_sports_swap_policy_and_release_identity_change(self):
        original = {
            "listeners": ["127.0.0.1", "172.20.11.1"],
            "port": 11440,
            "consumers": {"legacy": {"credential_file": "%d/api-key"}},
            "heavy_workloads": {
                "prm-sports-medium": {
                    "max_swap_activity_pages": 4096,
                    "memory_psi_avg10_max": 1,
                    "mem_available_floor_mb": 8192,
                },
                "protected": {"max_swap_activity_pages": 1024},
            },
        }
        frozen = copy.deepcopy(original)
        revised = release.admission_config(original, "a" * 40)
        self.assertEqual(original, frozen)
        self.assertEqual(revised["consumers"], original["consumers"])
        self.assertEqual(revised["listeners"], original["listeners"])
        self.assertEqual(
            revised["heavy_workloads"]["protected"],
            original["heavy_workloads"]["protected"],
        )
        self.assertEqual(
            revised["heavy_workloads"]["prm-sports-medium"][
                "max_swap_pages_per_second"
            ],
            64,
        )
        self.assertEqual(
            release.reverse_admission_config(revised, original.get("release_sha")),
            original,
        )

    def test_unexpected_policy_or_sha_refused(self):
        for pages, sha in [(4096, "bad"), (8192, "a" * 40), (None, "a" * 40)]:
            with self.subTest(pages=pages), self.assertRaises(ValueError):
                release.admission_config(
                    {
                        "heavy_workloads": {
                            "prm-sports-medium": {"max_swap_activity_pages": pages}
                        }
                    },
                    sha,
                )

    def test_source_bound_deployer_keeps_existing_unit_and_rolls_back_on_failure(self):
        script = (OPERATIONS / "mbfd_ai_gateway_admission_release.py").read_text()
        for required in [
            "validate_source(",
            "verify_live(",
            "sha256sum",
            "restore_previous",
            "systemctl",
            "backup",
        ]:
            self.assertIn(required, script)
        self.assertNotIn("provision-mbfd-ai-gateway-consumers", script)


if __name__ == "__main__":
    unittest.main()
