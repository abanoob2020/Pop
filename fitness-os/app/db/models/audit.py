"""012 - Audit & domain events.

Append-only. ``audit_events`` captures who did what to which entity, with
before/after content hashes and a request correlation id. ``domain_events`` is a
generic event log for aggregates. Both are protected history (no cascade deletes
into them).

The canonical field named ``timestamp`` is stored as ``occurred_at`` to avoid
the SQL reserved word.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import DateTime, String, Text, func
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base
from app.db.mixins import CreatedAtMixin, UUIDPrimaryKeyMixin


class AuditEvent(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "audit_events"

    actor: Mapped[str] = mapped_column(String(160), nullable=False)
    action: Mapped[str] = mapped_column(String(96), nullable=False)
    entity: Mapped[str] = mapped_column(String(96), nullable=False)
    entity_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True))
    before_hash: Mapped[str | None] = mapped_column(String(128))
    after_hash: Mapped[str | None] = mapped_column(String(128))
    request_id: Mapped[str | None] = mapped_column(String(128), index=True)
    occurred_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(), index=True
    )


class DomainEvent(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "domain_events"

    aggregate_type: Mapped[str] = mapped_column(String(96), nullable=False)
    aggregate_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False, index=True)
    event_type: Mapped[str] = mapped_column(String(96), nullable=False)
    payload: Mapped[dict | None] = mapped_column(JSONB)
    occurred_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(), index=True
    )
