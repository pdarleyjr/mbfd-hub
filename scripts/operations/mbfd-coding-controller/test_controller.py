#!/usr/bin/env python3
"""Automated tests for MBFD Coding Controller."""

import asyncio
import json
import math
import os
import sys
import tempfile
import time
from unittest.mock import AsyncMock, MagicMock, mock_open, patch

import pytest
import pytest_asyncio
import httpx
from httpx import ASGITransport

os.environ["OLLAMA_UPSTREAM"] = "http://mock-ollama:11434"
os.environ["CONTROLLER_HOST"] = "127.0.0.1"
os.environ["CONTROLLER_PORT"] = "11436"
os.environ["LOCK_PATH"] = tempfile.mktemp(suffix=".lock")
os.environ["LOG_DIR"] = tempfile.mkdtemp()
os.environ["INACTIVITY_TIMEOUT_S"] = "1200"
os.environ["MONITOR_INTERVAL_S"] = "1"
os.environ["MONITOR_IDLE_INTERVAL_S"] = "10"
os.environ["COOLDOWN_S"] = "0"
os.environ["MIN_AVAILABLE_RAM_GIB"] = "16"
os.environ["MAX_SWAP_DELTA_GIB"] = "1"
os.environ["SUPPRESS_UNITS"] = ""
os.environ["APPROVED_MODEL"] = "mbfd-code:32k"

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import controller as ctrl


@pytest.fixture(autouse=True)
def reset_state():
    ctrl.state = ctrl.SessionState()
    ctrl.state.ram_minimum_gib = float("inf")
    ctrl.shutdown_event = asyncio.Event()
    yield
    ctrl.state = ctrl.SessionState()
    ctrl.shutdown_event = asyncio.Event()


@pytest.fixture
def lock_dir(tmp_path):
    old_lock = ctrl.LOCK_PATH
    ctrl.LOCK_PATH = str(tmp_path / "test.lock")
    yield tmp_path
    ctrl.LOCK_PATH = old_lock


@pytest_asyncio.fixture
async def client():
    transport = ASGITransport(app=ctrl.app)
    async with httpx.AsyncClient(transport=transport, base_url="http://test") as c:
        yield c


class TestModelWhitelist:
    def test_approved_model_accepted(self):
        assert ctrl.validate_model_name("mbfd-code:32k") is True

    def test_wrong_model_rejected(self):
        assert ctrl.validate_model_name("qwen3.6:35b") is False

    def test_empty_model_rejected(self):
        assert ctrl.validate_model_name("") is False

    def test_none_model_rejected(self):
        assert ctrl.validate_model_name(None) is False

    def test_path_traversal_rejected(self):
        assert ctrl.validate_model_name("../etc/passwd") is False

    def test_case_insensitive(self):
        assert ctrl.validate_model_name("MBFD-CODE:32K") is True


class TestInvalidJSON:
    async def test_invalid_json_returns_400(self, client):
        resp = await client.post(
            "/api/chat",
            content=b"{invalid json",
            headers={"content-type": "application/json"},
        )
        assert resp.status_code == 400
        data = resp.json()
        assert "error" in data
        assert "invalid JSON" in data["error"]

    async def test_non_object_json_returns_400(self, client):
        resp = await client.post(
            "/api/chat",
            content=b'"just a string"',
            headers={"content-type": "application/json"},
        )
        assert resp.status_code == 400


class TestMissingModel:
    async def test_missing_model_field_returns_400(self, client):
        resp = await client.post(
            "/api/chat",
            json={"messages": [{"role": "user", "content": "hi"}]},
        )
        assert resp.status_code == 400

    async def test_wrong_model_returns_400(self, client):
        resp = await client.post(
            "/api/chat",
            json={"model": "wrong-model", "messages": [{"role": "user", "content": "hi"}]},
        )
        assert resp.status_code == 400


class TestOversizedRequest:
    async def test_oversized_body_rejected(self, client):
        old_max = ctrl.MAX_REQUEST_BODY_BYTES
        ctrl.MAX_REQUEST_BODY_BYTES = 100
        try:
            big_body = b"x" * 200
            resp = await client.post(
                "/api/chat",
                content=big_body,
                headers={"content-type": "application/json"},
            )
            assert resp.status_code == 400
            assert "exceeds" in resp.json()["error"]
        finally:
            ctrl.MAX_REQUEST_BODY_BYTES = old_max


