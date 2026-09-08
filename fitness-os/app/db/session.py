"""Engine / session factory for the Fitness OS schema."""

from __future__ import annotations

from sqlalchemy import create_engine
from sqlalchemy.engine import Engine
from sqlalchemy.orm import sessionmaker

from app.config import get_database_url


def make_engine(echo: bool = False) -> Engine:
    return create_engine(get_database_url(), echo=echo, future=True, pool_pre_ping=True)


def make_session_factory(engine: Engine | None = None) -> sessionmaker:
    return sessionmaker(bind=engine or make_engine(), expire_on_commit=False, future=True)
