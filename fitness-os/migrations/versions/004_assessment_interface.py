"""004 assessment_interface - assessments, assessment_metrics, assessment_flags

Revision ID: 0004
Revises: 0003
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import created_at, fk, timestamps, uuid_pk

revision: str = "0004"
down_revision: Union[str, None] = "0003"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "assessments",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("coach_id", UUID(as_uuid=True), fk("coaches.id")),
        sa.Column("assessment_type", sa.String(64), nullable=False),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'draft'")),
        sa.Column("performed_at", sa.DateTime(timezone=True)),
        sa.Column("notes", sa.Text()),
        *timestamps(),
    )
    op.create_index("ix_assessments_member_id", "assessments", ["member_id"])

    op.create_table(
        "assessment_metrics",
        uuid_pk(),
        sa.Column("assessment_id", UUID(as_uuid=True), fk("assessments.id", ondelete="CASCADE"), nullable=False),
        sa.Column("metric_key", sa.String(96), nullable=False),
        sa.Column("metric_value_numeric", sa.Numeric(14, 4)),
        sa.Column("metric_value_text", sa.Text()),
        sa.Column("unit", sa.String(32)),
        created_at(),
    )
    op.create_index("ix_assessment_metrics_assessment_id", "assessment_metrics", ["assessment_id"])

    op.create_table(
        "assessment_flags",
        uuid_pk(),
        sa.Column("assessment_id", UUID(as_uuid=True), fk("assessments.id", ondelete="CASCADE"), nullable=False),
        sa.Column("flag_key", sa.String(96), nullable=False),
        sa.Column("flag_value", sa.Text()),
        sa.Column("severity", sa.String(32)),
        created_at(),
    )
    op.create_index("ix_assessment_flags_assessment_id", "assessment_flags", ["assessment_id"])


def downgrade() -> None:
    op.drop_index("ix_assessment_flags_assessment_id", table_name="assessment_flags")
    op.drop_table("assessment_flags")
    op.drop_index("ix_assessment_metrics_assessment_id", table_name="assessment_metrics")
    op.drop_table("assessment_metrics")
    op.drop_index("ix_assessments_member_id", table_name="assessments")
    op.drop_table("assessments")
