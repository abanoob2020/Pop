"""011 coaching - coach_notes, coaching_interventions

Revision ID: 0011
Revises: 0010
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import fk, timestamps, uuid_pk

revision: str = "0011"
down_revision: Union[str, None] = "0010"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "coach_notes",
        uuid_pk(),
        sa.Column("coach_id", UUID(as_uuid=True), fk("coaches.id"), nullable=False),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("note", sa.Text(), nullable=False),
        sa.Column("visibility", sa.String(32), nullable=False, server_default=sa.text("'coach_only'")),
        *timestamps(),
    )
    op.create_index("ix_coach_notes_coach_id", "coach_notes", ["coach_id"])
    op.create_index("ix_coach_notes_member_id", "coach_notes", ["member_id"])

    op.create_table(
        "coaching_interventions",
        uuid_pk(),
        sa.Column("coach_id", UUID(as_uuid=True), fk("coaches.id"), nullable=False),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("intervention_type", sa.String(64), nullable=False),
        sa.Column("description", sa.Text()),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'open'")),
        sa.Column("scheduled_for", sa.DateTime(timezone=True)),
        sa.Column("resolved_at", sa.DateTime(timezone=True)),
        *timestamps(),
    )
    op.create_index("ix_coaching_interventions_coach_id", "coaching_interventions", ["coach_id"])
    op.create_index("ix_coaching_interventions_member_id", "coaching_interventions", ["member_id"])


def downgrade() -> None:
    op.drop_index("ix_coaching_interventions_member_id", table_name="coaching_interventions")
    op.drop_index("ix_coaching_interventions_coach_id", table_name="coaching_interventions")
    op.drop_table("coaching_interventions")
    op.drop_index("ix_coach_notes_member_id", table_name="coach_notes")
    op.drop_index("ix_coach_notes_coach_id", table_name="coach_notes")
    op.drop_table("coach_notes")