class TestStatusEndpointSerialization:
    async def test_status_no_inf_values(self, client):
        ctrl.state.ram_minimum_gib = float("inf")
        ctrl.state.ram_baseline_gib = float("inf")
        ctrl.state.swap_baseline_gib = float("nan")
        resp = await client.get("/controller/status")
        assert resp.status_code == 200
        data = resp.json()
        assert data["ram_minimum_gib"] is None
        assert data["ram_baseline_gib"] is None
        assert data["swap_baseline_gib"] is None

    async def test_health_no_inf(self, client):
        resp = await client.get("/health")
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] == "ok"

    async def test_status_normal_state(self, client):
        resp = await client.get("/controller/status")
        assert resp.status_code == 200
        data = resp.json()
        assert data["state"] == "NORMAL"


class TestLockOperations:
    def test_acquire_and_release(self, lock_dir):
        assert ctrl.acquire_lock() is True
        assert ctrl.state.lock_fd is not None
        ctrl.release_lock()
        assert ctrl.state.lock_fd is None

    def test_release_without_acquire(self):
        ctrl.release_lock()
        assert ctrl.state.lock_fd is None

    def test_lock_contention(self, lock_dir):
        assert ctrl.acquire_lock() is True
        old_fd = ctrl.state.lock_fd
        ctrl.state.lock_fd = None
        assert ctrl.acquire_lock() is False
        ctrl.state.lock_fd = old_fd
        ctrl.release_lock()


class TestEndSession:
    async def test_end_session_when_not_active(self, client):
        resp = await client.post("/controller/end-session")
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] == "not active"

    async def test_end_session_when_coding(self, client):
        ctrl.state.state = ctrl.ControllerState.CODING
        ctrl.state.model_loaded = True

        with patch.object(ctrl, "unload_model", new_callable=AsyncMock) as mock_unload, \
             patch.object(ctrl, "wait_model_absent", new_callable=AsyncMock, return_value=True), \
             patch.object(ctrl, "ollama_post", new_callable=AsyncMock, return_value={}), \
             patch.object(ctrl, "restore_background_services", new_callable=AsyncMock), \
             patch.object(ctrl, "release_lock"):
            resp = await client.post("/controller/end-session")
            assert resp.status_code == 200
            data = resp.json()
            assert data["status"] == "ended"


class TestApiTags:
    async def test_tags_returns_only_approved(self, client):
        resp = await client.get("/api/tags")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data["models"]) == 1
        assert data["models"][0]["name"] == ctrl.APPROVED_MODEL

    async def test_openai_catalog_returns_only_approved(self, client):
        resp = await client.get("/v1/models")
        assert resp.status_code == 200
        data = resp.json()
        assert [model["id"] for model in data["data"]] == [ctrl.APPROVED_MODEL]


class TestOpenAIAdmission:
    async def test_chat_rejects_wrong_model_before_upstream(self, client):
        with patch.object(ctrl, "enter_coding_mode", new_callable=AsyncMock) as enter:
            resp = await client.post(
                "/v1/chat/completions",
                json={
                    "model": "qwen3.6:35b",
                    "messages": [{"role": "user", "content": "hi"}],
                },
            )

        assert resp.status_code == 400
        enter.assert_not_awaited()

    async def test_chat_uses_admission_and_approved_model(self, client):
        mock_response = MagicMock()
        mock_response.content = b'{"choices": []}'
        mock_response.status_code = 200
        mock_response.headers = {"content-type": "application/json"}
        mock_client = MagicMock()
        mock_client.post = AsyncMock(return_value=mock_response)

        with patch.object(
            ctrl, "enter_coding_mode", new_callable=AsyncMock, return_value=(True, "ok")
        ) as enter, patch.object(ctrl, "http_client", mock_client):
            resp = await client.post(
                "/v1/chat/completions",
                json={
                    "model": "mbfd-code:32k",
                    "stream": False,
                    "messages": [{"role": "user", "content": "hi"}],
                },
            )

        assert resp.status_code == 200
        enter.assert_awaited_once()
        forwarded = mock_client.post.await_args.kwargs["json"]
        assert forwarded["model"] == ctrl.APPROVED_MODEL


