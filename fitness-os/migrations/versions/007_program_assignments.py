"""007 program_assignments - program_assignments, assignment_session_state

Revision ID: 0007
Revises: 0006
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import fk, timestamps, uuid_pk

revision: str = "0007"
down_revision: Union[str, None] = "0006"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "program_assignments",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("program_version_id", UUID(as_uuid=True), fk("program_versions.id"), nullable=False),
        sa.Column("assigned_by_coach_id", UUID(as_uuid=True), fk("coaches.id")),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'active'")),
        sa.Column("starts_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("ends_at", sa.DateTime(timezone=True)),
        *timestamps(),
    )
    op.create_index("ix_program_assignments_member_id", "program_assignments", ["member_id"])
    op.create_index("ix_program_assignments_program_version_id", "program_assignments", ["program_version_id"])

    op.create_table(
        "assignment_session_state",
        uuid_pk(),
        sa.Column("program_assignment_id", UUID(as_uuid=True), fk("program_assignments.id", ondelete="CASCADE"), nullable=False),
        sa.Column("program_session_id", UUID(as_uuid=True), fk("program_sessions.id"), nullable=False),
        sa.Column("state", sa.String(32), nullable=False, server_default=sa.text("'pending'")),
        sa.Column("scheduled_for", sa.DateTime(timezone=True)),
        sa.Column("completed_at", sa.DateTime(timezone=True)),
        sa.Column("sequence", sa.Integer()),
        *timestamps(),
    )
    op.create_index("ix_assignment_session_state_program_assignment_id", "assignment_session_state", ["program_assignment_id"])


def downgrade() -> None:
    op.drop_index("ix_assignment_session_state_program_assignment_id", table_name="assignment_session_state")
    op.drop_table("assignment_session_state")
    op.drop_index("ix_program_assignments_program_version_id", table_name="program_assignments")
    op.drop_index("ix_program_assignments_member_id", table_name="program_assignments")
    op.drop_table("program_assignments")
