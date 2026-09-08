"""Shared column builders for migrations.

Kept alongside env.py (not under versions/) so Alembic does not treat it as a
revision. env.py adds this directory to sys.path, so version modules can
``from helpers import ...``. These helpers are intentionally frozen: they build
generic columns only and must not change historical migration output.
"""

from __future__ import annotations

import sqlalchemy as sa
from sqlalchemy.dialects.postgresql import UUID


def uuid_pk() -> sa.Column:
    """PostgreSQL-native UUID primary key, generated in the database."""
    return sa.Column(
        "id",
        UUID(as_uuid=True),
        primary_key=True,
        server_default=sa.text("gen_random_uuid()"),
    )


def created_at() -> sa.Column:
    return sa.Column(
        "created_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.text("now()"),
    )


def updated_at() -> sa.Column:
    return sa.Column(
        "updated_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.text("now()"),
    )


def timestamps() -> list[sa.Column]:
    return [created_at(), updated_at()]


def fk(target: str, ondelete: str = "RESTRICT"):
    """A ForeignKey with an explicit ON DELETE action (default RESTRICT)."""
    return sa.ForeignKey(target, ondelete=ondelete)