class TestCatchAllRestriction:
    async def test_restricted_path_returns_403(self, client):
        resp = await client.get("/api/delete-everything")
        assert resp.status_code == 403

    async def test_allowed_path_proxied(self, client):
        with patch.object(ctrl, "http_client") as mock_client:
            mock_resp = MagicMock()
            mock_resp.content = b'{"version": "0.5.0"}'
            mock_resp.status_code = 200
            mock_resp.headers = {"content-type": "application/json"}
            mock_client.request = AsyncMock(return_value=mock_resp)
            resp = await client.get("/api/version")
            assert resp.status_code == 200


class TestSafeFloat:
    def test_inf_returns_none(self):
        assert ctrl._safe_float(float("inf")) is None

    def test_neg_inf_returns_none(self):
        assert ctrl._safe_float(float("-inf")) is None

    def test_nan_returns_none(self):
        assert ctrl._safe_float(float("nan")) is None

    def test_normal_value_rounded(self):
        assert ctrl._safe_float(42.567) == 42.6

    def test_zero(self):
        assert ctrl._safe_float(0.0) == 0.0


class TestControllerStates:
    def test_states_exist(self):
        assert ctrl.ControllerState.NORMAL == "NORMAL"
        assert ctrl.ControllerState.STARTING == "STARTING"
        assert ctrl.ControllerState.CODING == "CODING"
        assert ctrl.ControllerState.DRAINING == "DRAINING"
        assert ctrl.ControllerState.STOPPING == "STOPPING"

    def test_default_state(self):
        s = ctrl.SessionState()
        assert s.state == ctrl.ControllerState.NORMAL


class TestParseJsonSafe:
    async def test_valid_json(self):
        mock_request = AsyncMock()
        mock_request.body = AsyncMock(return_value=b'{"model": "mbfd-code:32k"}')
        result = await ctrl._parse_json_safe(mock_request)
        assert result["model"] == "mbfd-code:32k"

    async def test_invalid_json_raises(self):
        mock_request = AsyncMock()
        mock_request.body = AsyncMock(return_value=b"{bad json")
        with pytest.raises(ValueError, match="invalid JSON"):
            await ctrl._parse_json_safe(mock_request)

    async def test_oversized_raises(self):
        old_max = ctrl.MAX_REQUEST_BODY_BYTES
        ctrl.MAX_REQUEST_BODY_BYTES = 10
        mock_request = AsyncMock()
        mock_request.body = AsyncMock(return_value=b"x" * 100)
        try:
            with pytest.raises(ValueError, match="exceeds"):
                await ctrl._parse_json_safe(mock_request)
        finally:
            ctrl.MAX_REQUEST_BODY_BYTES = old_max

    async def test_non_dict_raises(self):
        mock_request = AsyncMock()
        mock_request.body = AsyncMock(return_value=b'[1, 2, 3]')
        with pytest.raises(ValueError, match="must be a JSON object"):
            await ctrl._parse_json_safe(mock_request)


class TestResourceReaders:
    def test_meminfo_and_derived_values(self):
        data = "MemAvailable: 33554432 kB\nSwapTotal: 4194304 kB\nSwapFree: 3145728 kB\n"
        with patch("builtins.open", mock_open(read_data=data)):
            assert ctrl.get_available_ram_gib() == 32.0
        with patch("builtins.open", mock_open(read_data=data)):
            assert ctrl.get_swap_used_gib() == 1.0

    def test_meminfo_oserror_is_fail_closed(self):
        with patch("builtins.open", side_effect=OSError("unavailable")):
            assert ctrl.read_meminfo() == {}
            assert ctrl.get_available_ram_gib() == 0.0
            assert ctrl.get_swap_used_gib() == 0.0

    def test_pressure_parser(self):
        data = "some avg10=2.50 avg60=1.00 total=1\nfull avg10=0.25 avg60=0.10 total=1\n"
        with patch("builtins.open", mock_open(read_data=data)):
            assert ctrl.read_pressure() == {"some_avg10": 2.5, "full_avg10": 0.25}

    def test_pressure_oserror(self):
        with patch("builtins.open", side_effect=OSError("unavailable")):
            assert ctrl.read_pressure() == {}


