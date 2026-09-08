"""008 - Workout execution.

Records what a member ACTUALLY performed. Prescription and performance are kept
strictly separate: ``workout_sets`` carries both ``planned_*`` values (copied
from the prescription at execution time) and ``actual_*`` values (recorded live).
Editing an ``actual_*`` value NEVER touches ``set_prescriptions`` - the program
prescription is the immutable source and is only referenced, never overwritten.

All execution history is protected by RESTRICT foreign keys so a parent delete
cannot silently erase performed history.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, Integer, Numeric, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import CreatedAtMixin, TimestampMixin, UUIDPrimaryKeyMixin


class WorkoutSession(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "workout_sessions"

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    program_assignment_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("program_assignments.id", ondelete="RESTRICT")
    )
    program_session_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("program_sessions.id", ondelete="RESTRICT")
    )
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="in_progress")
    started_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(), index=True
    )
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    notes: Mapped[str | None] = mapped_column(Text)

    exercises = relationship("WorkoutExercise", back_populates="session")


class WorkoutExercise(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "workout_exercises"

    workout_session_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("workout_sessions.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    # Optional trace back to the prescription that seeded this exercise.
    program_session_exercise_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("program_session_exercises.id", ondelete="RESTRICT")
    )
    sequence: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")
    notes: Mapped[str | None] = mapped_column(Text)

    session = relationship("WorkoutSession", back_populates="exercises")
    sets = relationship("WorkoutSet", back_populates="workout_exercise")


class WorkoutSet(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "workout_sets"

    workout_exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("workout_exercises.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    # Optional trace back to the exact prescription this set realises.
    set_prescription_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("set_prescriptions.id", ondelete="RESTRICT")
    )
    set_number: Mapped[int] = mapped_column(Integer, nullable=False)

    # Prescribed (planned) - copied in, read-only snapshot of the prescription.
    planned_reps: Mapped[int | None] = mapped_column(Integer)
    planned_load: Mapped[float | None] = mapped_column(Numeric(8, 2))
    planned_rpe: Mapped[float | None] = mapped_column(Numeric(4, 1))
    planned_rest_seconds: Mapped[int | None] = mapped_column(Integer)

    # Performed (actual) - recorded during the workout.
    actual_reps: Mapped[int | None] = mapped_column(Integer)
    actual_load: Mapped[float | None] = mapped_column(Numeric(8, 2))
    actual_rpe: Mapped[float | None] = mapped_column(Numeric(4, 1))
    actual_rest_seconds: Mapped[int | None] = mapped_column(Integer)

    load_unit: Mapped[str | None] = mapped_column(String(16))
    is_completed: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default="false")

    workout_exercise = relationship("WorkoutExercise", back_populates="sets")
