"""003 fitness_profile - member_fitness_profiles, member_goals

Revision ID: 0003
Revises: 0002
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import fk, timestamps, uuid_pk

revision: str = "0003"
down_revision: Union[str, None] = "0002"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "member_fitness_profiles",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("training_experience", sa.String(48)),
        sa.Column("activity_level", sa.String(48)),
        sa.Column("primary_focus", sa.String(96)),
        sa.Column("height_cm", sa.Numeric(6, 2)),
        sa.Column("weight_kg", sa.Numeric(6, 2)),
        sa.Column("notes", sa.Text()),
        *timestamps(),
        sa.UniqueConstraint("member_id", name="uq_member_fitness_profiles_member_id"),
    )

    op.create_table(
        "member_goals",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("goal_type", sa.String(64), nullable=False),
        sa.Column("description", sa.Text()),
        sa.Column("target_value", sa.Numeric(12, 3)),
        sa.Column("target_unit", sa.String(32)),
        sa.Column("target_date", sa.Date()),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'active'")),
        sa.Column("priority", sa.Integer()),
        *timestamps(),
    )
    op.create_index("ix_member_goals_member_id", "member_goals", ["member_id"])


def downgrade() -> None:
    op.drop_index("ix_member_goals_member_id", table_name="member_goals")
    op.drop_table("member_goals")
    op.drop_table("member_fitness_profiles")