class TestOllamaHelpers:
    async def test_get_and_post(self):
        response = MagicMock()
        response.json.return_value = {"ok": True}
        client = MagicMock()
        client.get = AsyncMock(return_value=response)
        client.post = AsyncMock(return_value=response)
        with patch.object(ctrl, "http_client", client):
            assert await ctrl.ollama_get("/api/ps") == {"ok": True}
            assert await ctrl.ollama_post("/api/chat", {"model": "x"}, timeout=7) == {"ok": True}
        response.raise_for_status.assert_called()

    async def test_unload_swallows_provider_failure(self):
        with patch.object(ctrl, "ollama_post", new_callable=AsyncMock, side_effect=RuntimeError("down")):
            await ctrl.unload_model("model")

    async def test_wait_absent_and_present(self):
        with patch.object(
            ctrl,
            "ollama_get",
            new_callable=AsyncMock,
            side_effect=[{"models": [{"name": "other"}]}, {"models": [{"name": "wanted"}]}],
        ):
            assert await ctrl.wait_model_absent("wanted", timeout_s=1) is True
            assert await ctrl.wait_model_present("wanted", timeout_s=1) is True

    async def test_wait_helpers_timeout_after_transient_error(self):
        with patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=RuntimeError("down")), \
             patch.object(ctrl.asyncio, "sleep", new_callable=AsyncMock), \
             patch.object(ctrl.time, "monotonic", side_effect=[0.0, 0.1, 2.0]):
            assert await ctrl.wait_model_absent("wanted", timeout_s=1) is False


class TestServiceSuppression:
    async def test_suppress_records_and_stops_only_active_units(self):
        active = MagicMock(stdout="active\n")
        stopped = MagicMock(stdout="")
        inactive = MagicMock(stdout="inactive\n")
        with patch.object(ctrl, "SUPPRESS_UNITS", ["active.timer", "inactive.timer"]), \
             patch.object(ctrl.subprocess, "run", side_effect=[active, stopped, inactive]) as run:
            prior = await ctrl.suppress_background_services()
        assert prior == {"active.timer": "active", "inactive.timer": "inactive"}
        assert run.call_count == 3

    async def test_suppress_records_unknown_on_failure(self):
        with patch.object(ctrl, "SUPPRESS_UNITS", ["bad.timer"]), \
             patch.object(ctrl.subprocess, "run", side_effect=TimeoutError("slow")):
            assert await ctrl.suppress_background_services() == {"bad.timer": "unknown"}

    async def test_restore_starts_only_previously_active_units(self):
        with patch.object(ctrl.subprocess, "run") as run:
            await ctrl.restore_background_services({"active.timer": "active", "inactive.timer": "inactive"})
        run.assert_called_once()
        assert run.call_args.args[0][-2:] == ["start", "active.timer"]

    async def test_restore_swallows_start_failure(self):
        with patch.object(ctrl.subprocess, "run", side_effect=TimeoutError("slow")):
            await ctrl.restore_background_services({"active.timer": "active"})


