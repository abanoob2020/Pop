"""006 - Programming model.

Template -> Version -> Phase -> Week -> Session -> SessionExercise -> SetPrescription.

Published ``program_versions`` are conceptually immutable: members are assigned
to a *version*, never to the mutable template, so the prescription a member
received cannot change underneath them. Full immutability enforcement is a
service-layer concern; the schema supports it by (a) versioning, (b) a status
column, and (c) RESTRICT foreign keys from assignments/workouts that prevent a
version (or any prescribed row that has been used) from being deleted.

Within a single version the phase/week/session/exercise/prescription subtree
CASCADEs, so an unused DRAFT version can be edited/rebuilt cleanly. A version in
use is protected from deletion by RESTRICT foreign keys in the assignment and
execution aggregates (see docs/DELETION_POLICY.md).
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import (
    DateTime,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import TimestampMixin, UUIDPrimaryKeyMixin


class ProgramTemplate(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "program_templates"

    name: Mapped[str] = mapped_column(String(160), nullable=False)
    description: Mapped[str | None] = mapped_column(Text)
    created_by_coach_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("coaches.id", ondelete="RESTRICT")
    )
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="active")

    versions = relationship("ProgramVersion", back_populates="template")


class ProgramVersion(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "program_versions"
    __table_args__ = (
        UniqueConstraint(
            "template_id", "version_number", name="uq_program_versions_template_id"
        ),
    )

    template_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_templates.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    version_number: Mapped[int] = mapped_column(Integer, nullable=False)
    # draft | published | archived - published versions are immutable by policy.
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="draft")
    published_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    notes: Mapped[str | None] = mapped_column(Text)

    template = relationship("ProgramTemplate", back_populates="versions")
    phases = relationship("ProgramPhase", back_populates="version", passive_deletes=True)


class ProgramPhase(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "program_phases"

    program_version_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_versions.id", ondelete="CASCADE"), nullable=False, index=True
    )
    name: Mapped[str] = mapped_column(String(160), nullable=False)
    sequence: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")
    description: Mapped[str | None] = mapped_column(Text)

    version = relationship("ProgramVersion", back_populates="phases")
    weeks = relationship("ProgramWeek", back_populates="phase", passive_deletes=True)


class ProgramWeek(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "program_weeks"

    program_phase_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_phases.id", ondelete="CASCADE"), nullable=False, index=True
    )
    week_number: Mapped[int] = mapped_column(Integer, nullable=False)
    sequence: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")

    phase = relationship("ProgramPhase", back_populates="weeks")
    sessions = relationship("ProgramSession", back_populates="week", passive_deletes=True)


class ProgramSession(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "program_sessions"

    program_week_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_weeks.id", ondelete="CASCADE"), nullable=False, index=True
    )
    name: Mapped[str] = mapped_column(String(160), nullable=False)
    day_of_week: Mapped[int | None] = mapped_column(Integer)
    sequence: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")

    week = relationship("ProgramWeek", back_populates="sessions")
    session_exercises = relationship(
        "ProgramSessionExercise", back_populates="session", passive_deletes=True
    )


class ProgramSessionExercise(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "program_session_exercises"

    program_session_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_sessions.id", ondelete="CASCADE"), nullable=False, index=True
    )
    # RESTRICT: an exercise referenced by any prescription cannot be deleted.
    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    sequence: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")
    notes: Mapped[str | None] = mapped_column(Text)

    session = relationship("ProgramSession", back_populates="session_exercises")
    set_prescriptions = relationship(
        "SetPrescription", back_populates="session_exercise", passive_deletes=True
    )


class SetPrescription(UUIDPrimaryKeyMixin, Base):
    """The PRESCRIBED work for one set. Never overwritten by execution."""

    __tablename__ = "set_prescriptions"

    program_session_exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_session_exercises.id", ondelete="CASCADE"),
        nullable=False,
        index=True,
    )
    set_number: Mapped[int] = mapped_column(Integer, nullable=False)
    target_reps: Mapped[int | None] = mapped_column(Integer)
    target_reps_min: Mapped[int | None] = mapped_column(Integer)
    target_reps_max: Mapped[int | None] = mapped_column(Integer)
    target_load: Mapped[float | None] = mapped_column(Numeric(8, 2))
    target_load_unit: Mapped[str | None] = mapped_column(String(16))
    target_rpe: Mapped[float | None] = mapped_column(Numeric(4, 1))
    target_rest_seconds: Mapped[int | None] = mapped_column(Integer)
    tempo: Mapped[str | None] = mapped_column(String(16))

    session_exercise = relationship(
        "ProgramSessionExercise", back_populates="set_prescriptions"
    )
