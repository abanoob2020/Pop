"""Pytest fixtures for the Fitness OS schema tests.

Strategy
--------
* A dedicated **test database** is created fresh (dropped + recreated) by an
  admin/maintenance connection, then built with the real Alembic migration
  chain (``alembic upgrade head``) - so the tests exercise exactly what a
  staging migration produces, not ``create_all``.
* Each test runs inside its own transaction which is rolled back at teardown,
  giving isolation without re-migrating between tests.

Environment
-----------
    FITNESS_TEST_ADMIN_URL  admin URL able to CREATE/DROP DATABASE
                            default: postgresql+psycopg://postgres@127.0.0.1:5433/postgres
    FITNESS_TEST_DB_NAME    name of the throwaway test DB (default xcamp_os_test)
    FITNESS_DB_USER/PASSWORD/HOST/PORT  the app (least-privilege) connection
"""

from __future__ import annotations

import os
import uuid

import pytest
from alembic import command
from alembic.config import Config
from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(HERE)

ADMIN_URL = os.getenv(
    "FITNESS_TEST_ADMIN_URL",
    "postgresql+psycopg://postgres@127.0.0.1:5433/postgres",
)
TEST_DB_NAME = os.getenv("FITNESS_TEST_DB_NAME", "xcamp_os_test")

APP_USER = os.getenv("FITNESS_DB_USER", "xcamp_app")
APP_PASSWORD = os.getenv("FITNESS_DB_PASSWORD", "")
APP_HOST = os.getenv("FITNESS_DB_HOST", "127.0.0.1")
APP_PORT = os.getenv("FITNESS_DB_PORT", "5433")


def _app_url(db_name: str) -> str:
    auth = f"{APP_USER}:{APP_PASSWORD}" if APP_PASSWORD else APP_USER
    return f"postgresql+psycopg://{auth}@{APP_HOST}:{APP_PORT}/{db_name}"


def _recreate_database(db_name: str) -> None:
    admin = create_engine(ADMIN_URL, isolation_level="AUTOCOMMIT", future=True)
    with admin.connect() as conn:
        conn.execute(
            text(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
                "WHERE datname = :n AND pid <> pg_backend_pid()"
            ),
            {"n": db_name},
        )
        conn.execute(text(f'DROP DATABASE IF EXISTS "{db_name}"'))
        conn.execute(text(f'CREATE DATABASE "{db_name}" OWNER "{APP_USER}"'))
    admin.dispose()


def _drop_database(db_name: str) -> None:
    admin = create_engine(ADMIN_URL, isolation_level="AUTOCOMMIT", future=True)
    with admin.connect() as conn:
        conn.execute(
            text(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
                "WHERE datname = :n AND pid <> pg_backend_pid()"
            ),
            {"n": db_name},
        )
        conn.execute(text(f'DROP DATABASE IF EXISTS "{db_name}"'))
    admin.dispose()


def _alembic_config(db_url: str) -> Config:
    cfg = Config(os.path.join(PROJECT_ROOT, "alembic.ini"))
    cfg.set_main_option("script_location", os.path.join(PROJECT_ROOT, "migrations"))
    # env.py reads the URL from FITNESS_DATABASE_URL / FITNESS_DB_* at runtime.
    os.environ["FITNESS_DATABASE_URL"] = db_url
    return cfg


@pytest.fixture(scope="session")
def migrated_engine():
    """Fresh test DB built by the real migration chain (upgrade head)."""
    _recreate_database(TEST_DB_NAME)
    url = _app_url(TEST_DB_NAME)
    cfg = _alembic_config(url)
    command.upgrade(cfg, "head")
    engine = create_engine(url, future=True)
    try:
        yield engine
    finally:
        engine.dispose()
        _drop_database(TEST_DB_NAME)


@pytest.fixture()
def db_session(migrated_engine):
    """A rolled-back transaction per test for isolation."""
    connection = migrated_engine.connect()
    trans = connection.begin()
    session = Session(bind=connection, expire_on_commit=False, future=True)
    try:
        yield session
    finally:
        session.close()
        if trans.is_active:
            trans.rollback()
        connection.close()


@pytest.fixture()
def new_uuid():
    return lambda: uuid.uuid4()