class TestEnterCodingMode:
    async def test_existing_active_and_busy_states(self):
        ctrl.state.state = ctrl.ControllerState.CODING
        assert await ctrl.enter_coding_mode() == (True, "already active")
        ctrl.state.state = ctrl.ControllerState.STARTING
        ok, message = await ctrl.enter_coding_mode()
        assert ok is False and "busy" in message

    async def test_cooldown_and_lock_contention(self):
        ctrl.state.cooldown_until = time.monotonic() + 60
        ok, message = await ctrl.enter_coding_mode()
        assert ok is False and "cooldown" in message
        ctrl.state.cooldown_until = 0
        with patch.object(ctrl, "acquire_lock", return_value=False):
            assert await ctrl.enter_coding_mode() == (False, "could not acquire exclusive lock")

    async def test_success_unloads_other_model_and_admits(self):
        ps_results = [
            {"models": [{"name": ctrl.GENERAL_MODEL}]},
            {"models": []},
            {"models": [{"name": ctrl.APPROVED_MODEL}]},
        ]
        with patch.object(ctrl, "acquire_lock", return_value=True), \
             patch.object(ctrl, "get_swap_used_gib", side_effect=[1.0, 1.0]), \
             patch.object(ctrl, "get_available_ram_gib", side_effect=[32.0, 24.0]), \
             patch.object(ctrl, "suppress_background_services", new_callable=AsyncMock, return_value={"timer": "active"}), \
             patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=ps_results), \
             patch.object(ctrl, "ollama_post", new_callable=AsyncMock), \
             patch.object(ctrl, "unload_model", new_callable=AsyncMock) as unload, \
             patch.object(ctrl, "wait_model_absent", new_callable=AsyncMock, return_value=True), \
             patch.object(ctrl, "wait_model_present", new_callable=AsyncMock, return_value=True):
            assert await ctrl.enter_coding_mode() == (True, "coding mode active")
        unload.assert_awaited_once_with(ctrl.GENERAL_MODEL)
        assert ctrl.state.state == ctrl.ControllerState.CODING
        assert ctrl.state.model_loaded is True

    async def test_failed_model_load_releases_exclusive_lock(self):
        with patch.object(ctrl, "acquire_lock", return_value=True), \
             patch.object(ctrl, "get_swap_used_gib", return_value=0.0), \
             patch.object(ctrl, "get_available_ram_gib", return_value=32.0), \
             patch.object(ctrl, "suppress_background_services", new_callable=AsyncMock, return_value={}), \
             patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=[{"models": []}, {"models": []}]), \
             patch.object(ctrl, "ollama_post", new_callable=AsyncMock), \
             patch.object(ctrl, "wait_model_present", new_callable=AsyncMock, return_value=False), \
             patch.object(ctrl, "release_lock") as release:
            ok, message = await ctrl.enter_coding_mode()
        assert ok is False and "failed to load" in message
        release.assert_called_once()
        assert ctrl.state.state == ctrl.ControllerState.NORMAL

    @pytest.mark.parametrize(
        ("ps_after_load", "ram_values", "swap_values", "message"),
        [
            ({"models": [{"name": "unauthorized"}]}, [32.0], [0.0], "unauthorized models"),
            ({"models": [{"name": ctrl.APPROVED_MODEL}]}, [32.0, 4.0], [0.0], "below abort floor"),
            ({"models": [{"name": ctrl.APPROVED_MODEL}]}, [32.0, 24.0], [0.0, 2.0], "swap delta"),
        ],
    )
    async def test_post_load_safety_rejections(self, ps_after_load, ram_values, swap_values, message):
        with patch.object(ctrl, "acquire_lock", return_value=True), \
             patch.object(ctrl, "get_swap_used_gib", side_effect=swap_values), \
             patch.object(ctrl, "get_available_ram_gib", side_effect=ram_values), \
             patch.object(ctrl, "suppress_background_services", new_callable=AsyncMock, return_value={"timer": "active"}), \
             patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=[{"models": []}, {"models": []}, ps_after_load]), \
             patch.object(ctrl, "ollama_post", new_callable=AsyncMock), \
             patch.object(ctrl, "unload_model", new_callable=AsyncMock), \
             patch.object(ctrl, "wait_model_absent", new_callable=AsyncMock, return_value=True), \
             patch.object(ctrl, "wait_model_present", new_callable=AsyncMock, return_value=True), \
             patch.object(ctrl, "restore_background_services", new_callable=AsyncMock), \
             patch.object(ctrl, "release_lock"):
            ok, detail = await ctrl.enter_coding_mode()
        assert ok is False and message in detail
        assert ctrl.state.state == ctrl.ControllerState.NORMAL

    async def test_provider_error_restores_services_and_lock(self):
        with patch.object(ctrl, "acquire_lock", return_value=True), \
             patch.object(ctrl, "get_swap_used_gib", return_value=0.0), \
             patch.object(ctrl, "get_available_ram_gib", return_value=32.0), \
             patch.object(ctrl, "suppress_background_services", new_callable=AsyncMock, return_value={"timer": "active"}), \
             patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=RuntimeError("provider down")), \
             patch.object(ctrl, "restore_background_services", new_callable=AsyncMock) as restore, \
             patch.object(ctrl, "release_lock") as release:
            assert await ctrl.enter_coding_mode() == (False, "provider down")
        restore.assert_awaited_once_with({"timer": "active"})
        release.assert_called_once()


