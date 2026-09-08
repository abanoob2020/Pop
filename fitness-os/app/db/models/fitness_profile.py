"""003 - Fitness profile.

Generic profile + goals attached to a member. No assessment methodology or
business rules are encoded here - only descriptive data fields.
"""

from __future__ import annotations

import uuid
from datetime import date

from sqlalchemy import Date, ForeignKey, Numeric, String, Text, UniqueConstraint
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import TimestampMixin, UUIDPrimaryKeyMixin


class MemberFitnessProfile(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "member_fitness_profiles"
    __table_args__ = (
        UniqueConstraint("member_id", name="uq_member_fitness_profiles_member_id"),
    )

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False
    )
    training_experience: Mapped[str | None] = mapped_column(String(48))
    activity_level: Mapped[str | None] = mapped_column(String(48))
    primary_focus: Mapped[str | None] = mapped_column(String(96))
    height_cm: Mapped[float | None] = mapped_column(Numeric(6, 2))
    weight_kg: Mapped[float | None] = mapped_column(Numeric(6, 2))
    notes: Mapped[str | None] = mapped_column(Text)

    member = relationship("Member", back_populates="fitness_profile")


class MemberGoal(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "member_goals"

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    goal_type: Mapped[str] = mapped_column(String(64), nullable=False)
    description: Mapped[str | None] = mapped_column(Text)
    target_value: Mapped[float | None] = mapped_column(Numeric(12, 3))
    target_unit: Mapped[str | None] = mapped_column(String(32))
    target_date: Mapped[date | None] = mapped_column(Date)
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="active")
    priority: Mapped[int | None] = mapped_column()

    member = relationship("Member", back_populates="goals")
