"""001 identity - members, external_references, branches, coaches, assignments

Revision ID: 0001
Revises:
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import fk, timestamps, uuid_pk

revision: str = "0001"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "members",
        uuid_pk(),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'active'")),
        sa.Column("first_name", sa.String(120)),
        sa.Column("last_name", sa.String(120)),
        sa.Column("phone", sa.String(32)),
        sa.Column("email", sa.String(255)),
        sa.Column("gender", sa.String(16)),
        *timestamps(),
    )
    op.create_index("ix_members_phone", "members", ["phone"])

    op.create_table(
        "external_references",
        uuid_pk(),
        sa.Column("entity_type", sa.String(64), nullable=False),
        sa.Column("entity_id", UUID(as_uuid=True), nullable=False),
        sa.Column("source_system", sa.String(64), nullable=False),
        sa.Column("source_entity", sa.String(64), nullable=False),
        sa.Column("external_id", sa.String(128), nullable=False),
        sa.Column("first_seen_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("last_synced_at", sa.DateTime(timezone=True)),
        sa.Column("source_payload_hash", sa.String(128)),
        sa.UniqueConstraint(
            "source_system",
            "source_entity",
            "external_id",
            name="external_references_source_uindex",
        ),
    )

    op.create_table(
        "branches",
        uuid_pk(),
        sa.Column("name", sa.String(160), nullable=False),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'active'")),
        sa.Column("timezone", sa.String(64), nullable=False, server_default=sa.text("'UTC'")),
        *timestamps(),
    )

    op.create_table(
        "coaches",
        uuid_pk(),
        sa.Column("user_id", UUID(as_uuid=True)),
        sa.Column("status", sa.String(32), nullable=False, server_default=sa.text("'active'")),
        sa.Column("display_name", sa.String(160), nullable=False),
        *timestamps(),
    )

    op.create_table(
        "coach_member_assignments",
        uuid_pk(),
        sa.Column("coach_id", UUID(as_uuid=True), fk("coaches.id"), nullable=False),
        sa.Column("member_id", UUID(as_uuid=True), fk("members.id"), nullable=False),
        sa.Column("assignment_type", sa.String(48), nullable=False, server_default=sa.text("'primary'")),
        sa.Column("starts_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.text("now()")),
        sa.Column("ends_at", sa.DateTime(timezone=True)),
        sa.Column("active", sa.Boolean(), nullable=False, server_default=sa.text("true")),
    )


def downgrade() -> None:
    op.drop_table("coach_member_assignments")
    op.drop_table("coaches")
    op.drop_table("branches")
    op.drop_table("external_references")
    op.drop_index("ix_members_phone", table_name="members")
    op.drop_table("members")
