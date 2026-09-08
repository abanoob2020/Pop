"""001 - Identity layer.

The Fitness OS owns its own identity for members, branches and coaches. It NEVER
uses an MOS id as a primary key: MOS ids are recorded only in
``external_references`` so that XCAMP UUID <-> MOS id resolution stays in one
dedicated, auditable place.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, String, Text, UniqueConstraint, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import TimestampMixin, UUIDPrimaryKeyMixin


class Member(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "members"

    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="active")
    first_name: Mapped[str | None] = mapped_column(String(120))
    last_name: Mapped[str | None] = mapped_column(String(120))
    phone: Mapped[str | None] = mapped_column(String(32), index=True)
    email: Mapped[str | None] = mapped_column(String(255))
    gender: Mapped[str | None] = mapped_column(String(16))

    goals = relationship("MemberGoal", back_populates="member")
    fitness_profile = relationship("MemberFitnessProfile", back_populates="member", uselist=False)


class ExternalReference(UUIDPrimaryKeyMixin, Base):
    """Maps an internal Fitness OS entity to an id in a source system (e.g. MOS).

    This is the ONLY place MOS ids live. The unique constraint guarantees a
    single source id maps to exactly one internal entity.
    """

    __tablename__ = "external_references"
    __table_args__ = (
        UniqueConstraint(
            "source_system",
            "source_entity",
            "external_id",
            name="external_references_source_uindex",
        ),
    )

    entity_type: Mapped[str] = mapped_column(String(64), nullable=False)
    entity_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    source_system: Mapped[str] = mapped_column(String(64), nullable=False)
    source_entity: Mapped[str] = mapped_column(String(64), nullable=False)
    external_id: Mapped[str] = mapped_column(String(128), nullable=False)
    first_seen_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    source_payload_hash: Mapped[str | None] = mapped_column(String(128))


class Branch(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "branches"

    name: Mapped[str] = mapped_column(String(160), nullable=False)
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="active")
    timezone: Mapped[str] = mapped_column(String(64), nullable=False, server_default="UTC")


class Coach(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "coaches"

    # Nullable link to an application user record (owned elsewhere); no FK, the
    # user directory is outside this schema's aggregate boundary.
    user_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True))
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="active")
    display_name: Mapped[str] = mapped_column(String(160), nullable=False)


class CoachMemberAssignment(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "coach_member_assignments"

    coach_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("coaches.id", ondelete="RESTRICT"), nullable=False
    )
    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False
    )
    assignment_type: Mapped[str] = mapped_column(String(48), nullable=False, server_default="primary")
    starts_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    ends_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    active: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default="true")
