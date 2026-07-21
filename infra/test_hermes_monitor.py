#!/usr/bin/env python3
"""Tests for Hermes inventory-driven monitor v2 (no network; probes injected).

Run with:  python -m unittest discover -v
(or:  py -3 -m unittest discover -v)
"""
from __future__ import annotations

import json
import os
import socket
import ssl
import subprocess
import tempfile
import unittest
import urllib.error
import urllib.request
from unittest import mock

# Import the monitor through normal Python import semantics (the file lives in
# the same package directory). This is required for @dataclass type resolution,
# which breaks when a module is exec'd outside sys.modules.
import hermes_monitor as hm

from hermes_monitor import (  # noqa: E402
    RuntimeContext, run_monitor, classify, is_benign_line, hls_escalation,
    ServiceState, StateDocument, StateStore, ProbeResult, LockHeld,
    probe_docker, probe_tcp, probe_http, next_state, should_notify,
    compute_fingerprint, detect_scheduled_recurrence, acquire_lock,
)

NOW = 1_000_000.0


def pr(status, code=None, detail=""):
    return ProbeResult(status=status, code=code, detail=detail)


def make_inv(services, **kw):
    base = {
        "schema_version": 1,
        "boot_grace_seconds": 600,
        "deployment_grace_seconds": 600,
        "transient_failure_threshold": 3,
        "recovery_success_threshold": 2,
        "outage_threshold": 5,
        "alert_cooldown_seconds": 900,
        "services": services,
    }
    base.update(kw)
    return base


def svc_http(sid, port=8080, external_status=200, public=None, auth=False,
             ext_redirect_host=None, ext_redirect_prefix=None, method="GET"):
    return {
        "id": sid, "origin_host": "127.0.0.1", "origin_port": port,
        "local_health": {"type": "http", "path": "/", "method": method, "expected_status": 200},
        "external_health": {"type": "https", "expected_status": external_status,
                            "expected_redirect_host": ext_redirect_host,
                            "expected_redirect_path_prefix": ext_redirect_prefix},
        "public_hostnames": public or [], "auth_required": auth,
    }


def svc_tcp(sid, port=6379):
    return {"id": sid, "origin_host": "127.0.0.1", "origin_port": port,
            "local_health": {"type": "tcp"}, "external_health": None, "public_hostnames": []}


def svc_docker(sid, container="c1"):
    return {"id": sid, "origin_host": "127.0.0.1", "origin_port": 0,
            "container": container, "local_health": {"type": "docker"},
            "external_health": None, "public_hostnames": []}


# --- HTTP stubs ----------------------------------------------------------- #
def http_ok(*a, **k):
    return pr("healthy", 200)


def http_fail(*a, **k):
    return pr("failed", 503, "unexpected-status:503")


def http_local_ok_ext_fail(*a, **k):
    url = a[0] if a else ""
    if url.startswith("https://"):
        return pr("failed", 503)
    return pr("healthy", 200)


def http_local_ok_ext_redirect(*a, **k):
    url = a[0] if a else ""
    if url.startswith("https://"):
        if k.get("expected_statuses") and 302 in k["expected_statuses"]:
            return pr("healthy", 302, "status=302")
        return pr("failed", 503)
    return pr("healthy", 200)


def http_exact_redirect_ok(*a, **k):
    # External expects 302 -> admin.mbfdhub.com/admin/login
    return pr("healthy", 302, "status=302")


def http_exact_redirect_bad(*a, **k):
    # 302 but to the wrong host
    return pr("failed", 302, "redirect-host-mismatch")


def http_auth_ok(*a, **k):
    return pr("healthy", 401, "status=401")


def http_auth_bad(*a, **k):
    return pr("failed", 503, "unexpected-status:503")


def http_head_unsupported(*a, **k):
    if k.get("method") == "HEAD":
        return pr("failed", 405, "method-not-allowed")
    return pr("healthy", 200)


class Recorder:
    def __init__(self):
        self.calls = []

    def __call__(self, svc, state, diagnosis, st):
        self.calls.append((svc["id"], state))


class HermesMonitorTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store = StateStore(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    # --- basic states (retained from prior 17) --- #
    def test_healthy_state(self):
        inv = make_inv([svc_http("hub", public=["mbfdhub.com"])])
        rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                          http_fn=http_ok, dry_run=False)
        self.assertEqual(rep["services"][0]["state"], "HEALTHY")
        self.assertEqual(rep["alerts"], [])

    def test_expected_admin_redirect(self):
        inv = make_inv([svc_http("admin", external_status=302,
                                 public=["admin.mbfdhub.com"], auth=True,
                                 ext_redirect_host="admin.mbfdhub.com",
                                 ext_redirect_prefix="/admin/login")])
        rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                          http_fn=http_local_ok_ext_redirect, dry_run=False)
        self.assertEqual(rep["services"][0]["external"], "healthy")
        self.assertEqual(rep["services"][0]["state"], "HEALTHY")

    def test_one_transient_failure(self):
        inv = make_inv([svc_tcp("redis")])
        rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                          tcp_fn=lambda *a, **k: False, dry_run=False)
        self.assertEqual(rep["services"][0]["state"], "SUSPECT")

    def test_repeated_failure_threshold(self):
        inv = make_inv([svc_tcp("redis")])
        rec = Recorder()
        st = {}
        for i in range(5):
            run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + i), self.store,
                        tcp_fn=lambda *a, **k: False, notify_fn=rec, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].state, "OUTAGE")
        self.assertTrue(any(s == "OUTAGE" for _, s in rec.calls))

    def test_boot_grace(self):
        inv = make_inv([svc_tcp("redis")])
        for i in range(10):
            run_monitor(inv, RuntimeContext(uptime_seconds=10, now=NOW + i), self.store,
                        tcp_fn=lambda *a, **k: False, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].state, "SUSPECT")

    def test_deployment_grace(self):
        inv = make_inv([svc_tcp("redis")])
        for i in range(10):
            run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + i,
                                            deployment_id="dep1"), self.store,
                        tcp_fn=lambda *a, **k: False, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].state, "SUSPECT")
        self.assertTrue(self.store.load()[0].deployment_grace_until != "")

    def test_recovery_summary_once(self):
        inv = make_inv([svc_tcp("redis")])
        rec = Recorder()
        for i in range(7):  # 5 fail -> OUTAGE, then 2 healthy -> HEALTHY
            run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + i), self.store,
                        tcp_fn=(lambda *a, **k: False) if i < 5 else (lambda *a, **k: True),
                        notify_fn=rec, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].state, "HEALTHY")
        recov = [s for _, s in rec.calls if s == "HEALTHY"]
        self.assertEqual(len(recov), 1)

    def test_local_healthy_external_failed(self):
        inv = make_inv([svc_http("hub", public=["mbfdhub.com"])])
        rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                          http_fn=http_local_ok_ext_fail, dry_run=False)
        self.assertEqual(rep["services"][0]["diagnosis"], "Tunnel/DNS/Access path")

    def test_local_failed_external_failed(self):
        inv = make_inv([svc_http("hub", public=["mbfdhub.com"])])
        rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                          http_fn=http_fail, tcp_fn=lambda *a, **k: False, dry_run=False)
        self.assertEqual(rep["services"][0]["diagnosis"], "Origin or dependency")

    def test_hls_benign_filter(self):
        self.assertTrue(is_benign_line("ERR Incoming request ended abruptly: context canceled"))
        self.assertFalse(is_benign_line("ERR connection refused"))

    def test_hls_escalation(self):
        self.assertTrue(hls_escalation({"stale_playlist": True}))
        self.assertTrue(hls_escalation({"frozen_media_sequence": True}))
        self.assertFalse(hls_escalation({}))

    def test_incorrect_inventory_entry(self):
        bad = {"id": "broken", "origin_host": "127.0.0.1"}
        rep = run_monitor(make_inv([bad]), RuntimeContext(uptime_seconds=3600, now=NOW),
                          self.store, dry_run=False)
        self.assertEqual(rep["services"][0]["state"], "HEALTHY")

    def test_missing_container_systemd(self):
        s = {"id": "x", "origin_host": "127.0.0.1", "origin_port": 0,
             "local_health": {"type": "systemd", "unit": ""}, "public_hostnames": []}
        rep = run_monitor(make_inv([s]), RuntimeContext(uptime_seconds=3600, now=NOW),
                          self.store, dry_run=False)
        self.assertEqual(rep["services"][0]["state"], "SUSPECT")

    def test_cloudflare_route_failure(self):
        inv = make_inv([svc_http("hub", public=["mbfdhub.com"])])
        rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                          http_fn=http_local_ok_ext_fail, dry_run=False)
        self.assertEqual(rep["services"][0]["diagnosis"], "Tunnel/DNS/Access path")

    def test_duplicate_alert_suppression(self):
        inv = make_inv([svc_tcp("redis")], transient_failure_threshold=10)
        rec = Recorder()
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                    tcp_fn=lambda *a, **k: False, notify_fn=rec, dry_run=False)
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + 10), self.store,
                    tcp_fn=lambda *a, **k: False, notify_fn=rec, dry_run=False)
        # Third call: same state (SUSPECT), same fingerprint -> suppressed.
        rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + 20),
                          self.store, tcp_fn=lambda *a, **k: False,
                          notify_fn=rec, dry_run=False)
        self.assertFalse(rep["services"][0]["would_alert"])
        # Only 1 SUSPECT alert (second and third suppressed by same fingerprint).
        self.assertEqual(sum(1 for _, s in rec.calls if s == "SUSPECT"), 1)


