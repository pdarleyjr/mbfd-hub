import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CONTROLLER = ROOT / "scripts" / "operations" / "mbfd-coding-controller"


class CodingControllerPersistenceTests(unittest.TestCase):
    def test_canonical_source_package_is_complete(self):
        expected = {
            "README.md",
            "controller.py",
            "install-mbfd-coding-controller.sh",
            "mbfd-coding-controller.logrotate",
            "mbfd-coding-controller.service",
            "mbfd-coding-controller.sudoers",
            "pytest.ini",
            "requirements.txt",
            "test_controller.py",
        }
        actual = {
            path.name
            for path in CONTROLLER.iterdir()
            if path.is_file() and "__pycache__" not in path.parts
        }
        self.assertEqual(expected, actual)

    def test_openai_chat_path_cannot_bypass_coding_admission(self):
        source = (CONTROLLER / "controller.py").read_text(encoding="utf-8")
        self.assertIn('@app.post("/v1/chat/completions")', source)
        self.assertIn('return await _proxy_inference(request, "/v1/chat/completions")', source)
        catch_all = source.split("ALLOWED_PROXY_PATHS =", maxsplit=1)[1]
        catch_all = catch_all.split("@app.api_route", maxsplit=1)[0]
        self.assertNotIn("v1/chat/completions", catch_all)
        self.assertNotIn("v1/completions", catch_all)

    def test_catalog_exposes_only_the_approved_coding_model(self):
        source = (CONTROLLER / "controller.py").read_text(encoding="utf-8")
        self.assertIn('@app.get("/v1/models")', source)
        self.assertIn('"id": APPROVED_MODEL', source)
        self.assertNotIn('"v1/models",', source.split("ALLOWED_PROXY_PATHS =", 1)[1])

    def test_provider_keeps_existing_lifecycle_and_resource_guards(self):
        source = (CONTROLLER / "controller.py").read_text(encoding="utf-8")
        for required in (
            "acquire_lock()",
            "MIN_AVAILABLE_RAM_GIB",
            "MAX_SWAP_DELTA_GIB",
            "wait_model_present(APPROVED_MODEL)",
            "INACTIVITY_TIMEOUT_S",
            "await exit_coding_mode(\"inactivity\")",
            '"model": GENERAL_MODEL',
        ):
            self.assertIn(required, source)

    def test_only_bounded_ai_summary_is_suppressed_during_coding(self):
        unit = (CONTROLLER / "mbfd-coding-controller.service").read_text(
            encoding="utf-8"
        )
        self.assertIn("SUPPRESS_UNITS=mbfd-hermes-bounded-summary.timer", unit)
        self.assertNotIn("mbfd-eoc-source-check", unit)
        self.assertNotIn("mbfd-eoc-scrape-audit", unit)
        self.assertNotIn("mbfd-site-error-monitor", unit)

    def test_controller_remains_loopback_provider_until_gateway_canary(self):
        unit = (CONTROLLER / "mbfd-coding-controller.service").read_text(
            encoding="utf-8"
        )
        self.assertIn("CONTROLLER_HOST=127.0.0.1", unit)
        self.assertIn("CONTROLLER_PORT=11436", unit)
        self.assertIn("APPROVED_MODEL=mbfd-code:32k", unit)
        self.assertIn("OLLAMA_UPSTREAM=http://127.0.0.1:11434", unit)

    def test_external_coding_handoff_keeps_lifecycle_boundary_explicit(self):
        readme = (CONTROLLER / "README.md").read_text(encoding="utf-8")
        normalized = " ".join(readme.split())
        self.assertIn("external-coding", normalized)
        self.assertIn("11440 -> 11436 -> 11434", normalized)
        self.assertIn("No external coding caller may target 11436", normalized)
        self.assertIn("11436 remains required", normalized)
        self.assertIn("registry no longer references 11436", normalized)
        self.assertIn("single-session exclusivity", normalized)
        self.assertIn("virtual readiness", normalized)


if __name__ == "__main__":
    unittest.main()
