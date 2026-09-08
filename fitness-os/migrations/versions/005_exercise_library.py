"""005 exercise_library - exercises + descriptors (muscles/equipment/media/instructions)

Revision ID: 0005
Revises: 0004
Create Date: 2026-09-08
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import UUID

from helpers import created_at, fk, timestamps, uuid_pk

revision: str = "0005"
down_revision: Union[str, None] = "0004"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "exercises",
        uuid_pk(),
        sa.Column("name", sa.String(160), nullable=False),
        sa.Column("slug", sa.String(180), nullable=False),
        sa.Column("category", sa.String(64)),
        sa.Column("movement_pattern", sa.String(64)),
        sa.Column("mechanics", sa.String(32)),
        sa.Column("force_type", sa.String(32)),
        sa.Column("difficulty", sa.String(32)),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("true")),
        *timestamps(),
        sa.UniqueConstraint("slug", name="uq_exercises_slug"),
    )

    op.create_table(
        "exercise_muscles",
        uuid_pk(),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id", ondelete="CASCADE"), nullable=False),
        sa.Column("muscle", sa.String(96), nullable=False),
        sa.Column("role", sa.String(32), nullable=False, server_default=sa.text("'primary'")),
    )
    op.create_index("ix_exercise_muscles_exercise_id", "exercise_muscles", ["exercise_id"])

    op.create_table(
        "exercise_equipment",
        uuid_pk(),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id", ondelete="CASCADE"), nullable=False),
        sa.Column("equipment", sa.String(96), nullable=False),
    )
    op.create_index("ix_exercise_equipment_exercise_id", "exercise_equipment", ["exercise_id"])

    op.create_table(
        "exercise_media",
        uuid_pk(),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id", ondelete="CASCADE"), nullable=False),
        sa.Column("media_type", sa.String(32), nullable=False),
        sa.Column("url", sa.Text(), nullable=False),
        sa.Column("license_code", sa.String(64)),
        sa.Column("attribution", sa.Text()),
        sa.Column("verified_at", sa.DateTime(timezone=True)),
        created_at(),
    )
    op.create_index("ix_exercise_media_exercise_id", "exercise_media", ["exercise_id"])

    op.create_table(
        "exercise_instructions",
        uuid_pk(),
        sa.Column("exercise_id", UUID(as_uuid=True), fk("exercises.id", ondelete="CASCADE"), nullable=False),
        sa.Column("step_number", sa.Integer(), nullable=False),
        sa.Column("instruction", sa.Text(), nullable=False),
        created_at(),
    )
    op.create_index("ix_exercise_instructions_exercise_id", "exercise_instructions", ["exercise_id"])


def downgrade() -> None:
    op.drop_index("ix_exercise_instructions_exercise_id", table_name="exercise_instructions")
    op.drop_table("exercise_instructions")
    op.drop_index("ix_exercise_media_exercise_id", table_name="exercise_media")
    op.drop_table("exercise_media")
    op.drop_index("ix_exercise_equipment_exercise_id", table_name="exercise_equipment")
    op.drop_table("exercise_equipment")
    op.drop_index("ix_exercise_muscles_exercise_id", table_name="exercise_muscles")
    op.drop_table("exercise_muscles")
    op.drop_table("exercises")