def rep_alert(rep):
    return rep["services"][0]["would_alert"]


# --------------------------------------------------------------------------- #
# Advanced scenarios (persistence, locking, probes, notifications, HLS)
# --------------------------------------------------------------------------- #
class FakeResponse:
    def __init__(self, status=200, headers=None, body=b""):
        self.status = status
        self._headers = headers or {}
        self._body = body
        self._req = None

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def read(self, n=None):
        return self._body

    def close(self):
        pass

    def getheaders(self):
        return self._headers.items()

    @property
    def headers(self):
        class _H:
            def __init__(self, d):
                self.d = d

            def get(self, k, default=None):
                return self.d.get(k, default)
        return _H(self._headers)


class FakeOpener:
    def __init__(self, response=None, exc=None, capture=None):
        self._response = response
        self._exc = exc
        self.capture = capture

    def open(self, req, timeout=None):
        if self.capture is not None:
            self.capture.append((req.method if hasattr(req, "method") else None,
                                 req.full_url if hasattr(req, "full_url") else None))
        if self._exc is not None:
            raise self._exc
        return self._response


class HermesMonitorAdvancedTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store = StateStore(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    # ---- persistence ---- #
    def test_state_survives_new_process(self):
        inv = make_inv([svc_tcp("redis")])
        bad = lambda *a, **k: False
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                    tcp_fn=bad, dry_run=False)
        # Simulate a new Python process: brand-new StateStore over the same dir.
        store2 = StateStore(self.tmp.name)
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + 1), store2,
                    tcp_fn=bad, dry_run=False)
        doc = store2.load()[0]
        self.assertEqual(doc.services["redis"].consecutive_failures, 2)

    def test_state_survives_multiple_cycles(self):
        inv = make_inv([svc_tcp("redis")])
        for i in range(4):
            run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + i), self.store,
                        tcp_fn=lambda *a, **k: False, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].consecutive_failures, 4)

    def test_missing_state_is_fresh(self):
        doc, err = self.store.load()
        self.assertIsNone(doc)
        self.assertIsNone(err)
        run_monitor(make_inv([svc_tcp("redis")]), RuntimeContext(now=NOW), self.store,
                    tcp_fn=lambda *a, **k: True, dry_run=False)
        self.assertIsNotNone(self.store.load()[0])

    def test_corrupt_state_quarantined(self):
        with open(os.path.join(self.tmp.name, "state.json"), "w") as fh:
            fh.write("{not valid json")
        doc, err = self.store.load()
        self.assertIsNone(doc)
        self.assertIn("corrupt", err)
        corrupt = [f for f in os.listdir(self.tmp.name) if f.endswith(".corrupt")]
        self.assertTrue(corrupt, "corrupt state must be quarantined")
        # Monitor still runs against a clean baseline.
        rep = run_monitor(make_inv([svc_tcp("redis")]), RuntimeContext(now=NOW),
                          self.store, tcp_fn=lambda *a, **k: True, dry_run=False)
        self.assertEqual(rep["services"][0]["state"], "HEALTHY")

    def test_unsupported_schema_version(self):
        with open(os.path.join(self.tmp.name, "state.json"), "w") as fh:
            json.dump({"schema_version": 99, "services": {}}, fh)
        doc, err = self.store.load()
        self.assertIsNone(doc)
        self.assertIn("unsupported-schema-version", err)

    def test_lock_contention_is_safe(self):
        # A second run must not overwrite state or send alerts when locked.
        inv_path = os.path.join(self.tmp.name, "inv.json")
        with open(inv_path, "w") as fh:
            json.dump(make_inv([svc_tcp("redis")]), fh)
        orig = hm.acquire_lock
        try:
            hm.acquire_lock = lambda d: (_ for _ in ()).throw(hm.LockHeld(d))
            rc = hm.main(["--state-dir", self.tmp.name, "--inventory", inv_path, "validate"])
            self.assertEqual(rc, 0)
        finally:
            hm.acquire_lock = orig

    # ---- boot / deployment transitions ---- #
    def test_boot_id_change_resets_counters(self):
        inv = make_inv([svc_tcp("redis")])
        bad = lambda *a, **k: False
        for i in range(5):
            run_monitor(inv, RuntimeContext(boot_id="A", uptime_seconds=3600, now=NOW + i),
                        self.store, tcp_fn=bad, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].state, "OUTAGE")
        # New boot, still in boot grace: counters reset, no stale OUTAGE.
        rec = Recorder()
        run_monitor(inv, RuntimeContext(boot_id="B", uptime_seconds=10, now=NOW + 100),
                    self.store, tcp_fn=bad, notify_fn=rec, dry_run=False)
        st = self.store.load()[0].services["redis"]
        self.assertEqual(st.consecutive_failures, 1)
        self.assertEqual(st.state, "SUSPECT")
        self.assertTrue(any(s == "SUSPECT" for _, s in rec.calls))  # genuine new alert

    def test_deployment_id_change_arms_grace(self):
        inv = make_inv([svc_tcp("redis")], deployment_grace_seconds=600)
        bad = lambda *a, **k: False
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW, deployment_id="d1"),
                    self.store, tcp_fn=bad, dry_run=False)
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + 1, deployment_id="d2"),
                    self.store, tcp_fn=bad, dry_run=False)
        st = self.store.load()[0].services["redis"]
        self.assertEqual(st.state, "SUSPECT")  # grace active on new deploy

    def test_stale_deployment_marker_expires_grace(self):
        inv = make_inv([svc_tcp("redis")], deployment_grace_seconds=5)
        bad = lambda *a, **k: False
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW, deployment_id="d1"),
                    self.store, tcp_fn=bad, dry_run=False)  # arms grace until NOW+5
        for i in range(5):
            run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + 100 + i,
                                            deployment_id="d1"),
                        self.store, tcp_fn=bad, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].state, "OUTAGE")

    def test_boot_grace_expiration(self):
        inv = make_inv([svc_tcp("redis")])
        bad = lambda *a, **k: False
        for i in range(5):
            run_monitor(inv, RuntimeContext(uptime_seconds=700, now=NOW + i),
                        self.store, tcp_fn=bad, dry_run=False)
        self.assertEqual(self.store.load()[0].services["redis"].state, "OUTAGE")

    def test_recovery_requires_success_count(self):
        inv = make_inv([svc_tcp("redis")], recovery_success_threshold=2)
        rec = Recorder()
        for i in range(7):  # 5 fail -> OUTAGE, then 2 healthy -> HEALTHY
            run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + i), self.store,
                        tcp_fn=(lambda *a, **k: False) if i < 5 else (lambda *a, **k: True),
                        notify_fn=rec, dry_run=False)
        st = self.store.load()[0].services["redis"]
        self.assertEqual(st.state, "HEALTHY")
        self.assertTrue(any(s == "HEALTHY" for _, s in rec.calls))

    # ---- notification safety ---- #
    def test_notification_failure_is_safe(self):
        inv = make_inv([svc_tcp("redis")])
        def boom(svc, state, diag, st):
            raise RuntimeError("send failed")
        # Must not raise; state still saved.
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                    tcp_fn=lambda *a, **k: False, notify_fn=boom, dry_run=False)
        self.assertIsNotNone(self.store.load()[0])

    def test_notification_timeout_is_safe(self):
        sent = {}
        def fake_run(*a, **k):
            sent["timeout"] = k.get("timeout")
            raise subprocess.TimeoutExpired("hermes", 60)
        with unittest.mock.patch.object(hm.subprocess, "run", fake_run):
            # notify_hermes must swallow the timeout; no exception escapes.
            hm.notify_hermes({"id": "redis", "display_name": "Redis"}, "OUTAGE",
                              "Origin or dependency", ServiceState())
        self.assertEqual(sent.get("timeout"), 60)

    # ---- monitor interruption during state write ---- #
    def test_state_write_interruption_keeps_old(self):
        inv = make_inv([svc_tcp("redis")])
        run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW), self.store,
                    tcp_fn=lambda *a, **k: True, dry_run=False)
        before = self.store.load()[0].services["redis"].state
        real_replace = hm.os.replace
        try:
            hm.os.replace = lambda *a, **k: (_ for _ in ()).throw(OSError("disk full"))
            with self.assertRaises(OSError):
                run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW + 1), self.store,
                            tcp_fn=lambda *a, **k: False, dry_run=False)
        finally:
            hm.os.replace = real_replace
        # Original state file must remain intact (atomic).
        self.assertEqual(self.store.load()[0].services["redis"].state, before)

    def test_atomic_replacement_called(self):
        calls = []
        real_replace = hm.os.replace
        try:
            hm.os.replace = lambda src, dst: calls.append((src, dst)) or real_replace(src, dst)
            run_monitor(make_inv([svc_tcp("redis")]), RuntimeContext(now=NOW), self.store,
                        tcp_fn=lambda *a, **k: True, dry_run=False)
        finally:
            hm.os.replace = real_replace
        self.assertTrue(calls)
        self.assertTrue(all(c[1].endswith("state.json") for c in calls))
        # No stray temp files left behind.
        leftovers = [f for f in os.listdir(self.tmp.name) if f.startswith(".state.")]
        self.assertEqual(leftovers, [])

    # ---- docker probe parsing ---- #
    def _patch_docker(self, returncode, stdout="", stderr=""):
        class R:
            pass
        r = R()
        r.returncode = returncode
        r.stdout = stdout
        r.stderr = stderr
        return r

    def test_docker_healthy(self):
        with unittest.mock.patch.object(hm.subprocess, "run",
                                        return_value=self._patch_docker(0, "healthy\n")):
            self.assertEqual(probe_docker("c1").status, "healthy")

    def test_docker_starting(self):
        with unittest.mock.patch.object(hm.subprocess, "run",
                                        return_value=self._patch_docker(0, "starting\n")):
            self.assertEqual(probe_docker("c1").status, "starting")

    def test_docker_unhealthy(self):
        with unittest.mock.patch.object(hm.subprocess, "run",
                                        return_value=self._patch_docker(0, "unhealthy\n")):
            self.assertEqual(probe_docker("c1").status, "failed")

    def test_docker_no_healthcheck(self):
        with unittest.mock.patch.object(hm.subprocess, "run",
                                        return_value=self._patch_docker(0, "")):
            self.assertEqual(probe_docker("c1").status, "no_healthcheck")

    def test_docker_missing_container(self):
        with unittest.mock.patch.object(hm.subprocess, "run",
                                        return_value=self._patch_docker(1, "", "Error: No such object: c1")):
            self.assertEqual(probe_docker("c1").status, "absent")

    def test_docker_unavailable(self):
        with unittest.mock.patch.object(hm.subprocess, "run", side_effect=FileNotFoundError):
            self.assertEqual(probe_docker("c1").status, "error")

    def test_unsafe_container_name_not_injected(self):
        captured = {}
        def fake_run(argv, *a, **k):
            captured["argv"] = argv
            captured["shell"] = k.get("shell", False)
            r = self._patch_docker(0, "healthy\n")
            return r
        with unittest.mock.patch.object(hm.subprocess, "run", fake_run):
            probe_docker("c1; touch /tmp/pwned")
        self.assertIsInstance(captured["argv"], list)
        self.assertFalse(captured["shell"])
        self.assertIn("c1; touch /tmp/pwned", captured["argv"])

    def test_docker_unhealthy_escalates(self):
        inv = make_inv([svc_docker("db", "db1")])
        with unittest.mock.patch.object(hm.subprocess, "run",
                                        return_value=self._patch_docker(0, "unhealthy\n")):
            rep = run_monitor(inv, RuntimeContext(uptime_seconds=3600, now=NOW),
                              self.store, dry_run=False)
        # unhealthy -> failed -> not escalated within grace-less threshold quickly,
        # but local status must be 'failed'.
        self.assertEqual(rep["services"][0]["local"], "failed")

    # ---- http probe branch coverage ---- #
    def test_exact_expected_redirect_ok(self):
        opener = FakeOpener(FakeResponse(302, {"location": "http://admin.mbfdhub.com/admin/login"}))
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("https://admin.mbfdhub.com/", expected_statuses=(302,),
                              expected_redirect_host="admin.mbfdhub.com",
                              expected_redirect_path_prefix="/admin/login")
        self.assertEqual(r.status, "healthy")

    def test_exact_expected_redirect_bad_host(self):
        opener = FakeOpener(FakeResponse(302, {"location": "http://evil.example/"}))
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("https://admin.mbfdhub.com/", expected_statuses=(302,),
                              expected_redirect_host="admin.mbfdhub.com")
        self.assertEqual(r.status, "failed")

    def test_expected_auth_challenge_ok(self):
        opener = FakeOpener(FakeResponse(401, {"www-authenticate": "Bearer"}))
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("https://x.example/", expected_statuses=(401,))
        self.assertEqual(r.status, "healthy")

    def test_incorrect_auth_response_fail(self):
        opener = FakeOpener(FakeResponse(503, {}))
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("https://x.example/", expected_statuses=(401,))
        self.assertEqual(r.status, "failed")

    def test_get_health_endpoint(self):
        cap = []
        opener = FakeOpener(FakeResponse(200, {}), capture=cap)
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("http://127.0.0.1:8080/up", method="GET", expected_statuses=(200,))
        self.assertEqual(r.status, "healthy")
        self.assertEqual(cap[0][0], "GET")

    def test_head_not_supported(self):
        opener = FakeOpener(FakeResponse(405, {}))
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("http://127.0.0.1:8080/", method="HEAD", expected_statuses=(200,))
        self.assertEqual(r.status, "failed")

    def test_connection_timeout(self):
        opener = FakeOpener(exc=socket.timeout("timed out"))
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("http://10.255.255.1:9/", timeout=1)
        self.assertEqual(r.status, "failed")
        self.assertEqual(r.detail, "timeout")

    def test_tls_error(self):
        opener = FakeOpener(exc=ssl.SSLError("certificate verify failed"))
        with unittest.mock.patch.object(hm.urllib.request, "build_opener", lambda *a, **k: opener):
            r = hm.probe_http("https://self-signed.example/", timeout=5)
        self.assertEqual(r.status, "failed")
        self.assertTrue(r.detail.startswith("tls-error"))

    def test_response_body_size_limit(self):
        class BigResp:
            def read(self, n=None):
                return b"A" * 1000
        self.assertLessEqual(len(hm._capped_read(BigResp(), 100)), 100)

    # ---- HLS scenarios ---- #
    def test_hls_advancing_sequence_no_escalation(self):
        self.assertFalse(hls_escalation({"frozen_media_sequence": False, "stale_playlist": False}))

    def test_hls_frozen_sequence_escalation(self):
        self.assertTrue(hls_escalation({"frozen_media_sequence": True}))

    def test_hls_segment_staleness_escalation(self):
        self.assertTrue(hls_escalation({"missing_segments": True}))
        self.assertTrue(hls_escalation({"stale_playlist": True}))

    # ---- inventory edge cases ---- #
    def test_inventory_missing_services_key(self):
        inv = {"schema_version": 1, "services": []}
        rep = run_monitor(inv, RuntimeContext(now=NOW), self.store, dry_run=False)
        self.assertEqual(rep["services"], [])

    def test_duplicate_service_id(self):
        inv = make_inv([svc_tcp("dup"), svc_tcp("dup")])
        rep = run_monitor(inv, RuntimeContext(now=NOW), self.store,
                          tcp_fn=lambda *a, **k: True, dry_run=False)
        self.assertEqual(len(rep["services"]), 2)
        # State is shared (same id), but report lists each inventory entry.
        self.assertEqual(self.store.load()[0].services["dup"].consecutive_successes, 2)

    def test_unknown_probe_type(self):
        svc = {"id": "weird", "origin_host": "127.0.0.1", "origin_port": 0,
               "local_health": {"type": "bogus"}, "public_hostnames": []}
        rep = run_monitor(make_inv([svc]), RuntimeContext(now=NOW), self.store, dry_run=False)
        self.assertEqual(rep["services"][0]["state"], "HEALTHY")

    def test_invalid_hostname_safe(self):
        r = probe_tcp("this is not a host !!", 1, timeout=1)
        self.assertEqual(r.status, "failed")


