#!/usr/bin/env python3
import importlib.util
import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "operations" / "hermes-watchdog" / "mbfd-eoc-watchdog.py"
SPEC = importlib.util.spec_from_file_location("watchdog", MODULE_PATH)
watchdog = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(watchdog)


class WatchdogPolicyTests(unittest.TestCase):
    def test_degraded_becomes_p1_only_after_three_checks(self):
        source = {"source_id": "cad", "state": "degraded", "message": "late"}
        state = {}
        for expected in ("P2", "P2", "P1"):
            state, notifications = watchdog.evaluate([source], state, 1000)
            self.assertEqual(expected, state["sources"]["cad"]["severity"])
        self.assertEqual("new_or_worsened", notifications[0]["reason"])

    def test_persistent_p1_is_suppressed_until_cooldown(self):
        source = {"source_id": "cad", "state": "schema_error"}
        state, first = watchdog.evaluate([source], {}, 1000)
        self.assertEqual(1, len(first))
        state, quiet = watchdog.evaluate([source], state, 1001)
        self.assertEqual([], quiet)
        _, repeated = watchdog.evaluate([source], state, 1000 + watchdog.COOLDOWN_SECONDS)
        self.assertEqual("cooldown_repeat", repeated[0]["reason"])

    def test_recovery_transition_is_not_suppressed(self):
        failed = {"source_id": "cad", "state": "schema_error"}
        state, _ = watchdog.evaluate([failed], {}, 1000)
        healthy = {"source_id": "cad", "state": "healthy"}
        _, notifications = watchdog.evaluate([healthy], state, 1001)
        self.assertEqual("RECOVERY", notifications[0]["severity"])

    def test_zero_records_is_p2_without_immediate_notification(self):
        source = {"source_id": "weather", "state": "healthy", "message": "No current records"}
        state, notifications = watchdog.evaluate([source], {}, 1000)
        self.assertEqual("P2", state["sources"]["weather"]["severity"])
        self.assertEqual([], notifications)

    def test_schema_validation_rejects_unexpected_payload(self):
        sources, error = watchdog.extract_sources({"data": "wrong"})
        self.assertEqual([], sources)
        self.assertEqual("unexpected_schema", error)


if __name__ == "__main__":
    unittest.main()
