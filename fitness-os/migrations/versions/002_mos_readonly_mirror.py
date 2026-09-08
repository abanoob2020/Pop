"""002 mos_readonly_mirror - membership_snapshots, attendance_events

Revision ID: 0002
Revises: 0001
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import fk, uuid_pk

revision: str = "0002"
down_revision: Union[str, None] = "0001"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "membership_snapshots",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("source_system", sa.String(64), nullable=False, server_default=sa.text("'MOS'")),
        sa.Column("external_membership_id", sa.String(128)),
        sa.Column("package_name", sa.String(160)),
        sa.Column("start_date", sa.Date()),
        sa.Column("expiry_date", sa.Date()),
        sa.Column("status", sa.String(48)),
        sa.Column("paid_price", sa.Numeric(12, 2)),
        sa.Column("salesperson_name", sa.String(160)),
        sa.Column("observed_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("source_hash", sa.String(128)),
    )
    op.create_index("ix_membership_snapshots_member_id", "membership_snapshots", ["member_id"])
    op.create_index("ix_membership_snapshots_expiry_date", "membership_snapshots", ["expiry_date"])

    op.create_table(
        "attendance_events",
        uuid_pk(),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("branch_id", UUID(as_uuid=True), fk("branches.id")),
        sa.Column("source_system", sa.String(64), nullable=False, server_default=sa.text("'MOS'")),
        sa.Column("external_event_id", sa.String(128)),
        sa.Column("checked_in_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("checked_out_at", sa.DateTime(timezone=True)),
        sa.Column("observed_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("source_hash", sa.String(128)),
    )
    op.create_index("ix_attendance_events_member_id", "attendance_events", ["member_id"])
    op.create_index("ix_attendance_events_checked_in_at", "attendance_events", ["checked_in_at"])


def downgrade() -> None:
    op.drop_index("ix_attendance_events_checked_in_at", table_name="attendance_events")
    op.drop_index("ix_attendance_events_member_id", table_name="attendance_events")
    op.drop_table("attendance_events")
    op.drop_index("ix_membership_snapshots_expiry_date", table_name="membership_snapshots")
    op.drop_index("ix_membership_snapshots_member_id", table_name="membership_snapshots")
    op.drop_table("membership_snapshots")