class TestExitCodingMode:
    async def test_noop_when_session_is_inactive(self):
        with patch.object(ctrl, "ollama_post", new_callable=AsyncMock) as post:
            await ctrl.exit_coding_mode()
        post.assert_not_awaited()

    async def test_unloads_coding_model_recovers_general_model_and_services(self):
        ctrl.state.state = ctrl.ControllerState.CODING
        ctrl.state.model_loaded = True
        ctrl.state.streaming_active = True
        ctrl.state.suppressed_units = {"timer": "active"}
        with patch.object(ctrl, "unload_model", new_callable=AsyncMock) as unload, \
             patch.object(ctrl, "wait_model_absent", new_callable=AsyncMock, return_value=True), \
             patch.object(ctrl, "ollama_post", new_callable=AsyncMock) as post, \
             patch.object(ctrl, "restore_background_services", new_callable=AsyncMock) as restore, \
             patch.object(ctrl, "release_lock") as release:
            await ctrl.exit_coding_mode("safety_ram")
        unload.assert_awaited_once_with(ctrl.APPROVED_MODEL)
        assert post.await_args.args[0] == "/api/chat"
        restore.assert_awaited_once_with({"timer": "active"})
        release.assert_called_once()
        assert ctrl.state.state == ctrl.ControllerState.NORMAL
        assert ctrl.state.cooldown_until > 0

    async def test_recovery_errors_do_not_prevent_normal_state(self):
        ctrl.state.state = ctrl.ControllerState.DRAINING
        with patch.object(ctrl, "ollama_post", new_callable=AsyncMock, side_effect=RuntimeError("warm failed")), \
             patch.object(ctrl, "restore_background_services", new_callable=AsyncMock, side_effect=RuntimeError("restore failed")), \
             patch.object(ctrl, "release_lock"):
            await ctrl.exit_coding_mode("manual")
        assert ctrl.state.state == ctrl.ControllerState.NORMAL


async def _run_single_safety_iteration(*, ram=32.0, swap=0.0, pressure=None, models=None, idle=False):
    ctrl.state.state = ctrl.ControllerState.CODING
    ctrl.state.swap_baseline_gib = 0.0
    ctrl.state.last_activity = time.monotonic() - ctrl.INACTIVITY_TIMEOUT_S - 1 if idle else time.monotonic()

    async def stop_after_abort(reason):
        ctrl.shutdown_event.set()

    with patch.object(ctrl.asyncio, "sleep", new_callable=AsyncMock), \
         patch.object(ctrl, "get_available_ram_gib", return_value=ram), \
         patch.object(ctrl, "get_swap_used_gib", return_value=swap), \
         patch.object(ctrl, "read_pressure", return_value=pressure or {}), \
         patch.object(ctrl, "ollama_get", new_callable=AsyncMock, return_value={"models": [{"name": name} for name in (models or [ctrl.APPROVED_MODEL])]}), \
         patch.object(ctrl, "exit_coding_mode", new_callable=AsyncMock, side_effect=stop_after_abort) as exit_mode:
        await ctrl.safety_monitor()
    return exit_mode


