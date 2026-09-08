"""005 - Exercise library.

Exercises plus their descriptors. ``exercise_media`` carries licensing metadata
(``license_code`` / ``attribution`` / ``verified_at``) so assets of unknown
license can be identified and kept out of the library.

Descriptor tables (muscles / equipment / media / instructions) CASCADE from
``exercises`` because they are pure descriptors owned by the exercise. An
exercise that is referenced by a program or workout is itself protected from
deletion by RESTRICT foreign keys in those aggregates.
"""

from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, Integer, String, Text
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base
from app.db.mixins import CreatedAtMixin, TimestampMixin, UUIDPrimaryKeyMixin


class Exercise(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "exercises"

    name: Mapped[str] = mapped_column(String(160), nullable=False)
    slug: Mapped[str] = mapped_column(String(180), nullable=False, unique=True)
    category: Mapped[str | None] = mapped_column(String(64))
    movement_pattern: Mapped[str | None] = mapped_column(String(64))
    mechanics: Mapped[str | None] = mapped_column(String(32))
    force_type: Mapped[str | None] = mapped_column(String(32))
    difficulty: Mapped[str | None] = mapped_column(String(32))
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default="true")

    muscles = relationship("ExerciseMuscle", back_populates="exercise", passive_deletes=True)
    equipment = relationship("ExerciseEquipment", back_populates="exercise", passive_deletes=True)
    media = relationship("ExerciseMedia", back_populates="exercise", passive_deletes=True)
    instructions = relationship(
        "ExerciseInstruction", back_populates="exercise", passive_deletes=True
    )


class ExerciseMuscle(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "exercise_muscles"

    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="CASCADE"), nullable=False, index=True
    )
    muscle: Mapped[str] = mapped_column(String(96), nullable=False)
    role: Mapped[str] = mapped_column(String(32), nullable=False, server_default="primary")

    exercise = relationship("Exercise", back_populates="muscles")


class ExerciseEquipment(UUIDPrimaryKeyMixin, Base):
    __tablename__ = "exercise_equipment"

    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="CASCADE"), nullable=False, index=True
    )
    equipment: Mapped[str] = mapped_column(String(96), nullable=False)

    exercise = relationship("Exercise", back_populates="equipment")


class ExerciseMedia(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "exercise_media"

    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="CASCADE"), nullable=False, index=True
    )
    media_type: Mapped[str] = mapped_column(String(32), nullable=False)
    url: Mapped[str] = mapped_column(Text, nullable=False)
    # Licensing metadata - required by the canonical model so no asset of unknown
    # license silently enters the library.
    license_code: Mapped[str | None] = mapped_column(String(64))
    attribution: Mapped[str | None] = mapped_column(Text)
    verified_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    exercise = relationship("Exercise", back_populates="media")


class ExerciseInstruction(UUIDPrimaryKeyMixin, CreatedAtMixin, Base):
    __tablename__ = "exercise_instructions"

    exercise_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("exercises.id", ondelete="CASCADE"), nullable=False, index=True
    )
    step_number: Mapped[int] = mapped_column(Integer, nullable=False)
    instruction: Mapped[str] = mapped_column(Text, nullable=False)

    exercise = relationship("Exercise", back_populates="instructions")
