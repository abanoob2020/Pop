"""002 - MOS read-only mirror.

These tables hold OBSERVED snapshots of data originating in MOS (the Member
Operating System). They are a read model only: the Fitness OS never writes back
to MOS. Each row records what was observed and when (``observed_at``) plus a
``source_hash`` for change detection. There is deliberately NO MOS client here.
"""

from __future__ import annotations

import uuid
from datetime import date, datetime

from sqlalchemy import (
    Date,
    DateTime,
    ForeignKey,
    Numeric,
    String,
    func,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base
from app.db.mixins import UUIDPrimaryKeyMixin


class MembershipSnapshot(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "membership_snapshots"

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    source_system: Mapped[str] = mapped_column(String(64), nullable=False, server_default="MOS")
    external_membership_id: Mapped[str | None] = mapped_column(String(128))
    package_name: Mapped[str | None] = mapped_column(String(160))
    start_date: Mapped[date | None] = mapped_column(Date)
    expiry_date: Mapped[date | None] = mapped_column(Date, index=True)
    status: Mapped[str | None] = mapped_column(String(48))
    paid_price: Mapped[float | None] = mapped_column(Numeric(12, 2))
    salesperson_name: Mapped[str | None] = mapped_column(String(160))
    observed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    source_hash: Mapped[str | None] = mapped_column(String(128))


class AttendanceEvent(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "attendance_events"

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    branch_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("branches.id", ondelete="RESTRICT")
    )
    source_system: Mapped[str] = mapped_column(String(64), nullable=False, server_default="MOS")
    external_event_id: Mapped[str | None] = mapped_column(String(128))
    checked_in_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, index=True)
    checked_out_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    observed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    source_hash: Mapped[str | None] = mapped_column(String(128))
