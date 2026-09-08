"""Unique MOS reference: the same (source_system, source_entity, external_id)
cannot be inserted twice - e.g. MOS / member / 2636."""

from __future__ import annotations

import uuid

import pytest
from sqlalchemy.exc import IntegrityError

from app.db.models import ExternalReference, Member


def _member(session) -> Member:
    m = Member(status="active", first_name="Test", last_name="Member")
    session.add(m)
    session.flush()
    return m


def test_duplicate_mos_reference_fails(db_session):
    member = _member(db_session)
    db_session.add(
        ExternalReference(
            entity_type="member",
            entity_id=member.id,
            source_system="MOS",
            source_entity="member",
            external_id="2636",
        )
    )
    db_session.flush()

    # Second identical MOS reference must violate the unique constraint.
    db_session.add(
        ExternalReference(
            entity_type="member",
            entity_id=member.id,
            source_system="MOS",
            source_entity="member",
            external_id="2636",
        )
    )
    with pytest.raises(IntegrityError):
        db_session.flush()


def test_same_external_id_different_entity_is_allowed(db_session):
    """The uniqueness is on the triple, not external_id alone."""
    member = _member(db_session)
    db_session.add(
        ExternalReference(
            entity_type="member",
            entity_id=member.id,
            source_system="MOS",
            source_entity="member",
            external_id="2636",
        )
    )
    # Different source_entity -> allowed even with same external_id value.
    db_session.add(
        ExternalReference(
            entity_type="membership",
            entity_id=uuid.uuid4(),
            source_system="MOS",
            source_entity="membership",
            external_id="2636",
        )
    )
    db_session.flush()  # must not raise
