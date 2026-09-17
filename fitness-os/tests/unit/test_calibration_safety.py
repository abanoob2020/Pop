import copy
from concurrent.futures import ThreadPoolExecutor

import pytest

from app.walking_skeleton.pipeline import PilotPolicy, SnapshotValidationError, build_run, persist_run
from test_walking_skeleton import sample_snapshot


def codes(run):
    return [reason["code"] for d in run["decisions"] for reason in d["reasons"]]


def test_missing_coverage_is_not_zero_attendance(sample_snapshot):
    sample_snapshot.pop("attendance_coverage")
    sample_snapshot["attendance"] = []
    run = build_run(sample_snapshot)
    assert codes(run) == ["RENEWAL_DUE"]
    assert run["attendance_evaluation"].startswith("unavailable")


@pytest.mark.parametrize("complete,through", [(False, "2026-09-17T00:00:00Z"),
                                             (True, "2026-09-16T00:00:00Z")])
def test_partial_or_stale_coverage_disables_absence(sample_snapshot, complete, through):
    sample_snapshot["attendance_coverage"].update(complete=complete, through=through)
    assert codes(build_run(sample_snapshot)) == ["RENEWAL_DUE"]


def test_no_visits_with_complete_coverage_can_raise_absence(sample_snapshot):
    sample_snapshot["attendance"] = []
    assert codes(build_run(sample_snapshot)).count("ATTENDANCE_GAP") == 2


def test_new_member_without_visits_not_flagged_too_early(sample_snapshot):
    sample_snapshot["attendance"] = []
    sample_snapshot["memberships"][1]["start_date"] = "2026-09-16"
    run = build_run(sample_snapshot)
    assert all(d["member_external_id"] != "1002" for d in run["decisions"])


@pytest.mark.parametrize("start,end,status", [
    ("2026-09-18", "2026-10-01", "active"),
    ("2026-01-01", "2026-09-16", "active"),
    ("2026-06-30", "2026-09-30", "freezed"),
    ("2026-06-30", "2026-09-30", "suspended"),
])
def test_ineligible_memberships_do_not_create_tasks(sample_snapshot, start, end, status):
    sample_snapshot["memberships"][0].update(start_date=start, expiry_date=end, status=status)
    assert build_run(sample_snapshot)["tasks"] == []


def test_future_noncontiguous_contract_does_not_hide_current_expiry(sample_snapshot):
    future = copy.deepcopy(sample_snapshot["memberships"][0])
    future.update(external_membership_id="future", start_date="2026-12-01", expiry_date="2027-01-01")
    sample_snapshot["memberships"].append(future)
    assert "RENEWAL_DUE" in codes(build_run(sample_snapshot))


def test_dates_evaluated_in_business_timezone(sample_snapshot):
    sample_snapshot["timezone"] = "Africa/Cairo"
    sample_snapshot["as_of"] = sample_snapshot["observed_at"] = "2026-09-16T22:00:00Z"
    sample_snapshot.pop("attendance_coverage")
    sample_snapshot["memberships"][0]["expiry_date"] = "2026-09-16"
    assert build_run(sample_snapshot)["tasks"] == []


@pytest.mark.parametrize("field,value", [("attendance_gap_days", True),
                                        ("renewal_window_days", 2.5), ("version", "")])
def test_invalid_policy_rejected(field, value):
    with pytest.raises(ValueError):
        PilotPolicy(**{field: value})


def test_inverted_dates_rejected(sample_snapshot):
    sample_snapshot["memberships"][0]["start_date"] = "2027-01-01"
    with pytest.raises(SnapshotValidationError, match="starts after expiry"):
        build_run(sample_snapshot)


def test_missing_status_not_assumed_active(sample_snapshot):
    sample_snapshot["members"][0].pop("status")
    with pytest.raises(SnapshotValidationError):
        build_run(sample_snapshot)


def test_event_after_extraction_rejected(sample_snapshot):
    sample_snapshot["observed_at"] = "2026-09-10T00:00:00Z"
    sample_snapshot.pop("attendance_coverage")
    with pytest.raises(SnapshotValidationError, match="after observed_at"):
        build_run(sample_snapshot)


def test_invalid_run_path_rejected(tmp_path):
    with pytest.raises(ValueError):
        persist_run({"run_id": "../escape"}, tmp_path)
    assert not list(tmp_path.iterdir())


def test_concurrent_retry_publishes_once(sample_snapshot, tmp_path):
    run = build_run(sample_snapshot)
    with ThreadPoolExecutor(max_workers=8) as pool:
        statuses = list(pool.map(lambda _: persist_run(run, tmp_path)[1], range(30)))
    assert statuses.count("created") == 1
    assert statuses.count("unchanged") == 29
    assert len(list(tmp_path.iterdir())) == 1


@pytest.mark.parametrize("days,expected", [(9, False), (10, True), (11, True)])
def test_exact_absence_boundary(sample_snapshot, days, expected):
    from datetime import datetime, timedelta, timezone
    sample_snapshot["attendance"][0]["checked_in_at"] = (
        datetime(2026, 9, 17, tzinfo=timezone.utc) - timedelta(days=days)).isoformat()
    assert ("ATTENDANCE_GAP" in codes(build_run(sample_snapshot))) is expected


@pytest.mark.parametrize("expiry,expected", [("2026-09-17", True), ("2026-10-17", True),
                                           ("2026-10-18", False)])
def test_renewal_window_boundary(sample_snapshot, expiry, expected):
    sample_snapshot["memberships"][0]["expiry_date"] = expiry
    assert ("RENEWAL_DUE" in codes(build_run(sample_snapshot))) is expected
