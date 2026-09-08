"""004 - Assessment interface (generic).

A deliberately generic assessment model. It stores metrics and flags as open
key/value rows so NO specific methodology (five-layer, Green/Yellow/Red, PT
prescription rules) is baked into the schema as policy. Those remain application
concerns and can evolve without a migration.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import DateTime, ForeignKey, Numeric, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import CreatedAtMixin, TimestampMixin, UUIDPrimaryKeyMixin


class Assessment(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "assessments"

    member_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("members.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    coach_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("coaches.id", ondelete="RESTRICT")
    )
    assessment_type: Mapped[str] = mapped_column(String(64), nullable=False)
    status: Mapped[str] = mapped_column(String(32), nullable=False, server_default="draft")
    performed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    notes: Mapped[str | None] = mapped_column(Text)

    # CASCADE: metrics/flags are owned exclusively by their assessment (see
    # docs/DELETION_POLICY.md). Deleting an assessment removes its own detail
    # rows; no operational history lives here.
    metrics = relationship(
        "AssessmentMetric", back_populates="assessment", passive_deletes=True
    )
    flags = relationship(
        "AssessmentFlag", back_populates="assessment", passive_deletes=True
    )


class AssessmentMetric(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "assessment_metrics"

    assessment_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("assessments.id", ondelete="CASCADE"), nullable=False, index=True
    )
    metric_key: Mapped[str] = mapped_column(String(96), nullable=False)
    metric_value_numeric: Mapped[float | None] = mapped_column(Numeric(14, 4))
    metric_value_text: Mapped[str | None] = mapped_column(Text)
    unit: Mapped[str | None] = mapped_column(String(32))

    assessment = relationship("Assessment", back_populates="metrics")


class AssessmentFlag(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "assessment_flags"

    assessment_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("assessments.id", ondelete="CASCADE"), nullable=False, index=True
    )
    flag_key: Mapped[str] = mapped_column(String(96), nullable=False)
    flag_value: Mapped[str | None] = mapped_column(Text)
    severity: Mapped[str | None] = mapped_column(String(32))

    assessment = relationship("Assessment", back_populates="flags")
