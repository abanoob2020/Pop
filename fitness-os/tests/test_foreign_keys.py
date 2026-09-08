"""Referential integrity is enforced by real foreign keys, not just the app."""

from __future__ import annotations

import uuid

import pytest
from sqlalchemy.exc import IntegrityError

from app.db.models import (
    Exercise,
    Member,
    ProgramAssignment,
    ProgramSessionExercise,
    ProgramSession,
    WorkoutExercise,
    WorkoutSession,
    WorkoutSet,
)


def test_workout_for_missing_member_fails(db_session):
    db_session.add(WorkoutSession(member_id=uuid.uuid4()))
    with pytest.raises(IntegrityError):
        db_session.flush()


def test_program_exercise_for_missing_exercise_fails(db_session):
    # A program_session_exercise pointing at a non-existent exercise/session.
    db_session.add(
        ProgramSessionExercise(
            program_session_id=uuid.uuid4(),
            exercise_id=uuid.uuid4(),
        )
    )
    with pytest.raises(IntegrityError):
        db_session.flush()


def test_assignment_for_missing_program_version_fails(db_session):
    member = Member(first_name="A")
    db_session.add(member)
    db_session.flush()
    db_session.add(
        ProgramAssignment(member_id=member.id, program_version_id=uuid.uuid4())
    )
    with pytest.raises(IntegrityError):
        db_session.flush()


def test_workout_set_for_missing_workout_exercise_fails(db_session):
    db_session.add(WorkoutSet(workout_exercise_id=uuid.uuid4(), set_number=1))
    with pytest.raises(IntegrityError):
        db_session.flush()


def test_valid_chain_inserts_succeed(db_session):
    """The happy path: a real member + exercise chain inserts cleanly."""
    member = Member(first_name="Real")
    exercise = Exercise(name="Back Squat", slug="back-squat")
    db_session.add_all([member, exercise])
    db_session.flush()

    ws = WorkoutSession(member_id=member.id)
    db_session.add(ws)
    db_session.flush()

    we = WorkoutExercise(workout_session_id=ws.id, exercise_id=exercise.id, sequence=1)
    db_session.add(we)
    db_session.flush()

    db_session.add(WorkoutSet(workout_exercise_id=we.id, set_number=1, actual_reps=5))
    db_session.flush()  # must not raise
