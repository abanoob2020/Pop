"""009 progression_engine - policies, rules, state, events

Revision ID: 0009
Revises: 0008
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB, UUID

from helpers import created_at, fk, timestamps, uuid_pk

revision: str = "0009"
down_revision: Union[str, None] = "0008"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "progression_policies",
        uuid_pk(),
        sa.Column("code", sa.String(48), nullable=False),
        sa.Column("name", sa.String(120), nullable=False),
        sa.Column("description", sa.Text()),
        sa.Column("parameters", JSONB()),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        *timestamps(),
        sa.UniqueConstraint("code", name="uq_progression_policies_code"),
    )

    op.create_table(
        "assignment_progression_rules",
        uuid_pk(),
        sa.Column("program_assignment_id", UUID(as_uuid=True), fk("program_assignments.id"), nullable=False),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id")),
        sa.Column("progression_policy_id", UUID(as_uuid=True), fk("progression_policies.id"), nullable=False),
        sa.Column("parameters", JSONB()),
        sa.Column("active", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        *timestamps(),
    )
    op.create_index("ix_assignment_progression_rules_program_assignment_id", "assignment_progression_rules", ["program_assignment_id"])

    op.create_table(
        "progression_state",
        uuid_pk(),
        sa.Column("program_assignment_id", UUID(as_uuid=True), fk("program_assignments.id"), nullable=False),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id"), nullable=False),
        sa.Column("current_load", sa.Numeric(8, 2)),
        sa.Column("current_reps", sa.Integer()),
        sa.Column("current_load_unit", sa.String(16)),
        sa.Column("consecutive_successes", sa.Integer(), nullable=False, server_default=sa.text("0")),
        sa.Column("consecutive_failures", sa.Integer(), nullable=False, server_default=sa.text("0")),
        *timestamps(),
        sa.UniqueConstraint("program_assignment_id", "exercise_id", name="uq_progression_state_program_assignment_id"),
    )
    op.create_index("ix_progression_state_program_assignment_id", "progression_state", ["program_assignment_id"])

    op.create_table(
        "progression_events",
        uuid_pk(),
        sa.Column("assignment_id", UUID(as_uuid=True), fk("program_assignments.id"), nullable=False),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id"), nullable=False),
        sa.Column("workout_set_id", UUID(as_uuid=True), fk("workout_sets.id")),
        sa.Column("event_type", sa.String(48), nullable=False),
        sa.Column("previous_load", sa.Numeric(8, 2)),
        sa.Column("new_load", sa.Numeric(8, 2)),
        sa.Column("previous_reps", sa.Integer()),
        sa.Column("new_reps", sa.Integer()),
        sa.Column("reason", sa.Text()),
        sa.Column("occurred_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        created_at(),
    )
    op.create_index("ix_progression_events_assignment_id", "progression_events", ["assignment_id"])
    op.create_index("ix_progression_events_exercise_id", "progression_events", ["exercise_id"])


def downgrade() -> None:
    op.drop_index("ix_progression_events_exercise_id", table_name="progression_events")
    op.drop_index("ix_progression_events_assignment_id", table_name="progression_events")
    op.drop_table("progression_events")
    op.drop_index("ix_progression_state_program_assignment_id", table_name="progression_state")
    op.drop_table("progression_state")
    op.drop_index("ix_assignment_progression_rules_program_assignment_id", table_name="assignment_progression_rules")
    op.drop_table("assignment_progression_rules")
    op.drop_table("progression_policies")
