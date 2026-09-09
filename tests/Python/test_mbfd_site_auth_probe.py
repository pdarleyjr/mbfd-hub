import unittest
from dataclasses import dataclass

from scripts.operations import mbfd_site_auth_probe


@dataclass(frozen=True)
class FakeResponse:
    status: int
    location: str = ""


class SiteAuthContractProbeTest(unittest.TestCase):
    def setUp(self):
        self.responses = {
            "https://mbfdhub.com/up": FakeResponse(200),
            "https://www.mbfdhub.com/up": FakeResponse(200),
            "https://mbfdhub.com/login": FakeResponse(200),
            "https://www.mbfdhub.com/login": FakeResponse(200),
            "https://mbfdhub.com/": FakeResponse(302, "https://mbfdhub.com/login"),
            "https://www.mbfdhub.com/": FakeResponse(
                302, "https://www.mbfdhub.com/login"
            ),
            "https://www.mbfdhub.com/admin": FakeResponse(
                302, "https://www.mbfdhub.com/login"
            ),
            "https://www.mbfdhub.com/admin/login": FakeResponse(
                302, "https://www.mbfdhub.com/login"
            ),
        }

    def fetch(self, url):
        response = self.responses[url]
        return mbfd_site_auth_probe.ProbeResponse(
            status=response.status, location=response.location
        )

    def test_current_authentication_topology_is_healthy(self):
        results = mbfd_site_auth_probe.run_contract(self.fetch)

        self.assertTrue(all(result.ok for result in results))
        self.assertIn("application-up", {result.name for result in results})
        self.assertIn("canonical-login", {result.name for result in results})

    def test_server_errors_remain_incidents(self):
        for status in (500, 502, 503, 504):
            with self.subTest(status=status):
                self.responses["https://www.mbfdhub.com/up"] = FakeResponse(status)

                results = mbfd_site_auth_probe.run_contract(self.fetch)

                failure = next(
                    result
                    for result in results
                    if result.name == "www-application-up"
                )
                self.assertFalse(failure.ok)
                self.assertEqual(failure.reason, "unexpected_status")

    def test_bounded_planned_maintenance_downgrades_only_503(self):
        for status in (500, 502, 503, 504):
            with self.subTest(status=status):
                self.responses["https://www.mbfdhub.com/up"] = FakeResponse(status)

                results = mbfd_site_auth_probe.run_contract(
                    self.fetch, planned_maintenance=True
                )

                result = next(
                    item for item in results if item.name == "www-application-up"
                )
                self.assertEqual(result.ok, status == 503)
                self.assertEqual(
                    result.reason,
                    "planned_maintenance" if status == 503 else "unexpected_status",
                )

    def test_maintenance_marker_requires_exact_service_sha_and_bounded_window(self):
        valid = {
            "schema_version": 1,
            "service": "mbfd-hub",
            "release_sha": "a" * 40,
            "started_at_epoch": 1_000,
            "expires_at_epoch": 1_900,
        }

        self.assertTrue(mbfd_site_auth_probe.validate_marker_payload(valid, 1_500))
        for key, value in (
            ("service", "camera-hls"),
            ("release_sha", "not-a-sha"),
            ("schema_version", 2),
            ("expires_at_epoch", 1_901),
            ("expires_at_epoch", 999),
        ):
            with self.subTest(key=key, value=value):
                invalid = valid | {key: value}
                self.assertFalse(
                    mbfd_site_auth_probe.validate_marker_payload(invalid, 1_500)
                )

        self.assertFalse(mbfd_site_auth_probe.validate_marker_payload(valid, 999))
        self.assertFalse(mbfd_site_auth_probe.validate_marker_payload(valid, 1_901))

    def test_connection_failure_remains_an_incident(self):
        def failing_fetch(url):
            if url == "https://www.mbfdhub.com/up":
                raise ConnectionError("origin unavailable")
            return self.fetch(url)

        results = mbfd_site_auth_probe.run_contract(failing_fetch)

        failure = next(
            result for result in results if result.name == "www-application-up"
        )
        self.assertFalse(failure.ok)
        self.assertEqual(failure.reason, "connection_failure")

    def test_unexpected_redirect_host_and_path_are_incidents(self):
        for location in (
            "https://example.net/login",
            "https://www.mbfdhub.com/unexpected",
            "http://www.mbfdhub.com/login",
        ):
            with self.subTest(location=location):
                self.responses["https://www.mbfdhub.com/admin"] = FakeResponse(
                    302, location
                )

                results = mbfd_site_auth_probe.run_contract(self.fetch)

                failure = next(
                    result for result in results if result.name == "admin"
                )
                self.assertFalse(failure.ok)
                self.assertEqual(failure.reason, "unexpected_redirect")

    def test_direct_redirect_loop_is_an_incident(self):
        self.responses["https://www.mbfdhub.com/admin"] = FakeResponse(
            302, "https://www.mbfdhub.com/admin"
        )

        results = mbfd_site_auth_probe.run_contract(self.fetch)

        failure = next(result for result in results if result.name == "admin")
        self.assertFalse(failure.ok)
        self.assertEqual(failure.reason, "redirect_loop")

    def test_indirect_redirect_loop_is_an_incident(self):
        self.responses["https://www.mbfdhub.com/login"] = FakeResponse(
            302, "https://www.mbfdhub.com/admin"
        )

        results = mbfd_site_auth_probe.run_contract(self.fetch)

        failure = next(result for result in results if result.name == "admin")
        self.assertFalse(failure.ok)
        self.assertEqual(failure.reason, "redirect_loop")


if __name__ == "__main__":
    unittest.main()
