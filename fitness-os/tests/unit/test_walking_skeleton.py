from __future__ import annotations

import copy
import json
from pathlib import Path

import pytest

from app.walking_skeleton.pipeline import (
    PilotPolicy,
    SnapshotValidationError,
    build_run,
    canonical_json_bytes,
    persist_run,
)

PROJECT_ROOT = Path(__file__).resolve().parents[2]
SAMPLE_PATH = PROJECT_ROOT / "examples" / "mos_snapshot.sample.json"


@pytest.fixture()
def sample_snapshot():
    return json.loads(SAMPLE_PATH.read_text(encoding="utf-8"))


def test_same_input_and_policy_produce_identical_bytes(sample_snapshot):
    first = build_run(sample_snapshot)
    second = build_run(copy.deepcopy(sample_snapshot))

    assert first["run_id"] == second["run_id"]
    assert canonical_json_bytes(first) == canonical_json_bytes(second)


def test_source_row_order_does_not_change_output(sample_snapshot):
    reordered = copy.deepcopy(sample_snapshot)
    reordered["members"].reverse()
    reordered["memberships"].reverse()
    reordered["attendance"].reverse()

    assert canonical_json_bytes(build_run(sample_snapshot)) == canonical_json_bytes(
        build_run(reordered)
    )


def test_sample_creates_one_explainable_high_risk_task(sample_snapshot):
    result = build_run(sample_snapshot)

    assert result["counts"] == {"members": 3, "decisions": 1, "tasks": 1}
    assert result["mode"] == "calibration_only"
    assert result["source"]["access"] == "supplied_snapshot_read_only"
    assert result["decisions"][0]["member_external_id"] == "1001"
    assert result["decisions"][0]["risk_score"] == 80
    assert result["decisions"][0]["severity"] == "high"
    assert result["tasks"][0]["reason_codes"] == ["ATTENDANCE_GAP", "RENEWAL_DUE"]


def test_policy_change_changes_run_identity_and_result(sample_snapshot):
    default = build_run(sample_snapshot)
    relaxed = build_run(sample_snapshot, PilotPolicy(attendance_gap_days=20))

    assert default["run_id"] != relaxed["run_id"]
    assert default["policy_hash"] != relaxed["policy_hash"]
    assert relaxed["decisions"][0]["risk_score"] == 30


def test_persist_is_idempotent(sample_snapshot, tmp_path):
    result = build_run(sample_snapshot)

    first_path, first_status = persist_run(result, tmp_path)
    second_path, second_status = persist_run(result, tmp_path)

    assert first_path == second_path
    assert first_status == "created"
    assert second_status == "unchanged"
    assert first_path.read_bytes() == canonical_json_bytes(result)


def test_persist_fails_closed_on_same_run_id_with_different_content(sample_snapshot, tmp_path):
    result = build_run(sample_snapshot)
    persist_run(result, tmp_path)
    tampered = copy.deepcopy(result)
    tampered["counts"]["tasks"] = 999

    with pytest.raises(RuntimeError, match="idempotency violation"):
        persist_run(tampered, tmp_path)


def test_unknown_member_reference_fails_closed(sample_snapshot):
    sample_snapshot["attendance"][0]["member_external_id"] = "does-not-exist"

    with pytest.raises(SnapshotValidationError, match="unknown member"):
        build_run(sample_snapshot)


def test_duplicate_source_identifier_fails_closed(sample_snapshot):
    sample_snapshot["attendance"].append(copy.deepcopy(sample_snapshot["attendance"][0]))

    with pytest.raises(SnapshotValidationError, match="duplicate attendance.external_event_id"):
        build_run(sample_snapshot)


def test_future_attendance_fails_closed(sample_snapshot):
    sample_snapshot["attendance"][0]["checked_in_at"] = "2026-09-18T00:00:00Z"

    with pytest.raises(SnapshotValidationError, match="cannot be after snapshot.as_of"):
        build_run(sample_snapshot)


def test_naive_timestamp_fails_closed(sample_snapshot):
    sample_snapshot["as_of"] = "2026-09-17T00:00:00"

    with pytest.raises(SnapshotValidationError, match="must include a timezone"):
        build_run(sample_snapshot)
