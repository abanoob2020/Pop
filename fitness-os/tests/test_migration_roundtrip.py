"""Migration chain: upgrade -> downgrade -> upgrade on a throwaway database, and
a linear, well-formed revision chain 0001..0012."""

from __future__ import annotations

import os

import pytest
from alembic import command
from alembic.config import Config
from alembic.script import ScriptDirectory
from sqlalchemy import create_engine, inspect, text

from tests.conftest import (
    APP_HOST,
    APP_PASSWORD,
    APP_PORT,
    APP_USER,
    PROJECT_ROOT,
    _app_url,
    _drop_database,
    _recreate_database,
)

ROUNDTRIP_DB = os.getenv("FITNESS_ROUNDTRIP_DB_NAME", "xcamp_os_migtest")


def _config(url: str) -> Config:
    cfg = Config(os.path.join(PROJECT_ROOT, "alembic.ini"))
    cfg.set_main_option("script_location", os.path.join(PROJECT_ROOT, "migrations"))
    os.environ["FITNESS_DATABASE_URL"] = url
    return cfg


def test_revision_chain_is_linear():
    cfg = Config(os.path.join(PROJECT_ROOT, "alembic.ini"))
    cfg.set_main_option("script_location", os.path.join(PROJECT_ROOT, "migrations"))
    script = ScriptDirectory.from_config(cfg)
    revs = list(script.walk_revisions())
    ids = [r.revision for r in revs]
    assert sorted(ids) == [f"{i:04d}" for i in range(1, 13)]
    heads = list(script.get_heads())
    assert heads == ["0012"], f"expected single head 0012, got {heads}"


def test_upgrade_downgrade_upgrade_roundtrip():
    _recreate_database(ROUNDTRIP_DB)
    url = _app_url(ROUNDTRIP_DB)
    cfg = _config(url)
    engine = create_engine(url, future=True)

    def table_count() -> int:
        insp = inspect(engine)
        return len([t for t in insp.get_table_names() if t != "alembic_version"])

    try:
        command.upgrade(cfg, "head")
        assert table_count() == 38

        command.downgrade(cfg, "base")
        assert table_count() == 0

        command.upgrade(cfg, "head")
        assert table_count() == 38
    finally:
        engine.dispose()
        _drop_database(ROUNDTRIP_DB)
