from __future__ import annotations

import contextlib
import hashlib
import importlib.util
import io
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

MODULE_PATH = (
    Path(__file__).parents[2] / "scripts" / "operations" / "mbfd_ai_gateway_release.py"
)
SPEC = importlib.util.spec_from_file_location("mbfd_ai_gateway_release", MODULE_PATH)
release = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = release
SPEC.loader.exec_module(release)


class GitFixture:
    def __init__(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name).resolve()
        self.operations = self.root / "scripts" / "operations"
        self.operations.mkdir(parents=True)
        self._git("init", "-b", "main")
        self._git("config", "user.email", "gateway-test@example.invalid")
        self._git("config", "user.name", "Gateway Test")
        for name in release.SOURCE_ARTIFACTS:
            (self.operations / name).write_text(f"candidate:{name}\n", encoding="utf-8")
        self.commit("initial candidate")

    def _git(self, *args: str) -> str:
        result = subprocess.run(
            ["git", *args],
            cwd=self.root,
            check=True,
            capture_output=True,
            text=True,
        )
        return result.stdout.strip()

    @property
    def head(self) -> str:
        return self._git("rev-parse", "HEAD")

    def commit(self, message: str) -> str:
        self._git("add", ".")
        self._git("commit", "-m", message)
        sha = self.head
        self._git("update-ref", "refs/remotes/origin/main", sha)
        return sha

    def state_path(self) -> Path:
        return self.root / "deployment-source.json"

    def close(self) -> None:
        self.temp.cleanup()


