import importlib.util
import io
import subprocess
import sys
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
FILTER_PATH = ROOT / "scripts" / "operations" / "filter_laravel_monitor_events.py"


def load_filter_module():
    spec = importlib.util.spec_from_file_location("filter_laravel_monitor_events", FILTER_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Unable to load {FILTER_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class LaravelMonitorEventFilterTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.filter_module = load_filter_module()

    def test_retains_actionable_application_error_with_evidence_frame(self):
        log = """[2026-08-20 07:00:00] production.ERROR: Database unavailable {"exception":"[object]"}
[stacktrace]
#0 /var/www/html/vendor/example.php(1): Vendor\\Call()
#1 /var/www/html/app/Services/ImportantService.php(42): App\\Call()
#2 /var/www/html/public/index.php(17): Illuminate\\Foundation\\Application->handleRequest()
"""

        result = self.filter_module.filter_events(log)

        self.assertEqual(result.suppressed_count, 0)
        self.assertIn("Database unavailable", result.output)
        self.assertIn("app/Services/ImportantService.php", result.output)

    def test_suppresses_disposable_livekit_room_cleanup(self):
        log = """[2026-08-20 06:51:04] production.ERROR: requested room does not exist {"exception":"[object]"}
[stacktrace]
#0 /var/www/html/app/Services/VideoConferencing/LiveKitConferenceProvider.php(41): closeRoom()
#1 /tmp/video-conference-fixture.php(86): closeRoom()
"""

        result = self.filter_module.filter_events(log)

        self.assertEqual(result.output, "")
        self.assertEqual(result.suppressed_count, 1)

    def test_suppresses_expected_livekit_webhook_rejections(self):
        for message in ("Signature verification failed", "Authorization header is empty"):
            with self.subTest(message=message):
                log = f"""[2026-08-20 06:47:24] production.ERROR: {message} {{"exception":"[object]"}}
[stacktrace]
#0 /var/www/html/vendor/agence104/livekit-server-sdk/src/WebhookReceiver.php(64): verify()
#1 /var/www/html/app/Services/VideoConferencing/LiveKitConferenceProvider.php(151): verifyWebhook()
#2 /var/www/html/app/Http/Controllers/Webhooks/LiveKitWebhookController.php(16): handle()
"""

                result = self.filter_module.filter_events(log)

                self.assertEqual(result.output, "")
                self.assertEqual(result.suppressed_count, 1)

    def test_does_not_suppress_signature_failure_outside_livekit_webhook(self):
        log = """[2026-08-20 07:02:00] production.ERROR: Signature verification failed {"exception":"[object]"}
[stacktrace]
#0 /var/www/html/app/Services/EmployeeSessionService.php(55): verifySession()
"""

        result = self.filter_module.filter_events(log)

        self.assertIn("Signature verification failed", result.output)
        self.assertEqual(result.suppressed_count, 0)

    def test_suppresses_operator_psysh_parse_error(self):
        log = """[2026-08-20 03:33:22] production.ERROR: Unexpected end of input {"exception":"[object]"}
[stacktrace]
#0 /var/www/html/vendor/psy/psysh/src/Shell.php(1640): Psy\\Shell->setCode()
#1 /var/www/html/vendor/laravel/tinker/src/Console/TinkerCommand.php(77): execute()
"""

        result = self.filter_module.filter_events(log)

        self.assertEqual(result.output, "")
        self.assertEqual(result.suppressed_count, 1)

    def test_cli_reports_suppressed_count_without_an_error_keyword(self):
        log = """[2026-08-20 06:51:04] production.ERROR: requested room does not exist {"exception":"[object]"}
[stacktrace]
#0 /tmp/video-conference-fixture.php(86): closeRoom()
"""

        completed = subprocess.run(
            [sys.executable, str(FILTER_PATH)],
            input=log,
            capture_output=True,
            check=False,
            text=True,
        )

        self.assertEqual(completed.returncode, 0)
        self.assertEqual(completed.stdout.strip(), "INFO filtered_non_incident_events=1")
        self.assertEqual(completed.stderr, "")

    def test_main_reports_retained_event_and_suppression_count(self):
        log = """[2026-08-20 07:00:00] production.ERROR: Database unavailable
#0 /var/www/html/app/Services/ImportantService.php(42): run()
[2026-08-20 07:01:00] production.ERROR: A target FIFA Bronze or Gold record already exists; no duplicate was created
"""
        stdout = io.StringIO()

        with mock.patch.object(sys, "stdin", io.StringIO(log)), mock.patch.object(
            sys, "stdout", stdout
        ):
            exit_code = self.filter_module.main()

        self.assertEqual(exit_code, 0)
        self.assertIn("Database unavailable", stdout.getvalue())
        self.assertIn("INFO filtered_non_incident_events=1", stdout.getvalue())


class SiteErrorMonitorIntegrationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.monitor = (
            ROOT / "scripts" / "operations" / "mbfd-site-error-monitor.sh"
        ).read_text(encoding="utf-8")
        cls.static_analysis_workflow = (
            ROOT / ".github" / "workflows" / "06-static-analysis.yml"
        ).read_text(encoding="utf-8")

    def test_monitor_filters_complete_laravel_entries(self):
        self.assertIn("filter_laravel_monitor_events.py", self.monitor)
        self.assertNotIn("| grep -E '^[\\[]", self.monitor)

    def test_monitor_supplies_current_runtime_evidence(self):
        self.assertIn("===== RUNTIME PROBES =====", self.monitor)
        for container in (
            "mbfd-hub-laravel",
            "mbfd-hub-pgsql",
            "mbfd-hub-redis",
            "mbfd-livekit-server",
        ):
            self.assertIn(container, self.monitor)

    def test_site_monitor_uses_deterministic_evidence_without_full_agent(self):
        self.assertNotIn("timeout 420 hermes", self.monitor)
        self.assertNotIn("hermes --provider", self.monitor)
        self.assertIn("LLM invoked: false", self.monitor)
        self.assertIn("Fallback: deterministic evidence is authoritative", self.monitor)
        self.assertIn("timeout 15 sudo -u mbfd-aiops", self.monitor)

    def test_healthy_http_with_application_event_is_not_global_degraded_state(self):
        self.assertIn('echo "ApplicationEvent"', self.monitor)
        self.assertIn('write_state "${event_state}"', self.monitor)

    def test_regression_suite_runs_in_ci(self):
        self.assertIn(
            "python3 -m unittest discover -s tests/Python -p 'test_*.py' -v",
            self.static_analysis_workflow,
        )


if __name__ == "__main__":
    unittest.main()
