"""Declarative base and shared metadata for the XCAMP Fitness OS schema.

A single MetaData object with an explicit constraint naming convention so that
constraint / index names are deterministic across SQLAlchemy model definitions,
Alembic migrations, and database reflection (used by the test-suite).
"""

from __future__ import annotations

from sqlalchemy import MetaData
from sqlalchemy.orm import DeclarativeBase

# Deterministic naming keeps Alembic autogenerate output stable and lets the
# tests reflect the migrated database and compare it against the models.
NAMING_CONVENTION = {
    "ix": "ix_%(table_name)s_%(column_0_name)s",
    "uq": "uq_%(table_name)s_%(column_0_name)s",
    "ck": "ck_%(table_name)s_%(constraint_name)s",
    "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
    "pk": "pk_%(table_name)s",
}

metadata_obj = MetaData(naming_convention=NAMING_CONVENTION)


class Base(DeclarativeBase):
    """Base class for all Fitness OS ORM models."""

    metadata = metadata_obj