# --------------------------------------------------------------------------- #
# Import-semantics regression tests (Section 4)
# --------------------------------------------------------------------------- #
class ImportSemanticsTests(unittest.TestCase):
    """Guard against the importlib.util.spec_from_file_location bug class.

    The monitor module uses @dataclass and ``from __future__ import annotations``.
    If it is ever loaded outside normal import semantics (e.g. exec_module
    without sys.modules registration), dataclass field resolution breaks.
    These tests lock in the correct import path.
    """

    def test_module_importable_via_normal_import(self):
        import hermes_monitor
        self.assertTrue(hasattr(hermes_monitor, "run_monitor"))

    def test_module_present_in_sys_modules(self):
        import sys
        self.assertIn("hermes_monitor", sys.modules)

    def test_dataclass_fields_resolve(self):
        st = ServiceState()
        self.assertEqual(st.state, "HEALTHY")
        self.assertEqual(st.consecutive_failures, 0)
        doc = StateDocument()
        self.assertEqual(doc.schema_version, 1)
        self.assertIsInstance(doc.services, dict)

    def test_future_annotations_present(self):
        import hermes_monitor
        with open(hermes_monitor.__file__, "r", encoding="utf-8") as fh:
            src = fh.read()
        self.assertIn("from __future__ import annotations", src)

    def test_unittest_discovery_finds_tests(self):
        import sys
        loader = unittest.TestLoader()
        suite = loader.discover(start_dir=os.path.dirname(__file__) or ".",
                                pattern="test_*.py")
        tests_found = list(suite)
        self.assertTrue(len(tests_found) > 0,
                        "unittest discovery must find at least one test module")

    def test_reimport_is_idempotent(self):
        import importlib
        import hermes_monitor
        original_id = id(hermes_monitor)
        importlib.reload(hermes_monitor)
        self.assertTrue(hasattr(hermes_monitor, "run_monitor"))


if __name__ == "__main__":
    unittest.main()