class TestSafetyMonitor:
    async def test_ram_abort(self):
        exit_mode = await _run_single_safety_iteration(ram=1.0)
        exit_mode.assert_awaited_once_with("safety_ram")

    async def test_swap_abort(self):
        exit_mode = await _run_single_safety_iteration(swap=ctrl.MAX_SWAP_DELTA_GIB + 1)
        exit_mode.assert_awaited_once_with("safety_swap")

    async def test_pressure_abort(self):
        exit_mode = await _run_single_safety_iteration(pressure={"some_avg10": 26.0})
        exit_mode.assert_awaited_once_with("safety_pressure")

    async def test_unauthorized_model_abort(self):
        exit_mode = await _run_single_safety_iteration(models=[ctrl.APPROVED_MODEL, "other"])
        exit_mode.assert_awaited_once_with("unauthorized_model")

    async def test_provider_error_still_honors_idle_release(self):
        ctrl.state.state = ctrl.ControllerState.CODING
        ctrl.state.last_activity = time.monotonic() - ctrl.INACTIVITY_TIMEOUT_S - 1

        async def stop_after_abort(reason):
            ctrl.shutdown_event.set()

        with patch.object(ctrl.asyncio, "sleep", new_callable=AsyncMock), \
             patch.object(ctrl, "get_available_ram_gib", return_value=32.0), \
             patch.object(ctrl, "get_swap_used_gib", return_value=0.0), \
             patch.object(ctrl, "read_pressure", return_value={}), \
             patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=RuntimeError("down")), \
             patch.object(ctrl, "exit_coding_mode", new_callable=AsyncMock, side_effect=stop_after_abort) as exit_mode:
            await ctrl.safety_monitor()
        exit_mode.assert_awaited_once_with("inactivity")

    async def test_cancelled_monitor_stops_cleanly(self):
        with patch.object(ctrl.asyncio, "sleep", new_callable=AsyncMock, side_effect=asyncio.CancelledError):
            await ctrl.safety_monitor()


class TestStartupReconciliation:
    async def test_unloads_residual_model_and_warms_general_model(self, tmp_path):
        lock_path = tmp_path / "stale.lock"
        lock_path.write_text("stale", encoding="utf-8")
        with patch.object(ctrl, "LOCK_PATH", str(lock_path)), \
             patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=[
                 {"models": [{"name": ctrl.APPROVED_MODEL}]},
                 {"models": []},
             ]), \
             patch.object(ctrl, "unload_model", new_callable=AsyncMock) as unload, \
             patch.object(ctrl, "wait_model_absent", new_callable=AsyncMock, return_value=True), \
             patch.object(ctrl, "ollama_post", new_callable=AsyncMock) as post:
            await ctrl.startup_reconciliation()
        assert not lock_path.exists()
        unload.assert_awaited_once_with(ctrl.APPROVED_MODEL)
        post.assert_awaited_once()

    async def test_provider_errors_are_nonfatal(self):
        with patch.object(ctrl.os.path, "exists", side_effect=RuntimeError("fs error")), \
             patch.object(ctrl, "ollama_get", new_callable=AsyncMock, side_effect=RuntimeError("down")):
            await ctrl.startup_reconciliation()


class TestProviderMetadataRoutes:
    async def test_show_rejects_wrong_model(self, client):
        resp = await client.post("/api/show", json={"model": "wrong"})
        assert resp.status_code == 400

    async def test_show_forwards_only_approved_model(self, client):
        response = MagicMock(content=b'{"details": {}}', status_code=200, headers={"content-type": "application/json"})
        upstream = MagicMock(post=AsyncMock(return_value=response))
        with patch.object(ctrl, "http_client", upstream):
            resp = await client.post("/api/show", json={"model": ctrl.APPROVED_MODEL})
        assert resp.status_code == 200
        assert upstream.post.await_args.kwargs["json"]["model"] == ctrl.APPROVED_MODEL

    async def test_ps_exposes_only_coding_provider_readiness(self, client):
        response = MagicMock()
        response.json.side_effect = [
            {"models": [{"name": ctrl.APPROVED_MODEL}, {"name": "other"}]},
            {"models": [{"name": "other"}]},
            {"models": [{"name": "other"}]},
        ]
        upstream = MagicMock(get=AsyncMock(return_value=response))
        with patch.object(ctrl, "http_client", upstream):
            ctrl.state.state = ctrl.ControllerState.CODING
            assert [m["name"] for m in (await client.get("/api/ps")).json()["models"]] == [ctrl.APPROVED_MODEL]
            ctrl.state.state = ctrl.ControllerState.NORMAL
            normal_models = (await client.get("/api/ps")).json()["models"]
            assert [m["name"] for m in normal_models] == [ctrl.APPROVED_MODEL]
            assert normal_models[0]["provider_state"] == "admission_ready"
            ctrl.state.state = ctrl.ControllerState.STARTING
            assert (await client.get("/api/ps")).json()["models"] == []

    async def test_ps_does_not_claim_readiness_when_raw_provider_fails(self, client):
        request = httpx.Request("GET", f"{ctrl.OLLAMA_UPSTREAM}/api/ps")
        response = httpx.Response(503, request=request)
        upstream = MagicMock(get=AsyncMock(return_value=response))
        with patch.object(ctrl, "http_client", upstream), pytest.raises(httpx.HTTPStatusError):
            await client.get("/api/ps")


