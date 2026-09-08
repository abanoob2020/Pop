"""Prescribed vs performed separation: editing an actual workout set must NOT
change the underlying set prescription."""

from __future__ import annotations

from app.db.models import (
    Exercise,
    Member,
    ProgramPhase,
    ProgramSession,
    ProgramSessionExercise,
    ProgramTemplate,
    ProgramVersion,
    ProgramWeek,
    SetPrescription,
    WorkoutExercise,
    WorkoutSession,
    WorkoutSet,
)


def _build_prescription(session):
    member = Member(first_name="Perf")
    exercise = Exercise(name="Bench Press", slug="bench-press")
    template = ProgramTemplate(name="PPL")
    session.add_all([member, exercise, template])
    session.flush()

    version = ProgramVersion(template_id=template.id, version_number=1, status="published")
    session.add(version)
    session.flush()
    phase = ProgramPhase(program_version_id=version.id, name="Accumulation", sequence=1)
    session.add(phase)
    session.flush()
    week = ProgramWeek(program_phase_id=phase.id, week_number=1)
    session.add(week)
    session.flush()
    psession = ProgramSession(program_week_id=week.id, name="Push")
    session.add(psession)
    session.flush()
    pse = ProgramSessionExercise(program_session_id=psession.id, exercise_id=exercise.id, sequence=1)
    session.add(pse)
    session.flush()
    presc = SetPrescription(
        program_session_exercise_id=pse.id,
        set_number=1,
        target_reps=8,
        target_load=100.0,
        target_load_unit="kg",
    )
    session.add(presc)
    session.flush()
    return member, exercise, presc


def test_editing_actual_set_does_not_change_prescription(db_session):
    member, exercise, presc = _build_prescription(db_session)

    ws = WorkoutSession(member_id=member.id)
    db_session.add(ws)
    db_session.flush()
    we = WorkoutExercise(workout_session_id=ws.id, exercise_id=exercise.id, sequence=1)
    db_session.add(we)
    db_session.flush()

    # Copy prescription -> planned, record actuals.
    wset = WorkoutSet(
        workout_exercise_id=we.id,
        set_prescription_id=presc.id,
        set_number=1,
        planned_reps=presc.target_reps,
        planned_load=float(presc.target_load),
        actual_reps=6,
        actual_load=95.0,
        load_unit="kg",
    )
    db_session.add(wset)
    db_session.flush()

    # Mutate the performed values.
    wset.actual_reps = 5
    wset.actual_load = 90.0
    db_session.flush()

    db_session.refresh(presc)
    # Prescription is untouched.
    assert presc.target_reps == 8
    assert float(presc.target_load) == 100.0
    # Performed differs from prescribed.
    assert wset.actual_reps == 5
    assert float(wset.actual_load) == 90.0
    assert wset.planned_reps == 8


def test_workout_set_has_both_planned_and_actual_columns():
    cols = set(WorkoutSet.__table__.columns.keys())
    for c in ("planned_reps", "planned_load", "actual_reps", "actual_load"):
        assert c in cols