class TestSourceReleaseGuard(unittest.TestCase):
    def setUp(self) -> None:
        self.fixture = GitFixture()

    def tearDown(self) -> None:
        self.fixture.close()

    def validate(self, *, allow_initialize: bool = False):
        return release.validate_source(
            source_dir=self.fixture.operations,
            expected_sha=self.fixture.head,
            protected_ref="refs/remotes/origin/main",
            state_file=self.fixture.state_path(),
            allow_initialize=allow_initialize,
        )

    def test_git_calls_trust_only_the_candidate_repository(self) -> None:
        completed = subprocess.CompletedProcess(
            args=[], returncode=0, stdout="", stderr=""
        )
        with mock.patch.object(
            release.subprocess, "run", return_value=completed
        ) as run:
            release._run_git(self.fixture.operations, "status", "--porcelain=v1")

        command = run.call_args.args[0]
        self.assertEqual(
            command[:3],
            ["git", "-c", f"safe.directory={self.fixture.root}"],
        )
        self.assertNotIn("safe.directory=*", command)

    def test_initial_convergence_requires_explicit_flag(self) -> None:
        with self.assertRaisesRegex(
            release.SourceValidationError, "initial source convergence"
        ):
            self.validate()
        candidate = self.validate(allow_initialize=True)
        self.assertEqual(candidate.source_sha, self.fixture.head)

    def test_rejects_expected_sha_that_is_not_exact_head(self) -> None:
        with self.assertRaisesRegex(release.SourceValidationError, "HEAD"):
            release.validate_source(
                source_dir=self.fixture.operations,
                expected_sha="0" * 40,
                protected_ref="refs/remotes/origin/main",
                state_file=self.fixture.state_path(),
                allow_initialize=True,
            )

    def test_rejects_candidate_not_equal_to_protected_ref(self) -> None:
        previous = self.fixture.head
        (self.fixture.operations / "mbfd_ai_gateway.py").write_text(
            "new candidate\n", encoding="utf-8"
        )
        head = self.fixture.commit("new candidate")
        self.fixture._git("update-ref", "refs/remotes/origin/main", previous)
        with self.assertRaisesRegex(release.SourceValidationError, "protected ref"):
            release.validate_source(
                source_dir=self.fixture.operations,
                expected_sha=head,
                protected_ref="refs/remotes/origin/main",
                state_file=self.fixture.state_path(),
                allow_initialize=True,
            )

    def test_rejects_dirty_tracked_source(self) -> None:
        (self.fixture.operations / "mbfd_ai_gateway.py").write_text(
            "dirty candidate\n", encoding="utf-8"
        )
        with self.assertRaisesRegex(release.SourceValidationError, "tracked changes"):
            self.validate(allow_initialize=True)

    def test_rejects_non_descendant_of_deployed_source(self) -> None:
        base = self.fixture.head
        self.fixture._git("checkout", "-b", "deployed")
        (self.fixture.operations / "mbfd_ai_gateway.py").write_text(
            "deployed sibling\n", encoding="utf-8"
        )
        deployed_sha = self.fixture.commit("deployed sibling")
        self.fixture._git("checkout", "main")
        (self.fixture.operations / "mbfd_ai_gateway.py").write_text(
            "candidate sibling\n", encoding="utf-8"
        )
        candidate_sha = self.fixture.commit("candidate sibling")
        self.assertNotEqual(base, deployed_sha)
        self.fixture.state_path().write_text(
            json.dumps({"schema_version": 1, "source_sha": deployed_sha}),
            encoding="utf-8",
        )
        with self.assertRaisesRegex(release.SourceValidationError, "descendant"):
            release.validate_source(
                source_dir=self.fixture.operations,
                expected_sha=candidate_sha,
                protected_ref="refs/remotes/origin/main",
                state_file=self.fixture.state_path(),
                allow_initialize=False,
            )

    def test_accepts_descendant_of_deployed_source(self) -> None:
        deployed = self.validate(allow_initialize=True)
        state = release.build_state(deployed, deployed_at="2026-09-05T00:00:00Z")
        self.fixture.state_path().write_text(json.dumps(state), encoding="utf-8")
        (self.fixture.operations / "mbfd-ai-gateway.json").write_text(
            "descendant config\n", encoding="utf-8"
        )
        descendant_sha = self.fixture.commit("descendant")
        candidate = release.validate_source(
            source_dir=self.fixture.operations,
            expected_sha=descendant_sha,
            protected_ref="refs/remotes/origin/main",
            state_file=self.fixture.state_path(),
            allow_initialize=False,
        )
        self.assertEqual(candidate.source_sha, descendant_sha)

    def test_state_and_live_verification_are_hash_bound(self) -> None:
        candidate = self.validate(allow_initialize=True)
        state = release.build_state(candidate, deployed_at="2026-09-05T00:00:00Z")
        self.fixture.state_path().write_text(json.dumps(state), encoding="utf-8")
        live_root = self.fixture.root / "live"
        live_root.mkdir()
        live_files = {}
        for name in release.LIVE_ARTIFACTS:
            live = live_root / name
            live.write_bytes((self.fixture.operations / name).read_bytes())
            live_files[name] = live
        release.verify_live(candidate, self.fixture.state_path(), live_files)

        changed = live_files["mbfd-ai-gateway.json"]
        changed.write_text("changed live config\n", encoding="utf-8")
        with self.assertRaisesRegex(release.SourceValidationError, "live hash"):
            release.verify_live(candidate, self.fixture.state_path(), live_files)

    def test_state_records_every_persisted_artifact_without_secrets(self) -> None:
        candidate = self.validate(allow_initialize=True)
        state = release.build_state(candidate, deployed_at="2026-09-05T00:00:00Z")
        self.assertEqual(state["schema_version"], 1)
        self.assertEqual(state["source_sha"], candidate.source_sha)
        self.assertEqual(set(state["artifacts"]), set(release.SOURCE_ARTIFACTS))
        for name, digest in state["artifacts"].items():
            expected = hashlib.sha256(
                (self.fixture.operations / name).read_bytes()
            ).hexdigest()
            self.assertEqual(digest, expected)
        serialized = json.dumps(state).lower()
        self.assertNotIn("credential", serialized)
        self.assertNotIn("token", serialized)

    def test_cli_builds_state_and_verifies_live_files(self) -> None:
        common = [
            "--source-dir",
            str(self.fixture.operations),
            "--expected-sha",
            self.fixture.head,
            "--state-file",
            str(self.fixture.state_path()),
            "--allow-initialize",
        ]
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = release.main(["build-state", *common])
        self.assertEqual(result, 0)
        state = json.loads(output.getvalue())
        self.fixture.state_path().write_text(json.dumps(state), encoding="utf-8")

        live_root = self.fixture.root / "live-cli"
        live_root.mkdir()
        live_paths = {}
        for name in release.LIVE_ARTIFACTS:
            live = live_root / name
            live.write_bytes((self.fixture.operations / name).read_bytes())
            live_paths[name] = live
        verify_args = [
            "verify-live",
            "--source-dir",
            str(self.fixture.operations),
            "--expected-sha",
            self.fixture.head,
            "--state-file",
            str(self.fixture.state_path()),
            "--live-script",
            str(live_paths["mbfd_ai_gateway.py"]),
            "--live-config",
            str(live_paths["mbfd-ai-gateway.json"]),
            "--live-unit",
            str(live_paths["ollama-ai-proxy.service"]),
        ]
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = release.main(verify_args)
        self.assertEqual(result, 0)
        self.assertIn("SOURCE_LIVE_PARITY=PASS", output.getvalue())

    def test_cli_returns_fail_closed_status_for_invalid_sha(self) -> None:
        errors = io.StringIO()
        with contextlib.redirect_stderr(errors):
            result = release.main(
                [
                    "validate-source",
                    "--source-dir",
                    str(self.fixture.operations),
                    "--expected-sha",
                    "not-a-sha",
                    "--state-file",
                    str(self.fixture.state_path()),
                    "--allow-initialize",
                ]
            )
        self.assertEqual(result, 2)
        self.assertIn("SOURCE_PROVENANCE=FAIL", errors.getvalue())


