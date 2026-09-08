"""Schema creation: every model table exists in the migrated database, and the
migrated columns match the models (guards against migration/model drift)."""

from __future__ import annotations

from sqlalchemy import inspect

from app.db import models  # noqa: F401  populates metadata
from app.db.base import Base

EXPECTED_TABLES = set(Base.metadata.tables.keys())


def test_all_model_tables_exist(migrated_engine):
    inspector = inspect(migrated_engine)
    actual = set(inspector.get_table_names())
    missing = EXPECTED_TABLES - actual
    assert not missing, f"Tables missing from migrated DB: {sorted(missing)}"


def test_expected_table_count():
    # 12 domains -> 38 tables in the canonical model.
    assert len(EXPECTED_TABLES) == 38


def test_columns_match_models(migrated_engine):
    inspector = inspect(migrated_engine)
    mismatches = {}
    for name, table in Base.metadata.tables.items():
        model_cols = {c.name for c in table.columns}
        db_cols = {c["name"] for c in inspector.get_columns(name)}
        if model_cols != db_cols:
            mismatches[name] = {
                "only_in_model": sorted(model_cols - db_cols),
                "only_in_db": sorted(db_cols - model_cols),
            }
    assert not mismatches, f"Column drift: {mismatches}"


def test_uuid_primary_keys(migrated_engine):
    inspector = inspect(migrated_engine)
    for name in EXPECTED_TABLES:
        cols = {c["name"]: c for c in inspector.get_columns(name)}
        assert "id" in cols, f"{name} has no id column"
        assert "uuid" in str(cols["id"]["type"]).lower(), f"{name}.id is not UUID"
