"""009 - Progression engine.

Declarative progression only - NO custom executable scripting. Policies are
identified by a fixed set of codes; per-assignment rules parameterise a policy;
state tracks current working values; events are an append-only ledger of changes.

``progression_events`` is operational history and is protected by RESTRICT
foreign keys. Its assignment foreign-key column is named ``assignment_id`` to
match the canonical access-pattern index list.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import (
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import CreatedAtMixin, TimestampMixin, UUIDPrimaryKeyMixin


class ProgressionPolicy(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "progression_policies"

    # Initial policy types: LINEAR_LOAD, DOUBLE_PROGRESSION, REP_SUM, COACH_MANUAL
    code: Mapped[str] = mapped_column(String(48), nullable=False, unique=True)
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    description: Mapped[str | None] = mapped_column(Text)
    parameters: Mapped[dict | None] = mapped_column(JSONB)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default="true")


class AssignmentProgressionRule(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "assignment_progression_rules"

    program_assignment_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_assignments.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    exercise_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("exercises.id", ondelete="RESTRICT")
    )
    progression_policy_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("progression_policies.id", ondelete="RESTRICT"), nullable=False
    )
    parameters: Mapped[dict | None] = mapped_column(JSONB)
    active: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default="true")


class ProgressionState(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "progression_state"
    __table_args__ = (
        UniqueConstraint(
            "program_assignment_id",
            "exercise_id",
            name="uq_progression_state_program_assignment_id",
        ),
    )

    program_assignment_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_assignments.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="RESTRICT"), nullable=False
    )
    current_load: Mapped[float | None] = mapped_column(Numeric(8, 2))
    current_reps: Mapped[int | None] = mapped_column(Integer)
    current_load_unit: Mapped[str | None] = mapped_column(String(16))
    consecutive_successes: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    consecutive_failures: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")


class ProgressionEvent(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "progression_events"

    # Named assignment_id per the canonical access-pattern index list.
    assignment_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("program_assignments.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    workout_set_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("workout_sets.id", ondelete="RESTRICT")
    )
    event_type: Mapped[str] = mapped_column(String(48), nullable=False)
    previous_load: Mapped[float | None] = mapped_column(Numeric(8, 2))
    new_load: Mapped[float | None] = mapped_column(Numeric(8, 2))
    previous_reps: Mapped[int | None] = mapped_column(Integer)
    new_reps: Mapped[int | None] = mapped_column(Integer)
    reason: Mapped[str | None] = mapped_column(Text)
    occurred_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