class TestPersistedReleaseSurface(unittest.TestCase):
    def setUp(self) -> None:
        self.root = Path(__file__).parents[2]
        self.operations = self.root / "scripts" / "operations"

    def test_deployer_requires_exact_protected_sha_and_rolls_back_state(self) -> None:
        deployer = (self.operations / "migrate-ollama-ai-proxy.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn('EXPECTED_SOURCE_SHA="${2:', deployer)
        self.assertIn("refs/remotes/origin/main", deployer)
        self.assertIn("mbfd_ai_gateway_release.py", deployer)
        self.assertIn("validate-source", deployer)
        self.assertIn("build-state", deployer)
        self.assertIn("deployment-source.json", deployer)
        self.assertIn("--initialize-source-state", deployer)
        self.assertIn("STATE_WAS_PRESENT", deployer)
        self.assertIn('rm -f -- "${STATE_FILE}"', deployer)
        self.assertIn("deployment_failure()", deployer)
        self.assertIn("trap 'deployment_failure \"$?\"' ERR", deployer)
        self.assertIn('exit "${status}"', deployer)
        self.assertNotIn("trap 'if [[ ${deployment_started}", deployer)

    def test_verifier_checks_provenance_parity_auth_and_listener_scope(self) -> None:
        verifier = (self.operations / "verify-ollama-ai-proxy.sh").read_text(
            encoding="utf-8"
        )
        for required in (
            "validate-source",
            "verify-live",
            "mbfd-ai-gateway-smoke.py",
            "UNAUTHENTICATED_HEALTH_STATUS",
            "127.0.0.1:11440",
            "172.20.11.1:11440",
            "GATEWAY_CANONICAL_SOURCE=PASS",
            "LISTENER_WAIT_ATTEMPTS",
            "LISTENER_WAIT_INTERVAL_SECONDS",
            'sleep "${LISTENER_WAIT_INTERVAL_SECONDS}"',
        ):
            self.assertIn(required, verifier)
        self.assertNotIn("cat /etc/ollama-ai-proxy/api-key", verifier)

    def test_runbook_defines_exact_release_and_rollback_contract(self) -> None:
        runbook = (
            self.root / "docs" / "operations" / "mbfd-ai-gateway-runbook.md"
        ).read_text(encoding="utf-8")
        for required in (
            "exact 40-character `origin/main` SHA",
            "--initialize-source-state",
            "deployment-source.json",
            "verify-ollama-ai-proxy.sh",
            "Rollback",
            "11441",
            "GATEWAY_CANONICAL_SOURCE=PASS",
        ):
            self.assertIn(required, runbook)
        self.assertNotIn("paste the token", runbook.lower())


if __name__ == "__main__":
    unittest.main()
