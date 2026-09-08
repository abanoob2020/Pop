"""012 audit_events - audit_events, domain_events

Revision ID: 0012
Revises: 0011
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB, UUID

from helpers import created_at, uuid_pk

revision: str = "0012"
down_revision: Union[str, None] = "0011"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "audit_events",
        uuid_pk(),
        sa.Column("actor", sa.String(160), nullable=False),
        sa.Column("action", sa.String(96), nullable=False),
        sa.Column("entity", sa.String(96), nullable=False),
        sa.Column("entity_id", UUID(as_uuid=True)),
        sa.Column("before_hash", sa.String(128)),
        sa.Column("after_hash", sa.String(128)),
        sa.Column("request_id", sa.String(128)),
        sa.Column("occurred_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        created_at(),
    )
    op.create_index("ix_audit_events_request_id", "audit_events", ["request_id"])
    op.create_index("ix_audit_events_occurred_at", "audit_events", ["occurred_at"])

    op.create_table(
        "domain_events",
        uuid_pk(),
        sa.Column("aggregate_type", sa.String(96), nullable=False),
        sa.Column("aggregate_id", UUID(as_uuid=True), nullable=False),
        sa.Column("event_type", sa.String(96), nullable=False),
        sa.Column("payload", JSONB()),
        sa.Column("occurred_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        created_at(),
    )
    op.create_index("ix_domain_events_aggregate_id", "domain_events", ["aggregate_id"])
    op.create_index("ix_domain_events_occurred_at", "domain_events", ["occurred_at"])


def downgrade() -> None:
    op.drop_index("ix_domain_events_occurred_at", table_name="domain_events")
    op.drop_index("ix_domain_events_aggregate_id", table_name="domain_events")
    op.drop_table("domain_events")
    op.drop_index("ix_audit_events_occurred_at", table_name="audit_events")
    op.drop_index("ix_audit_events_request_id", table_name="audit_events")
    op.drop_table("audit_events")
