import json
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WATCHDOG = ROOT / "scripts" / "operations" / "hermes-watchdog"


class HermesWatchdogPersistenceTests(unittest.TestCase):
    def test_all_managed_artifacts_exist(self):
        expected = {
            "mbfd-eoc-watchdog.py",
            "run-hermes-bounded-summary.sh",
            "install-hermes-watchdog.sh",
            "managed-config.json",
            "systemd/mbfd-eoc-source-check.service",
            "systemd/mbfd-eoc-source-check.timer",
            "systemd/mbfd-eoc-scrape-audit.service",
            "systemd/mbfd-eoc-scrape-audit.timer",
            "systemd/mbfd-hermes-bounded-summary.service",
            "systemd/mbfd-hermes-bounded-summary.timer",
        }
        actual = {
            str(path.relative_to(WATCHDOG)).replace("\\", "/")
            for path in WATCHDOG.rglob("*")
            if path.is_file() and "__pycache__" not in path.parts
        }
        self.assertEqual(expected, actual)

    def test_managed_configuration_preserves_accepted_schedule(self):
        config = json.loads((WATCHDOG / "managed-config.json").read_text(encoding="utf-8"))
        self.assertEqual(
            ["eoc-source-check", "eoc-scrape-audit", "eoc-public-brief"],
            config["legacy_hermes_cron_jobs_must_be_paused"],
        )
        bounded_timer = (WATCHDOG / "systemd" / "mbfd-hermes-bounded-summary.timer").read_text(encoding="utf-8")
        self.assertIn("OnCalendar=*-*-* 07,14,20:00:00 America/New_York", bounded_timer)

    def test_deploy_workflow_applies_exact_checkout(self):
        workflow = (ROOT / ".github" / "workflows" / "deploy.yml").read_text(encoding="utf-8")
        self.assertIn('test "$(git rev-parse HEAD)" = "$RELEASE_SHA"', workflow)
        self.assertIn("install-hermes-watchdog.sh --apply", workflow)
        self.assertIn("install-hermes-watchdog.sh --check", workflow)

    def test_no_obsolete_full_agent_invocation_in_deployable_source(self):
        deployable = [ROOT / "scripts" / "operations" / "mbfd-site-error-monitor.sh"]
        deployable.extend(
            path
            for path in WATCHDOG.rglob("*")
            if path.is_file() and "__pycache__" not in path.parts
        )
        content = "\n".join(path.read_text(encoding="utf-8") for path in deployable)
        obsolete = "timeout " + "420 hermes"
        self.assertNotIn(obsolete, content)
        self.assertNotIn("hermes --provider", content)


if __name__ == "__main__":
    unittest.main()
