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

    def test_bounded_summary_uses_gateway_logical_capability(self):
        script = (WATCHDOG / "run-hermes-bounded-summary.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn("GATEWAY_URL=http://127.0.0.1:11440/api/chat", script)
        self.assertIn("CAPABILITY=mbfd-ops-summary", script)
        self.assertIn("CREDENTIALS_DIRECTORY", script)
        self.assertIn("Authorization: Bearer", script)
        self.assertIn("X-MBFD-Capability", script)
        self.assertIn("X-Request-ID", script)
        self.assertIn("--dump-header", script)
        self.assertNotIn("127.0.0.1:11434", script)
        self.assertNotIn("qwen3.6:35b", script)

    def test_bounded_summary_preserves_accepted_bounds_and_fallback(self):
        script = (WATCHDOG / "run-hermes-bounded-summary.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn("MAX_MODEL_CALLS=2", script)
        self.assertIn("REQUEST_TIMEOUT_SECONDS=36", script)
        self.assertIn("RETRY_TIMEOUT_SECONDS=6", script)
        self.assertIn("fallback_status=deterministic_report_used", script)
        self.assertIn("tools_exposed:[]", script)

    def test_deterministic_watchdog_remains_ai_independent(self):
        watchdog = (WATCHDOG / "mbfd-eoc-watchdog.py").read_text(encoding="utf-8")
        self.assertNotIn("11440", watchdog)
        self.assertNotIn("Authorization: Bearer", watchdog)
        self.assertNotIn("mbfd-ops-summary", watchdog)

    def test_bounded_summary_unit_loads_only_its_consumer_credential(self):
        unit = (
            WATCHDOG / "systemd" / "mbfd-hermes-bounded-summary.service"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "LoadCredential=ai-gateway-api-key:/etc/ollama-ai-proxy/hermes-api-key",
            unit,
        )
        self.assertIn("After=network-online.target ollama-ai-proxy.service", unit)
        self.assertNotIn("ollama-eoc.service", unit)


if __name__ == "__main__":
    unittest.main()
