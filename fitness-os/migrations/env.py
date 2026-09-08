"""Alembic environment for XCAMP Fitness OS.

The database URL is resolved at runtime from environment variables (never stored
in alembic.ini or git). ``target_metadata`` points at the models' metadata so
``alembic revision --autogenerate`` and drift checks work.
"""

from __future__ import annotations

import os
import sys
from logging.config import fileConfig

from alembic import context
from sqlalchemy import engine_from_config, pool

# Make the project root and the migrations dir importable.
_HERE = os.path.dirname(os.path.abspath(__file__))
_ROOT = os.path.dirname(_HERE)
for _p in (_ROOT, _HERE):
    if _p not in sys.path:
        sys.path.insert(0, _p)

from app.config import get_database_url, get_settings  # noqa: E402
from app.db import models  # noqa: E402,F401  (populates metadata)
from app.db.base import Base  # noqa: E402

config = context.config

if config.config_file_name is not None:
    fileConfig(config.config_file_name)

# Inject the runtime URL from the environment.
config.set_main_option("sqlalchemy.url", get_database_url())

target_metadata = Base.metadata


def _print_target() -> None:
    """Print connection target WITHOUT the password before running."""
    summary = get_settings().safe_summary()
    print("=== Alembic migration target (password hidden) ===")
    for key, value in summary.items():
        print(f"  {key:>9}: {value}")
    print("==================================================")


def run_migrations_offline() -> None:
    context.configure(
        url=get_database_url(),
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
        compare_type=True,
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    _print_target()
    section = config.get_section(config.config_ini_section, {})
    connectable = engine_from_config(
        section,
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            compare_type=True,
        )
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
