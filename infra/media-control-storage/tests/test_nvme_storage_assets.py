from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_compose_override_moves_only_sqlite_state_to_nvme():
    override = (ROOT / "docker-compose.nvme-db.override.yml").read_text(encoding="utf-8")

    assert '"/var/lib/mbfd/media-control-db:/app/data/db"' in override
    assert "/app/server/uploads" not in override
    assert "/app/server/certs" not in override


def test_migration_verifier_is_fail_closed_and_preserves_rollback():
    script = (ROOT / "verify-nvme-db-cutover.sh").read_text(encoding="utf-8")

    assert "set -euo pipefail" in script
    assert "PRAGMA quick_check" in script
    assert "display_states" in script
    assert "video_walls" in script
    assert "MEDIA_CONTROL_NVME_DB_DIR:-/var/lib/mbfd/media-control-db" in script
    assert 'expected_mount="bind|$db_dir"' in script
    assert "docker inspect" in script
    assert "curl -fsS --max-time" in script
    assert "rm -rf" not in script