class TestInferenceFailureAndBusyPaths:
    async def test_busy_state_rejects_without_new_admission(self, client):
        ctrl.state.state = ctrl.ControllerState.STARTING
        with patch.object(ctrl, "enter_coding_mode", new_callable=AsyncMock) as enter:
            resp = await client.post("/api/chat", json={"model": ctrl.APPROVED_MODEL, "stream": False})
        assert resp.status_code == 503
        enter.assert_not_awaited()

    async def test_failed_admission_returns_503(self, client):
        with patch.object(ctrl, "enter_coding_mode", new_callable=AsyncMock, return_value=(False, "unsafe")):
            resp = await client.post("/api/chat", json={"model": ctrl.APPROVED_MODEL, "stream": False})
        assert resp.status_code == 503 and "unsafe" in resp.json()["error"]

    @pytest.mark.parametrize(
        ("error", "status", "message"),
        [
            (httpx.ReadTimeout("slow"), 504, "upstream timeout"),
            (httpx.ConnectError("down"), 502, "upstream connection failed"),
            (RuntimeError("bad"), 502, "upstream error"),
        ],
    )
    async def test_nonstream_provider_failures_are_controlled(self, client, error, status, message):
        ctrl.state.state = ctrl.ControllerState.CODING
        upstream = MagicMock(post=AsyncMock(side_effect=error))
        with patch.object(ctrl, "http_client", upstream):
            resp = await client.post("/v1/completions", json={"model": ctrl.APPROVED_MODEL, "stream": False, "prompt": "hi"})
        assert resp.status_code == status
        assert resp.json()["error"] == message
        assert ctrl.state.streaming_active is False


class _FakeStream:
    def __init__(self, *, status_code=200, lines=(), body=b""):
        self.status_code = status_code
        self._lines = list(lines)
        self._body = body

    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc, tb):
        return False

    async def aread(self):
        return self._body

    async def aiter_lines(self):
        for line in self._lines:
            yield line


class TestStreamingInference:
    async def test_openai_stream_media_type_and_payload(self, client):
        ctrl.state.state = ctrl.ControllerState.CODING
        upstream = MagicMock()
        upstream.stream.return_value = _FakeStream(lines=["data: one", "data: two"])
        with patch.object(ctrl, "http_client", upstream):
            resp = await client.post("/v1/chat/completions", json={"model": ctrl.APPROVED_MODEL, "stream": True, "messages": []})
        assert resp.status_code == 200
        assert resp.headers["content-type"].startswith("text/event-stream")
        assert resp.text == "data: one\ndata: two\n"
        assert ctrl.state.streaming_active is False

    @pytest.mark.parametrize(
        ("body", "expected"),
        [
            (b'{"error":"denied"}', '"denied"'),
            (b"plain failure", "plain failure"),
        ],
    )
    async def test_stream_provider_error_body_is_returned(self, client, body, expected):
        ctrl.state.state = ctrl.ControllerState.CODING
        upstream = MagicMock()
        upstream.stream.return_value = _FakeStream(status_code=503, body=body)
        with patch.object(ctrl, "http_client", upstream):
            resp = await client.post("/api/generate", json={"model": ctrl.APPROVED_MODEL, "stream": True})
        assert resp.status_code == 200
        assert expected in resp.text


class TestCatchAllBodyLimit:
    async def test_allowed_path_rejects_oversized_body_with_413(self, client):
        old_max = ctrl.MAX_REQUEST_BODY_BYTES
        ctrl.MAX_REQUEST_BODY_BYTES = 2
        try:
            with patch.object(ctrl, "http_client", MagicMock()):
                resp = await client.post("/api/version", content=b"long")
        finally:
            ctrl.MAX_REQUEST_BODY_BYTES = old_max
        assert resp.status_code == 413
