"""011 - Coaching."""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import DateTime, ForeignKey, String, Text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base
from app.db.mixins import TimestampMixin, UUIDPrimaryKeyMixin


class CoachNote(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "coach_notes"

    coach_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("coaches.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    note: Mapped[str] = mapped_column(Text, nullable=False)
    visibility: Mapped[str] = mapped_column(String(32), nullable=False, server_default="coach_only")


class CoachingIntervention(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "coaching_interventions"

    coach_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("coaches.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    intervention_type: Mapped[str] = mapped_column(String(64), nullable=False)
    description: Mapped[str | None] = mapped_column(Text)
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="open")
    scheduled_for: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
