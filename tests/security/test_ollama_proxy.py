"""Regression tests for the loopback-only Ollama reverse proxy boundary."""

import importlib.util
from pathlib import Path
import unittest


MODULE_PATH = (
    Path(__file__).resolve().parents[2]
    / "scripts"
    / "operations"
    / "ollama-ai-proxy.py"
)
SPEC = importlib.util.spec_from_file_location("ollama_ai_proxy", MODULE_PATH)
PROXY = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(PROXY)


class OllamaProxySafetyTest(unittest.TestCase):
    def test_loopback_origins_are_canonicalized(self):
        self.assertEqual(
            PROXY.validate_upstream("http://127.0.0.1:11434/"),
            "http://127.0.0.1:11434",
        )
        self.assertEqual(
            PROXY.validate_upstream("http://localhost:11434"),
            "http://localhost:11434",
        )
        self.assertEqual(
            PROXY.validate_upstream("http://[::1]:11434"),
            "http://[::1]:11434",
        )

    def test_non_loopback_or_ambiguous_origins_are_rejected(self):
        for value in (
            "https://127.0.0.1:11434",
            "http://192.0.2.1:11434",
            "http://127.0.0.1:11434/api",
            "http://user@127.0.0.1:11434",
            "http://localhost:11434?target=evil",
        ):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    PROXY.validate_upstream(value)

    def test_only_origin_form_request_targets_are_accepted(self):
        self.assertEqual(
            PROXY.relative_request_target("/api/generate?stream=false"),
            "/api/generate?stream=false",
        )
        for value in ("//example.test/path", "https://example.test/path", "relative"):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    PROXY.relative_request_target(value)


if __name__ == "__main__":
    unittest.main()
