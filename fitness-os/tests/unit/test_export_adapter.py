from datetime import date

import pytest

from scripts.calibrate_renewal_export import snapshot_from_rows
from app.walking_skeleton.pipeline import build_run


def row(code="synthetic-1", start=date(2026, 8, 1), status="Active", core="Yes"):
    return {"كود العضوية": code, "تاريخ البدء": start,
            "تاريخ الانتهاء": date(2026, 9, 30), "الحالة": status, "Core Membership?": core}


def test_missing_dates_quarantine_entire_member():
    snapshot, q = snapshot_from_rows([(2, row()), (3, row(start=None)),
                                     (4, row(code="synthetic-2"))], "synthetic-hash", date(2026, 9, 16))
    assert q["excluded_member_codes"] == 1
    assert len(snapshot["members"]) == 1
    assert snapshot["members"][0]["external_id"] == "synthetic-2"
    assert len(snapshot["memberships"]) == 1


def test_noncore_and_frozen_do_not_produce_contacts():
    snapshot, _ = snapshot_from_rows([(2, row(core="No")),
                                     (3, row(code="synthetic-2", status="Freezed"))],
                                    "synthetic-hash", date(2026, 9, 16))
    run = build_run(snapshot)
    assert run["tasks"] == []
    assert run["attendance_evaluation"].startswith("unavailable")


def test_export_row_identity_is_not_claimed_as_mos_identity():
    snapshot, _ = snapshot_from_rows([(2, row())], "synthetic-hash", date(2026, 9, 16))
    assert snapshot["memberships"][0]["external_membership_id"] == "export-row:synthetic-hash:2"
    assert len(build_run(snapshot)["tasks"]) == 1


def test_unknown_core_classification_fails_closed():
    with pytest.raises(ValueError, match="Unclassified"):
        snapshot_from_rows([(2, row(core=None))], "synthetic-hash", date(2026, 9, 16))
