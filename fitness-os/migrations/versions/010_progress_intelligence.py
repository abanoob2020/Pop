"""010 progress_intelligence - body_measurements

Revision ID: 0010
Revises: 0009
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import created_at, fk, uuid_pk

revision: str = "0010"
down_revision: Union[str, None] = "0009"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "body_measurements",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("measured_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("weight_kg", sa.Numeric(6, 2)),
        sa.Column("height_cm", sa.Numeric(6, 2)),
        sa.Column("body_fat_pct", sa.Numeric(5, 2)),
        sa.Column("waist_cm", sa.Numeric(6, 2)),
        sa.Column("hip_cm", sa.Numeric(6, 2)),
        sa.Column("chest_cm", sa.Numeric(6, 2)),
        sa.Column("arm_cm", sa.Numeric(6, 2)),
        sa.Column("thigh_cm", sa.Numeric(6, 2)),
        sa.Column("resting_hr", sa.Integer()),
        sa.Column("source", sa.String(48)),
        sa.Column("notes", sa.Text()),
        created_at(),
    )
    op.create_index("ix_body_measurements_member_id", "body_measurements", ["member_id"])
    op.create_index("ix_body_measurements_measured_at", "body_measurements", ["measured_at"])


def downgrade() -> None:
    op.drop_index("ix_body_measurements_measured_at", table_name="body_measurements")
    op.drop_index("ix_body_measurements_member_id", table_name="body_measurements")
    op.drop_table("body_measurements")
