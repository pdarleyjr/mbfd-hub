"""Deterministic swap-admission regression corpus; never pressures a real host."""

from __future__ import annotations

import concurrent.futures
import dataclasses
import unittest
from unittest import mock

from tests.security.test_mbfd_ai_gateway import ConfigFixture, FakeProbe, gateway


class TestSwapRate(unittest.TestCase):
    def sample(self, probe, now, incoming, outgoing):
        with (
            mock.patch.object(gateway.time, "monotonic", return_value=now),
            mock.patch.object(
                gateway.Path,
                "read_text",
                return_value=f"pswpin {incoming}\npswpout {outgoing}\n",
            ),
        ):
            return probe._swap_sample()

    def test_long_quiet_interval_is_not_a_burst(self):
        probe = gateway.HostHealthProbe()
        self.assertEqual(self.sample(probe, 100, 10, 20).pages_per_second, 0)
        sample = self.sample(probe, 1900, 2010, 2020)
        self.assertAlmostEqual(sample.pages_per_second, 4000 / 1800)
        self.assertEqual(sample.window_seconds, 1800)
        self.assertTrue(sample.valid)

    def test_real_burst_and_no_activity(self):
        probe = gateway.HostHealthProbe()
        self.sample(probe, 10, 0, 0)
        self.assertEqual(self.sample(probe, 11, 4000, 4000).pages_per_second, 8000)
        self.assertEqual(self.sample(probe, 12, 4000, 4000).pages_per_second, 0)

    def test_counter_reset_fails_closed_then_recovers(self):
        probe = gateway.HostHealthProbe()
        self.sample(probe, 10, 100, 200)
        reset = self.sample(probe, 11, 1, 2)
        self.assertFalse(reset.valid)
        self.assertEqual(reset.pages_per_second, 0)
        self.assertTrue(self.sample(probe, 12, 1, 2).valid)

    def test_bad_counter_or_clock_is_not_reported_as_safe_zero(self):
        for content in ["", "pswpin -1\npswpout 0", "pswpin garbage\npswpout 0"]:
            with (
                self.subTest(content=content),
                mock.patch.object(gateway.Path, "read_text", return_value=content),
            ):
                self.assertFalse(gateway.HostHealthProbe()._swap_sample().valid)
        probe = gateway.HostHealthProbe()
        self.sample(probe, 20, 0, 0)
        self.assertFalse(self.sample(probe, 19, 1, 1).valid)

    def test_concurrent_same_interval_cannot_dilute_recent_burst(self):
        probe = gateway.HostHealthProbe()
        self.sample(probe, 1, 0, 0)
        self.sample(probe, 2, 1000, 1000)
        with (
            mock.patch.object(gateway.time, "monotonic", return_value=2.1),
            mock.patch.object(
                gateway.Path, "read_text", return_value="pswpin 1000\npswpout 1000\n"
            ),
            concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor,
        ):
            samples = list(executor.map(lambda _: probe._swap_sample(), range(32)))
        self.assertTrue(all(sample.pages_per_second == 2000 for sample in samples))

    def test_uses_monotonic_not_wall_clock(self):
        with mock.patch.object(
            gateway.time, "time", side_effect=AssertionError("wall clock")
        ):
            self.assertTrue(self.sample(gateway.HostHealthProbe(), 0, 0, 0).valid)

    def test_rate_admission_low_allow_high_deny_invalid_deny(self):
        policy = gateway.HeavyWorkloadPolicy("qa", "qa", max_swap_pages_per_second=64)
        for rate, valid, deny in [
            (4000 / 1800, True, False),
            (8000, True, True),
            (0, False, True),
        ]:
            state = dataclasses.replace(
                FakeProbe().snapshot(),
                swap_pages_per_second=rate,
                swap_sample_valid=valid,
            )
            manager = gateway.HeavyLeaseManager(
                {"qa": policy}, mock.Mock(snapshot=lambda: state)
            )
            if deny:
                with (
                    self.assertRaises(gateway.GatewayError) as caught,
                    manager.acquire("qa"),
                ):
                    pass
                self.assertEqual(caught.exception.classification, "admission_denied")
                self.assertIn(
                    "swap_pages_per_second", caught.exception.admission["host"]
                )
            else:
                with manager.acquire("qa"):
                    pass

    def test_malformed_or_ambiguous_rate_config_is_rejected(self):
        fixture = ConfigFixture()
        self.addCleanup(fixture.close)
        import json

        fixture.data["heavy_workloads"]["primary-ollama-large"].pop(
            "max_swap_activity_pages", None
        )
        for value in [-1, True, "64", float("nan"), float("inf")]:
            fixture.data["heavy_workloads"]["primary-ollama-large"][
                "max_swap_pages_per_second"
            ] = value
            fixture.path.write_text(json.dumps(fixture.data), encoding="utf-8")
            with self.subTest(value=value), self.assertRaises((ValueError, TypeError)):
                gateway.load_config(fixture.path)
        fixture.data["heavy_workloads"]["primary-ollama-large"][
            "max_swap_pages_per_second"
        ] = 64
        fixture.path.write_text(json.dumps(fixture.data), encoding="utf-8")
        self.assertEqual(
            gateway.load_config(fixture.path)
            .heavy_workloads["primary-ollama-large"]
            .max_swap_pages_per_second,
            64,
        )
        fixture.data["heavy_workloads"]["primary-ollama-large"][
            "max_swap_activity_pages"
        ] = 4096
        fixture.path.write_text(json.dumps(fixture.data), encoding="utf-8")
        with self.assertRaises(ValueError):
            gateway.load_config(fixture.path)


if __name__ == "__main__":
    unittest.main()
