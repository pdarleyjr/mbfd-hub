import importlib.util
import json
import sys
import stat
import unittest
from datetime import datetime
from pathlib import Path
from unittest import mock
from types import SimpleNamespace


ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "operations" / "mbfd-origin-monitor.py"
SPEC = importlib.util.spec_from_file_location("mbfd_origin_monitor", MODULE_PATH)
assert SPEC and SPEC.loader
MONITOR = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MONITOR
SPEC.loader.exec_module(MONITOR)


class OriginMonitorTest(unittest.TestCase):
    def probe(self, name: str, ok: bool) -> object:
        return MONITOR.Probe(name, "guest-computer", ok, "Healthy" if ok else "Failed", 1, "test", name)

    def test_absent_optional_guest_is_inactive_and_informational(self) -> None:
        event = MONITOR.optional_publisher_state(
            "guest-computer",
            [self.probe("master", False), self.probe("video", False), self.probe("audio", False)],
            {},
        )

        self.assertEqual("Inactive", event["current_state"])
        self.assertEqual("informational", event["severity"])
        self.assertEqual("deduplicated", event["notification_disposition"])

    def test_partially_available_optional_guest_is_degraded(self) -> None:
        event = MONITOR.optional_publisher_state(
            "guest-computer",
            [self.probe("master", True), self.probe("video", True), self.probe("audio", False)],
            {},
        )

        self.assertEqual("Degraded", event["current_state"])
        self.assertEqual("warning", event["severity"])
        self.assertEqual("send", event["notification_disposition"])

    def test_camera_program_requires_fresh_video_tonor_audio_and_sync(self) -> None:
        now = datetime.now().astimezone().isoformat()
        payload = {
            "camera_online": True,
            "preview_online": True,
            "camera_audio_online": True,
            "sources": {
                "anpviz": {
                    "video_online": True,
                    "microphone_connected": True,
                    "audio_online": True,
                    "synchronization_status": "locked",
                    "audio_level_probe_healthy": True,
                    "last_update": now,
                    "last_audio_frame_at": now,
                }
            },
        }
        body = json.dumps(payload).encode()

        with mock.patch.object(MONITOR, "fetch", return_value=(200, body, 4, "")):
            probe = MONITOR.camera_program_probe("http://loopback/api/status")

        self.assertTrue(probe.ok)
        self.assertIn("tonor=true", probe.evidence)
        self.assertIn("sync=true", probe.evidence)

    def test_camera_program_reports_causal_audio_failures(self) -> None:
        now = datetime.now().astimezone().isoformat()
        payload = {
            "camera_online": True,
            "preview_online": True,
            "camera_audio_online": False,
            "sources": {
                "anpviz": {
                    "video_online": True,
                    "microphone_connected": False,
                    "audio_online": False,
                    "synchronization_status": "unlocked",
                    "audio_level_probe_healthy": False,
                    "last_update": now,
                    "last_audio_frame_at": now,
                }
            },
        }
        body = json.dumps(payload).encode()

        with mock.patch.object(MONITOR, "fetch", return_value=(200, body, 4, "")):
            probe = MONITOR.camera_program_probe("http://loopback/api/status")

        self.assertFalse(probe.ok)
        self.assertIn("tonor_microphone", probe.evidence)
        self.assertIn("program_audio", probe.evidence)
        self.assertIn("sync", probe.evidence)

    def test_maintenance_marker_is_root_owned_regular_and_expires(self) -> None:
        valid = SimpleNamespace(
            st_mode=stat.S_IFREG | 0o644,
            st_uid=0,
            st_mtime=1_000,
        )
        self.assertTrue(MONITOR.maintenance_metadata_valid(valid, 1_900))

        for metadata, now_epoch in (
            (SimpleNamespace(st_mode=stat.S_IFREG | 0o666, st_uid=0, st_mtime=1_000), 1_100),
            (SimpleNamespace(st_mode=stat.S_IFREG | 0o644, st_uid=1000, st_mtime=1_000), 1_100),
            (SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=0, st_mtime=1_000), 1_100),
            (valid, 1_901),
            (valid, 999),
        ):
            with self.subTest(metadata=metadata, now_epoch=now_epoch):
                self.assertFalse(
                    MONITOR.maintenance_metadata_valid(metadata, now_epoch)
                )

    def test_maintenance_marker_is_limited_to_known_services(self) -> None:
        with mock.patch.object(MONITOR.Path, "lstat") as lstat:
            self.assertFalse(MONITOR.maintenance_active("unknown-service"))
            lstat.assert_not_called()


if __name__ == "__main__":
    unittest.main()
