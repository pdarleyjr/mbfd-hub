from __future__ import annotations

import importlib.util
import sys
from pathlib import Path


WATCHDOG = Path(__file__).resolve().parents[1] / "mbfd-cloudflared-watchdog.py"


def _load_watchdog(tmp_path: Path):
    spec = importlib.util.spec_from_file_location("mbfd_cloudflared_watchdog", WATCHDOG)
    module = importlib.util.module_from_spec(spec)
    sys.modules["mbfd_cloudflared_watchdog"] = module
    spec.loader.exec_module(module)
    return module


def _cfg(module, state_dir: Path, **overrides):
    base = dict(
        public_url="https://media.mbfdhub.com",
        health_path="/api/status",
        socketio_path="/socket.io/?EIO=4&transport=polling",
        failure_threshold=3,
        cooldown_seconds=300,
        max_restarts_per_hour=3,
        request_timeout=8.0,
        state_dir=state_dir,
        dry_run=False,
        local_url="http://localhost:8096",
    )
    base.update(overrides)
    return module.WatchdogConfig(**base)


def _install_mocks(module, *, healthy: bool, restart_succeeds: bool = True,
                   local_healthy: bool = True, already_restarting: bool = False):
    module.check_public_path = lambda cfg: module.CheckResult(healthy, "stub")
    module.check_local_media_control = lambda cfg: module.CheckResult(local_healthy, "local-stub")
    module.is_cloudflared_restarting = lambda: already_restarting
    module.registered_tunnel_connections = lambda minutes=5: 4
    restarted = {"count": 0}

    def fake_restart(cfg):
        restarted["count"] += 1
        return restart_succeeds

    module.restart_cloudflared = fake_restart
    return restarted


def test_healthy_check_resets_failures_and_does_not_restart(tmp_path, monkeypatch):
    mod = _load_watchdog(tmp_path)
    _install_mocks(mod, healthy=True)
    # Seed a prior failure count.
    (tmp_path / "state.json").write_text(
        '{"consecutive_failures": 2, "last_restart": 0.0, "restarts": []}'
    )
    cfg = _cfg(mod, tmp_path)
    rc = mod.evaluate(cfg)
    assert rc == 0
    import json

    state = json.loads((tmp_path / "state.json").read_text())
    assert state["consecutive_failures"] == 0


def test_below_threshold_does_not_restart(tmp_path):
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False)
    cfg = _cfg(mod, tmp_path, failure_threshold=3)
    # Two unhealthy cycles: must not restart yet.
    assert mod.evaluate(cfg) == 1
    assert mod.evaluate(cfg) == 1
    assert restarted["count"] == 0


def test_threshold_reached_restarts_and_verifies(tmp_path):
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, restart_succeeds=True)
    cfg = _cfg(mod, tmp_path, failure_threshold=3)
    mod.evaluate(cfg)
    mod.evaluate(cfg)
    rc = mod.evaluate(cfg)  # 3rd failure -> threshold reached
    assert rc == 0
    assert restarted["count"] == 1


def test_cooldown_blocks_rapid_restart(tmp_path):
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, restart_succeeds=True)
    cfg = _cfg(mod, tmp_path, failure_threshold=1, cooldown_seconds=10_000)
    assert mod.evaluate(cfg) == 0  # first restart
    # Immediately another failure: cooldown must block a second restart.
    rc = mod.evaluate(cfg)
    assert rc == 1
    assert restarted["count"] == 1


def test_storm_cap_stops_restart_and_alerts(tmp_path):
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, restart_succeeds=True)
    cfg = _cfg(
        mod, tmp_path, failure_threshold=1, cooldown_seconds=0, max_restarts_per_hour=2
    )
    # Reach the cap.
    mod.evaluate(cfg)
    mod.evaluate(cfg)
    assert restarted["count"] == 2
    # Third attempt within the hour: storm protection -> alert-only (exit 2).
    rc = mod.evaluate(cfg)
    assert rc == 2
    assert restarted["count"] == 2


def test_failed_recovery_returns_alert_code(tmp_path):
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, restart_succeeds=False)
    cfg = _cfg(mod, tmp_path, failure_threshold=1, cooldown_seconds=0)
    rc = mod.evaluate(cfg)
    assert rc == 2
    assert restarted["count"] == 1


def test_dry_run_never_restarts(tmp_path):
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, restart_succeeds=True)
    cfg = _cfg(mod, tmp_path, failure_threshold=1, cooldown_seconds=0, dry_run=True)
    mod.evaluate(cfg)
    assert restarted["count"] == 0


def test_check_public_path_failure_for_network_error(tmp_path, monkeypatch):
    mod = _load_watchdog(tmp_path)

    def fake_get(url, timeout):
        return None, "network_error=URLError"

    monkeypatch.setattr(mod, "_http_get", fake_get)
    cfg = _cfg(mod, tmp_path)
    result = mod.check_public_path(cfg)
    assert result.healthy is False


def test_local_media_control_down_does_not_restart_cloudflared(tmp_path):
    """Public unhealthy + local Media Control unhealthy -> NOT a tunnel problem;
    never restart cloudflared; alert-only (exit 2)."""
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, local_healthy=False)
    cfg = _cfg(mod, tmp_path, failure_threshold=1, cooldown_seconds=0)
    rc = mod.evaluate(cfg)
    assert rc == 2
    assert restarted["count"] == 0


def test_sustained_external_failure_with_local_healthy_restarts(tmp_path):
    """Public unhealthy + local Media Control healthy -> tunnel problem; restart cloudflared."""
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, local_healthy=True, restart_succeeds=True)
    cfg = _cfg(mod, tmp_path, failure_threshold=1, cooldown_seconds=0)
    rc = mod.evaluate(cfg)
    assert rc == 0
    assert restarted["count"] == 1


def test_cloudflared_already_restarting_skips_restart(tmp_path):
    """cloudflared mid-transition -> skip restart this cycle (exit 1)."""
    mod = _load_watchdog(tmp_path)
    restarted = _install_mocks(mod, healthy=False, local_healthy=True, already_restarting=True)
    cfg = _cfg(mod, tmp_path, failure_threshold=1, cooldown_seconds=0)
    rc = mod.evaluate(cfg)
    assert rc == 1
    assert restarted["count"] == 0


def test_check_local_media_control_healthy(tmp_path, monkeypatch):
    mod = _load_watchdog(tmp_path)
    monkeypatch.setattr(mod, "_http_get", lambda url, timeout: (200, ""))
    cfg = _cfg(mod, tmp_path)
    result = mod.check_local_media_control(cfg)
    assert result.healthy is True


def test_check_local_media_control_unhealthy(tmp_path, monkeypatch):
    mod = _load_watchdog(tmp_path)
    monkeypatch.setattr(mod, "_http_get", lambda url, timeout: (502, "http_error=502"))
    cfg = _cfg(mod, tmp_path)
    result = mod.check_local_media_control(cfg)
    assert result.healthy is False
