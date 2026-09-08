"""006 programming - templates/versions/phases/weeks/sessions/exercises/prescriptions

Revision ID: 0006
Revises: 0005
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import fk, timestamps, uuid_pk

revision: str = "0006"
down_revision: Union[str, None] = "0005"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "program_templates",
        uuid_pk(),
        sa.Column("name", sa.String(160), nullable=False),
        sa.Column("description", sa.Text()),
        sa.Column("created_by_coach_id", UUID(as_uuid=True), fk("coaches.id")),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'active'")),
        *timestamps(),
    )

    op.create_table(
        "program_versions",
        uuid_pk(),
        sa.Column("template_id", UUID(as_uuid=True), fk("program_templates.id"), nullable=False),
        sa.Column("version_number", sa.Integer(), nullable=False),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'draft'")),
        sa.Column("published_at", sa.DateTime(timezone=True)),
        sa.Column("notes", sa.Text()),
        *timestamps(),
        sa.UniqueConstraint("template_id", "version_number", name="uq_program_versions_template_id"),
    )
    op.create_index("ix_program_versions_template_id", "program_versions", ["template_id"])

    op.create_table(
        "program_phases",
        uuid_pk(),
        sa.Column("program_version_id", UUID(as_uuid=True), fk("program_versions.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(160), nullable=False),
        sa.Column("sequence", sa.Integer(), nullable=False, server_default=sa.text("1")),
        sa.Column("description", sa.Text()),
    )
    op.create_index("ix_program_phases_program_version_id", "program_phases", ["program_version_id"])

    op.create_table(
        "program_weeks",
        uuid_pk(),
        sa.Column("program_phase_id", UUID(as_uuid=True), fk("program_phases.id", ondelete="CASCADE"), nullable=False),
        sa.Column("week_number", sa.Integer(), nullable=False),
        sa.Column("sequence", sa.Integer(), nullable=False, server_default=sa.text("1")),
    )
    op.create_index("ix_program_weeks_program_phase_id", "program_weeks", ["program_phase_id"])

    op.create_table(
        "program_sessions",
        uuid_pk(),
        sa.Column("program_week_id", UUID(as_uuid=True), fk("program_weeks.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(160), nullable=False),
        sa.Column("day_of_week", sa.Integer()),
        sa.Column("sequence", sa.Integer(), nullable=False, server_default=sa.text("1")),
    )
    op.create_index("ix_program_sessions_program_week_id", "program_sessions", ["program_week_id"])

    op.create_table(
        "program_session_exercises",
        uuid_pk(),
        sa.Column("program_session_id", UUID(as_uuid=True), fk("program_sessions.id", ondelete="CASCADE"), nullable=False),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id"), nullable=False),
        sa.Column("sequence", sa.Integer(), nullable=False, server_default=sa.text("1")),
        sa.Column("notes", sa.Text()),
    )
    op.create_index("ix_program_session_exercises_program_session_id", "program_session_exercises", ["program_session_id"])
    op.create_index("ix_program_session_exercises_exercise_id", "program_session_exercises", ["exercise_id"])

    op.create_table(
        "set_prescriptions",
        uuid_pk(),
        sa.Column("program_session_exercise_id", UUID(as_uuid=True), fk("program_session_exercises.id", ondelete="CASCADE"), nullable=False),
        sa.Column("set_number", sa.Integer(), nullable=False),
        sa.Column("target_reps", sa.Integer()),
        sa.Column("target_reps_min", sa.Integer()),
        sa.Column("target_reps_max", sa.Integer()),
        sa.Column("target_load", sa.Numeric(8, 2)),
        sa.Column("target_load_unit", sa.String(16)),
        sa.Column("target_rpe", sa.Numeric(4, 1)),
        sa.Column("target_rest_seconds", sa.Integer()),
        sa.Column("tempo", sa.String(16)),
    )
    op.create_index("ix_set_prescriptions_program_session_exercise_id", "set_prescriptions", ["program_session_exercise_id"])


def downgrade() -> None:
    op.drop_index("ix_set_prescriptions_program_session_exercise_id", table_name="set_prescriptions")
    op.drop_table("set_prescriptions")
    op.drop_index("ix_program_session_exercises_exercise_id", table_name="program_session_exercises")
    op.drop_index("ix_program_session_exercises_program_session_id", table_name="program_session_exercises")
    op.drop_table("program_session_exercises")
    op.drop_index("ix_program_sessions_program_week_id", table_name="program_sessions")
    op.drop_table("program_sessions")
    op.drop_index("ix_program_weeks_program_phase_id", table_name="program_weeks")
    op.drop_table("program_weeks")
    op.drop_index("ix_program_phases_program_version_id", table_name="program_phases")
    op.drop_table("program_phases")
    op.drop_index("ix_program_versions_template_id", table_name="program_versions")
    op.drop_table("program_versions")
    op.drop_table("program_templates")
