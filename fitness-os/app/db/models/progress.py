"""010 - Progress intelligence.

Only the raw source table (``body_measurements``) is stored. Derived metrics
(estimated 1RM, volume trend, adherence, PRs) are intentionally NOT materialised
as separate tables - they are computed later from source data via views/services.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import DateTime, ForeignKey, Numeric, String, Text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base
from app.db.mixins import CreatedAtMixin, UUIDPrimaryKeyMixin


class BodyMeasurement(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "body_measurements"

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    measured_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, index=True)
    weight_kg: Mapped[float | None] = mapped_column(Numeric(6, 2))
    height_cm: Mapped[float | None] = mapped_column(Numeric(6, 2))
    body_fat_pct: Mapped[float | None] = mapped_column(Numeric(5, 2))
    waist_cm: Mapped[float | None] = mapped_column(Numeric(6, 2))
    hip_cm: Mapped[float | None] = mapped_column(Numeric(6, 2))
    chest_cm: Mapped[float | None] = mapped_column(Numeric(6, 2))
    arm_cm: Mapped[float | None] = mapped_column(Numeric(6, 2))
    thigh_cm: Mapped[float | None] = mapped_column(Numeric(6, 2))
    resting_hr: Mapped[int | None] = mapped_column()
    source: Mapped[str | None] = mapped_column(String(48))
    notes: Mapped[str | None] = mapped_column(Text)
