"""008 workout_execution - workout_sessions, workout_exercises, workout_sets

Revision ID: 0008
Revises: 0007
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import created_at, fk, timestamps, uuid_pk

revision: str = "0008"
down_revision: Union[str, None] = "0007"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "workout_sessions",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("program_assignment_id", UUID(as_uuid=True), fk("program_assignments.id")),
        sa.Column("program_session_id", UUID(as_uuid=True), fk("program_sessions.id")),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'in_progress'")),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("completed_at", sa.DateTime(timezone=True)),
        sa.Column("notes", sa.Text()),
        *timestamps(),
    )
    op.create_index("ix_workout_sessions_member_id", "workout_sessions", ["member_id"])
    op.create_index("ix_workout_sessions_started_at", "workout_sessions", ["started_at"])

    op.create_table(
        "workout_exercises",
        uuid_pk(),
        sa.Column("workout_session_id", UUID(as_uuid=True), fk("workout_sessions.id"), nullable=False),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id"), nullable=False),
        sa.Column("program_session_exercise_id", UUID(as_uuid=True), fk("program_session_exercises.id")),
        sa.Column("sequence", sa.Integer(), nullable=False, server_default=sa.text("1")),
        sa.Column("notes", sa.Text()),
        *timestamps(),
    )
    op.create_index("ix_workout_exercises_workout_session_id", "workout_exercises", ["workout_session_id"])
    op.create_index("ix_workout_exercises_exercise_id", "workout_exercises", ["exercise_id"])

    op.create_table(
        "workout_sets",
        uuid_pk(),
        sa.Column("workout_exercise_id", UUID(as_uuid=True), fk("workout_exercises.id"), nullable=False),
        sa.Column("set_prescription_id", UUID(as_uuid=True), fk("set_prescriptions.id")),
        sa.Column("set_number", sa.Integer(), nullable=False),
        sa.Column("planned_reps", sa.Integer()),
        sa.Column("planned_load", sa.Numeric(8, 2)),
        sa.Column("planned_rpe", sa.Numeric(4, 1)),
        sa.Column("planned_rest_seconds", sa.Integer()),
        sa.Column("actual_reps", sa.Integer()),
        sa.Column("actual_load", sa.Numeric(8, 2)),
        sa.Column("actual_rpe", sa.Numeric(4, 1)),
        sa.Column("actual_rest_seconds", sa.Integer()),
        sa.Column("load_unit", sa.String(16)),
        sa.Column("is_completed", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        created_at(),
    )
    op.create_index("ix_workout_sets_workout_exercise_id", "workout_sets", ["workout_exercise_id"])


def downgrade() -> None:
    op.drop_index("ix_workout_sets_workout_exercise_id", table_name="workout_sets")
    op.drop_table("workout_sets")
    op.drop_index("ix_workout_exercises_exercise_id", table_name="workout_exercises")
    op.drop_index("ix_workout_exercises_workout_session_id", table_name="workout_exercises")
    op.drop_table("workout_exercises")
    op.drop_index("ix_workout_sessions_started_at", table_name="workout_sessions")
    op.drop_index("ix_workout_sessions_member_id", table_name="workout_sessions")
    op.drop_table("workout_sessions")
