"""Program version history: a member is assigned to an immutable program VERSION,
never directly to the mutable template. Changing the template afterwards must not
alter what the member was assigned."""

from __future__ import annotations

from sqlalchemy import inspect

from app.db.models import (
    Member,
    ProgramAssignment,
    ProgramTemplate,
    ProgramVersion,
)


def test_assignment_references_version_not_template():
    """The FK column on program_assignments points at program_versions."""
    fks = {fk.column.table.name for fk in ProgramAssignment.__table__.foreign_keys}
    assert "program_versions" in fks
    assert "program_templates" not in fks
    assert hasattr(ProgramAssignment, "program_version_id")
    assert not hasattr(ProgramAssignment, "template_id")


def test_assignment_bound_to_version_snapshot(db_session):
    member = Member(first_name="Ver")
    template = ProgramTemplate(name="Strength Base")
    db_session.add_all([member, template])
    db_session.flush()

    v1 = ProgramVersion(template_id=template.id, version_number=1, status="published")
    db_session.add(v1)
    db_session.flush()

    assignment = ProgramAssignment(member_id=member.id, program_version_id=v1.id)
    db_session.add(assignment)
    db_session.flush()

    # A NEW version of the same template does not change the existing assignment.
    v2 = ProgramVersion(template_id=template.id, version_number=2, status="published")
    db_session.add(v2)
    db_session.flush()

    db_session.refresh(assignment)
    assert assignment.program_version_id == v1.id
    assert assignment.program_version_id != v2.id


def test_version_number_unique_per_template(db_session):
    import pytest
    from sqlalchemy.exc import IntegrityError

    template = ProgramTemplate(name="Hypertrophy")
    db_session.add(template)
    db_session.flush()
    db_session.add(ProgramVersion(template_id=template.id, version_number=1))
    db_session.flush()
    db_session.add(ProgramVersion(template_id=template.id, version_number=1))
    with pytest.raises(IntegrityError):
        db_session.flush()
