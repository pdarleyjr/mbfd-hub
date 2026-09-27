"""Behavior checks for the host backup status marker without touching /opt/mbfd."""

import os
import pathlib
import subprocess
import tempfile
import unittest
import fcntl


SOURCE = pathlib.Path(__file__).resolve().parents[1] / "mbfd-restic-backup.sh"
CHECK_SOURCE = pathlib.Path(__file__).resolve().parents[1] / "mbfd-restic-check.sh"
ALERTS_SOURCE = pathlib.Path(__file__).resolve().parents[1] / "mbfd-alerts.sh"


class ResticBackupStatusTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name)
        (self.root / "secrets").mkdir()
        (self.root / "bin").mkdir()
        (self.root / "env").write_text(
            "RESTIC_REPOSITORY=test-repository\n"
            f'RESTIC_CACHE_DIR="{self.root / "cache"}"\n'
        )
        (self.root / "sources").write_text(str(self.root / "source") + "\n")
        (self.root / "excludes").write_text("# none\n")
        (self.root / "source").write_text("backup content\n")
        restic = self.root / "bin" / "restic"
        restic.write_text(
            "#!/bin/sh\n"
            'case "$RESTIC_TEST_MODE" in\n'
            '  fail) echo "backend unavailable" >&2; exit 1 ;;\n'
            '  locked) echo "repository is already locked" >&2; exit 1 ;;\n'
            "esac\n"
        )
        restic.chmod(0o700)

        source = SOURCE.read_text()
        source = source.replace(
            'if [ "$(id -u)" -ne 0 ]; then\n'
            '  exec /usr/bin/sudo -- /opt/mbfd/restic-backup.sh\n'
            'fi\n',
            "",
        )
        for name, value in {
            "ENV": self.root / "env",
            "SOURCES_FILE": self.root / "sources",
            "EXCLUDES_FILE": self.root / "excludes",
            "LOG": self.root / "backup.log",
            "STATUS": self.root / "secrets" / "status",
            "LOCK": self.root / "lock",
        }.items():
            source = source.replace(
                next(line for line in source.splitlines() if line.startswith(f"{name}=")),
                f'{name}="{value}"',
                1,
            )
        self.script = self.root / "backup.sh"
        self.script.write_text(source)

    def run_backup(self, mode="success"):
        env = os.environ.copy()
        env["PATH"] = f'{self.root / "bin"}:{env["PATH"]}'
        env["RESTIC_TEST_MODE"] = mode
        return subprocess.run(
            ["bash", str(self.script)], env=env, capture_output=True, text=True
        )

    def assert_status(self, prefix):
        status = self.root / "secrets" / "status"
        self.assertTrue(status.read_text().startswith(prefix))
        self.assertEqual(status.stat().st_mode & 0o777, 0o600)
        self.assertEqual(list((self.root / "secrets").glob("status.*")), [])

    def test_preflight_failures_replace_stale_success(self):
        for missing, reason in (
            ("env", "missing-env"),
            ("sources", "missing-sources-file"),
            ("excludes", "missing-excludes-file"),
        ):
            with self.subTest(missing=missing):
                (self.root / "secrets" / "status").write_text("ok yesterday\n")
                path = self.root / missing
                content = path.read_text()
                path.unlink()
                self.assertNotEqual(self.run_backup().returncode, 0)
                self.assert_status(f"FAIL ")
                self.assertIn(reason, (self.root / "secrets" / "status").read_text())
                path.write_text(content)

    def test_invalid_sources_and_command_error_fail(self):
        self.root.joinpath("sources").write_text("/missing/backup/source\n")
        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assert_status("FAIL ")
        self.assertIn("no-sources", self.root.joinpath("secrets/status").read_text())

        self.root.joinpath("sources").write_text(str(self.root / "source") + "\n")
        self.assertNotEqual(self.run_backup("fail").returncode, 0)
        self.assertIn("backup-error", self.root.joinpath("secrets/status").read_text())

    def test_failing_environment_file_does_not_preserve_old_success(self):
        self.root.joinpath("secrets/status").write_text("ok yesterday\n")
        self.root.joinpath("env").write_text("false\n")

        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assert_status("FAIL ")

    def test_log_preflight_failure_does_not_preserve_old_success(self):
        self.root.joinpath("secrets/status").write_text("ok yesterday\n")
        source = self.script.read_text().replace(
            f'LOG="{self.root / "backup.log"}"',
            f'LOG="{self.root / "missing" / "backup.log"}"',
        )
        self.script.write_text(source)

        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assert_status("FAIL ")

    def test_success_and_expected_repository_lock(self):
        self.assertEqual(self.run_backup().returncode, 0)
        self.assert_status("ok ")
        successful_status = self.root.joinpath("secrets/status").read_text()

        self.assertEqual(self.run_backup("locked").returncode, 0)
        self.assertEqual(self.root.joinpath("secrets/status").read_text(), successful_status)

        with self.root.joinpath("lock").open("w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(self.run_backup().returncode, 0)
        self.assertEqual(self.root.joinpath("secrets/status").read_text(), successful_status)

    def test_check_preflight_failure_replaces_stale_check_success(self):
        self.root.joinpath("secrets/status").write_text("ok today\n")
        self.root.joinpath("secrets/check-status").write_text("ok yesterday\n")
        self.root.joinpath("env").write_text("false\n")
        source = CHECK_SOURCE.read_text().replace(
            'if [ "$(id -u)" -ne 0 ]; then\n'
            '  exec /usr/bin/sudo -n -- /opt/mbfd/restic-check.sh "$@"\n'
            'fi\n',
            "",
        )
        for name, value in {
            "ENV": self.root / "env",
            "STATUS": self.root / "secrets" / "status",
            "CHECK_STATUS": self.root / "secrets" / "check-status",
        }.items():
            source = source.replace(
                next(line for line in source.splitlines() if line.startswith(f"{name}=")),
                f'{name}="{value}"',
                1,
            )
        script = self.root / "check.sh"
        script.write_text(source)

        result = subprocess.run(["bash", str(script)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertTrue(self.root.joinpath("secrets/check-status").read_text().startswith("repository_inaccessible "))
        self.assertEqual(self.root.joinpath("secrets/check-status").stat().st_mode & 0o777, 0o600)

        self.root.joinpath("secrets/status").write_text("FAIL today missing-env\n")
        status = subprocess.run(["bash", str(script), "--status"], capture_output=True, text=True)
        self.assertEqual(status.returncode, 1)
        self.assertEqual(status.stdout.strip(), "backup_failure")

    def test_alerts_emit_once_per_failed_backup_attempt_and_reset_on_recovery(self):
        source = ALERTS_SOURCE.read_text()
        for name, value in {
            "LOG": self.root / "alerts.log",
            "WEBHOOK_FILE": self.root / "missing-webhook",
            "BACKUP_ALERT_STATE": self.root / "secrets" / "alert-state",
            "BACKUP_STATUS": self.root / "secrets" / "status",
            "CHECK_STATUS": self.root / "secrets" / "check-status",
        }.items():
            source = source.replace(
                next(line for line in source.splitlines() if line.startswith(f"{name}=")),
                f'{name}="{value}"',
                1,
            )
        source = source.replace(
            "/opt/mbfd/restic-check.sh --status", f'{self.root / "bin" / "check-status"} --status'
        )
        script = self.root / "alerts.sh"
        script.write_text(source)
        for name, content in {
            "check-status": '#!/bin/sh\necho "$BACKUP_TEST_KIND"\n',
            "df": "#!/bin/sh\nprintf '/dev/test 10%% /\\n'\n",
            "docker": (
                "#!/bin/sh\n"
                'case "$*" in\n'
                '  *"backup-alert"*) echo called >> "$BACKUP_ALERT_CALLS" ;;\n'
                '  *"SELECT count"*) echo 0 ;;\n'
                "esac\n"
            ),
            "curl": "#!/bin/sh\necho 200\n",
            "systemctl": "#!/bin/sh\nexit 0\n",
            "journalctl": "#!/bin/sh\nexit 0\n",
        }.items():
            path = self.root / "bin" / name
            path.write_text(content)
            path.chmod(0o700)
        backup = self.root / "secrets" / "status"
        backup.write_text("FAIL today missing-env\n")
        env = os.environ.copy()
        env["PATH"] = f'{self.root / "bin"}:{env["PATH"]}'
        env["BACKUP_ALERT_CALLS"] = str(self.root / "alert-calls")

        def run(kind):
            env["BACKUP_TEST_KIND"] = kind
            return subprocess.run(["bash", str(script)], env=env, capture_output=True, text=True)

        self.assertEqual(run("backup_failure").returncode, 1)
        self.assertEqual(run("backup_failure").returncode, 1)
        self.assertEqual(self.root.joinpath("alert-calls").read_text().splitlines(), ["called"])
        self.assertEqual(self.root.joinpath("alerts.log").read_text().count("ALERT: BACKUP"), 1)
        self.assertEqual(self.root.joinpath("secrets/alert-state").stat().st_mode & 0o777, 0o600)

        os.utime(backup, (backup.stat().st_atime, backup.stat().st_mtime + 10))
        self.assertEqual(run("backup_failure").returncode, 1)
        self.assertEqual(len(self.root.joinpath("alert-calls").read_text().splitlines()), 2)
        self.assertEqual(run("ok").returncode, 0)
        self.assertFalse(self.root.joinpath("secrets/alert-state").exists())


if __name__ == "__main__":
    unittest.main()
