"""Deletion policy: operational history is protected by RESTRICT; only documented
owned-config subtrees CASCADE (see docs/DELETION_POLICY.md)."""

from __future__ import annotations

import pytest
from sqlalchemy import text
from sqlalchemy.exc import IntegrityError

from app.db.models import (
    Assessment,
    AssessmentMetric,
    Exercise,
    Member,
    WorkoutSession,
)


def test_member_with_workout_history_cannot_be_hard_deleted(db_session):
    """RESTRICT: deleting a member who has workout history must fail, so operational
    history cannot silently vanish. (Soft-delete via members.status is the path.)"""
    member = Member(first_name="History")
    db_session.add(member)
    db_session.flush()
    db_session.add(WorkoutSession(member_id=member.id))
    db_session.flush()

    with pytest.raises(IntegrityError):
        db_session.execute(text("DELETE FROM members WHERE id = :i"), {"i": str(member.id)})


def test_used_exercise_cannot_be_hard_deleted(db_session):
    """RESTRICT: an exercise referenced by a workout is protected."""
    from app.db.models import WorkoutExercise

    member = Member(first_name="Ex")
    exercise = Exercise(name="Deadlift", slug="deadlift")
    db_session.add_all([member, exercise])
    db_session.flush()
    ws = WorkoutSession(member_id=member.id)
    db_session.add(ws)
    db_session.flush()
    db_session.add(WorkoutExercise(workout_session_id=ws.id, exercise_id=exercise.id))
    db_session.flush()

    with pytest.raises(IntegrityError):
        db_session.execute(text("DELETE FROM exercises WHERE id = :i"), {"i": str(exercise.id)})


def test_assessment_children_cascade(db_session):
    """CASCADE (documented): assessment metrics are owned by their assessment."""
    member = Member(first_name="Assess")
    db_session.add(member)
    db_session.flush()
    a = Assessment(member_id=member.id, assessment_type="movement")
    db_session.add(a)
    db_session.flush()
    db_session.add(AssessmentMetric(assessment_id=a.id, metric_key="squat_depth", metric_value_numeric=1))
    db_session.flush()

    db_session.execute(text("DELETE FROM assessments WHERE id = :i"), {"i": str(a.id)})
    db_session.flush()  # must NOT raise - children cascade

    remaining = db_session.execute(
        text("SELECT count(*) FROM assessment_metrics WHERE assessment_id = :i"),
        {"i": str(a.id)},
    ).scalar()
    assert remaining == 0
