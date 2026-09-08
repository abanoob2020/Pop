"""007 - Program assignments.

A member is assigned to an immutable ``program_version`` (never the mutable
template). ``assignment_session_state`` tracks per-session progress state for
the assignment.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import DateTime, ForeignKey, Integer, String, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import TimestampMixin, UUIDPrimaryKeyMixin


class ProgramAssignment(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "program_assignments"

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    # Bound to a VERSION, not a template. RESTRICT protects assigned history.
    program_version_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_versions.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    assigned_by_coach_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("coaches.id", ondelete="RESTRICT")
    )
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="active")
    starts_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    ends_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    session_states = relationship(
        "AssignmentSessionState", back_populates="assignment", passive_deletes=True
    )


class AssignmentSessionState(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "assignment_session_state"

    program_assignment_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_assignments.id", ondelete="CASCADE"), nullable=False, index=True
    )
    program_session_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_sessions.id", ondelete="RESTRICT"), nullable=False
    )
    state: Mapped[str] = mapped_column(String(32), nullable=False, server_default="pending")
    scheduled_for: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    sequence: Mapped[int | None] = mapped_column(Integer)

    assignment = relationship("ProgramAssignment", back_populates="session_states")
