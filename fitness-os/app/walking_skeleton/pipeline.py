"""Walking Skeleton v0.1: snapshot -> normalize -> evaluate -> task -> audit.

This module deliberately accepts a supplied snapshot and never connects to MOS.
It is a deterministic calibration harness, not a production retention service.
The same snapshot and policy always produce the same canonical bytes and run id.
"""

from __future__ import annotations

import hashlib
import json
import os
import uuid
from dataclasses import asdict, dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Mapping

SCHEMA_VERSION = "xcamp.walking-skeleton.v1"
POLICY_VERSION = "retention-pilot.v0.1"
RUN_NAMESPACE = uuid.UUID("e39d3845-1c83-5108-bf1d-7a67431dfed1")
MEMBER_NAMESPACE = uuid.UUID("491b23f6-7182-5bfe-91f2-4c897cae66d3")
TASK_NAMESPACE = uuid.UUID("8f82977d-84ab-5fda-bcaf-45f96a4fb698")


class SnapshotValidationError(ValueError):
    """Raised when a source snapshot cannot be processed safely."""


@dataclass(frozen=True)
class PilotPolicy:
    """Explicit, versioned pilot thresholds; not approved for production."""

    version: str = POLICY_VERSION
    attendance_gap_days: int = 10
    renewal_window_days: int = 30
    owner_role: str = "retention_desk"

    def __post_init__(self) -> None:
        if self.attendance_gap_days < 1:
            raise ValueError("attendance_gap_days must be positive")
        if self.renewal_window_days < 1:
            raise ValueError("renewal_window_days must be positive")
        if not self.owner_role.strip():
            raise ValueError("owner_role must not be empty")


def canonical_json_bytes(value: Any) -> bytes:
    """Return stable UTF-8 JSON suitable for hashing and byte comparison."""

    return (
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n"
    ).encode("utf-8")


def _hash(value: Any) -> str:
    return hashlib.sha256(canonical_json_bytes(value)).hexdigest()


def _require_text(row: Mapping[str, Any], key: str, context: str) -> str:
    value = row.get(key)
    if not isinstance(value, str) or not value.strip():
        raise SnapshotValidationError(f"{context}.{key} must be a non-empty string")
    return value.strip()


def _parse_datetime(value: Any, context: str) -> datetime:
    if not isinstance(value, str):
        raise SnapshotValidationError(f"{context} must be an ISO-8601 string")
    candidate = value[:-1] + "+00:00" if value.endswith("Z") else value
    try:
        parsed = datetime.fromisoformat(candidate)
    except ValueError as exc:
        raise SnapshotValidationError(f"{context} is not valid ISO-8601") from exc
    if parsed.tzinfo is None:
        raise SnapshotValidationError(f"{context} must include a timezone")
    return parsed.astimezone(timezone.utc)


def _parse_date(value: Any, context: str) -> date:
    if not isinstance(value, str):
        raise SnapshotValidationError(f"{context} must be an ISO date")
    try:
        return date.fromisoformat(value)
    except ValueError as exc:
        raise SnapshotValidationError(f"{context} is not a valid ISO date") from exc


def _require_list(snapshot: Mapping[str, Any], key: str) -> list[Mapping[str, Any]]:
    value = snapshot.get(key)
    if not isinstance(value, list) or any(not isinstance(row, Mapping) for row in value):
        raise SnapshotValidationError(f"snapshot.{key} must be a list of objects")
    return value


def _unique_by(rows: list[Mapping[str, Any]], key: str, context: str) -> None:
    seen: set[str] = set()
    for index, row in enumerate(rows):
        value = _require_text(row, key, f"{context}[{index}]")
        if value in seen:
            raise SnapshotValidationError(f"duplicate {context}.{key}: {value}")
        seen.add(value)


def _normalize(snapshot: Mapping[str, Any]) -> dict[str, Any]:
    snapshot_id = _require_text(snapshot, "snapshot_id", "snapshot")
    observed_at = _parse_datetime(snapshot.get("observed_at"), "snapshot.observed_at")
    as_of = _parse_datetime(snapshot.get("as_of"), "snapshot.as_of")
    if observed_at > as_of:
        raise SnapshotValidationError("snapshot.observed_at cannot be after snapshot.as_of")

    members = _require_list(snapshot, "members")
    memberships = _require_list(snapshot, "memberships")
    attendance = _require_list(snapshot, "attendance")
    _unique_by(members, "external_id", "members")
    _unique_by(memberships, "external_membership_id", "memberships")
    _unique_by(attendance, "external_event_id", "attendance")

    member_ids = {_require_text(row, "external_id", "member") for row in members}
    normalized_members = []
    for row in members:
        external_id = _require_text(row, "external_id", "member")
        normalized_members.append(
            {
                "external_id": external_id,
                "member_id": str(uuid.uuid5(MEMBER_NAMESPACE, f"MOS/member/{external_id}")),
                "status": str(row.get("status", "active")).strip().lower(),
            }
        )

    normalized_memberships = []
    for index, row in enumerate(memberships):
        member_external_id = _require_text(row, "member_external_id", f"memberships[{index}]")
        if member_external_id not in member_ids:
            raise SnapshotValidationError(
                f"memberships[{index}] references unknown member {member_external_id}"
            )
        normalized_memberships.append(
            {
                "external_membership_id": _require_text(
                    row, "external_membership_id", f"memberships[{index}]"
                ),
                "member_external_id": member_external_id,
                "start_date": _parse_date(
                    row.get("start_date"), f"memberships[{index}].start_date"
                ).isoformat(),
                "expiry_date": _parse_date(
                    row.get("expiry_date"), f"memberships[{index}].expiry_date"
                ).isoformat(),
                "status": str(row.get("status", "active")).strip().lower(),
            }
        )

    normalized_attendance = []
    for index, row in enumerate(attendance):
        member_external_id = _require_text(row, "member_external_id", f"attendance[{index}]")
        if member_external_id not in member_ids:
            raise SnapshotValidationError(
                f"attendance[{index}] references unknown member {member_external_id}"
            )
        checked_in_at = _parse_datetime(
            row.get("checked_in_at"), f"attendance[{index}].checked_in_at"
        )
        if checked_in_at > as_of:
            raise SnapshotValidationError(
                f"attendance[{index}].checked_in_at cannot be after snapshot.as_of"
            )
        normalized_attendance.append(
            {
                "external_event_id": _require_text(
                    row, "external_event_id", f"attendance[{index}]"
                ),
                "member_external_id": member_external_id,
                "checked_in_at": checked_in_at.isoformat().replace("+00:00", "Z"),
            }
        )

    return {
        "snapshot_id": snapshot_id,
        "observed_at": observed_at.isoformat().replace("+00:00", "Z"),
        "as_of": as_of.isoformat().replace("+00:00", "Z"),
        "members": sorted(normalized_members, key=lambda row: row["external_id"]),
        "memberships": sorted(
            normalized_memberships, key=lambda row: row["external_membership_id"]
        ),
        "attendance": sorted(normalized_attendance, key=lambda row: row["external_event_id"]),
    }


def _evaluate(normalized: Mapping[str, Any], policy: PilotPolicy) -> list[dict[str, Any]]:
    as_of = _parse_datetime(normalized["as_of"], "normalized.as_of")
    as_of_date = as_of.date()
    memberships_by_member: dict[str, list[Mapping[str, Any]]] = {}
    attendance_by_member: dict[str, list[datetime]] = {}

    for row in normalized["memberships"]:
        memberships_by_member.setdefault(row["member_external_id"], []).append(row)
    for row in normalized["attendance"]:
        attendance_by_member.setdefault(row["member_external_id"], []).append(
            _parse_datetime(row["checked_in_at"], "attendance.checked_in_at")
        )

    decisions = []
    for member in normalized["members"]:
        if member["status"] != "active":
            continue
        external_id = member["external_id"]
        active_memberships = [
            row
            for row in memberships_by_member.get(external_id, [])
            if row["status"] == "active"
        ]
        if not active_memberships:
            continue

        reasons: list[dict[str, Any]] = []
        latest_expiry = max(_parse_date(row["expiry_date"], "expiry_date") for row in active_memberships)
        days_to_expiry = (latest_expiry - as_of_date).days
        if 0 <= days_to_expiry <= policy.renewal_window_days:
            reasons.append({"code": "RENEWAL_DUE", "days_to_expiry": days_to_expiry})

        visits = attendance_by_member.get(external_id, [])
        if visits:
            last_visit = max(visits)
            gap_days = (as_of - last_visit).days
            if gap_days >= policy.attendance_gap_days:
                reasons.append({"code": "ATTENDANCE_GAP", "days_since_visit": gap_days})

        if not reasons:
            continue

        reason_codes = [reason["code"] for reason in reasons]
        score = (50 if "ATTENDANCE_GAP" in reason_codes else 0) + (
            30 if "RENEWAL_DUE" in reason_codes else 0
        )
        decisions.append(
            {
                "member_id": member["member_id"],
                "member_external_id": external_id,
                "reasons": sorted(reasons, key=lambda row: row["code"]),
                "risk_score": score,
                "severity": "high" if score >= 80 else "medium",
            }
        )
    return sorted(decisions, key=lambda row: row["member_external_id"])


def build_run(snapshot: Mapping[str, Any], policy: PilotPolicy | None = None) -> dict[str, Any]:
    """Build a deterministic walking-skeleton output without side effects."""

    selected_policy = policy or PilotPolicy()
    normalized = _normalize(snapshot)
    input_hash = _hash(normalized)
    policy_data = asdict(selected_policy)
    policy_hash = _hash(policy_data)
    run_id = str(uuid.uuid5(RUN_NAMESPACE, f"{input_hash}:{policy_hash}"))
    decisions = _evaluate(normalized, selected_policy)
    as_of = _parse_datetime(normalized["as_of"], "normalized.as_of")

    tasks = []
    for decision in decisions:
        reason_codes = [row["code"] for row in decision["reasons"]]
        task_id = str(
            uuid.uuid5(
                TASK_NAMESPACE,
                f"{run_id}:{decision['member_id']}:{','.join(reason_codes)}",
            )
        )
        tasks.append(
            {
                "task_id": task_id,
                "member_id": decision["member_id"],
                "member_external_id": decision["member_external_id"],
                "owner_role": selected_policy.owner_role,
                "deadline": (as_of + timedelta(days=1)).isoformat().replace("+00:00", "Z"),
                "status": "pending",
                "action": "contact_member",
                "reason_codes": reason_codes,
            }
        )

    audit = [
        {
            "sequence": 1,
            "stage": "snapshot_received",
            "input_hash": input_hash,
            "snapshot_id": normalized["snapshot_id"],
        },
        {
            "sequence": 2,
            "stage": "normalized",
            "member_count": len(normalized["members"]),
            "membership_count": len(normalized["memberships"]),
            "attendance_count": len(normalized["attendance"]),
        },
        {
            "sequence": 3,
            "stage": "evaluated",
            "policy_hash": policy_hash,
            "decision_count": len(decisions),
        },
        {"sequence": 4, "stage": "tasks_created", "task_count": len(tasks)},
    ]

    return {
        "schema_version": SCHEMA_VERSION,
        "run_id": run_id,
        "mode": "calibration_only",
        "source": {"system": "MOS", "access": "supplied_snapshot_read_only"},
        "input_hash": input_hash,
        "policy": policy_data,
        "policy_hash": policy_hash,
        "normalized": normalized,
        "decisions": decisions,
        "tasks": tasks,
        "audit": audit,
        "counts": {
            "members": len(normalized["members"]),
            "decisions": len(decisions),
            "tasks": len(tasks),
        },
    }


def persist_run(run: Mapping[str, Any], output_dir: str | os.PathLike[str]) -> tuple[Path, str]:
    """Atomically persist canonical output; identical retries are no-ops."""

    run_id = _require_text(run, "run_id", "run")
    destination_dir = Path(output_dir)
    destination_dir.mkdir(parents=True, exist_ok=True)
    destination = destination_dir / f"{run_id}.json"
    content = canonical_json_bytes(run)

    if destination.exists():
        if destination.read_bytes() != content:
            raise RuntimeError(f"idempotency violation for existing run {run_id}")
        return destination, "unchanged"

    temporary = destination.with_suffix(".tmp")
    temporary.write_bytes(content)
    os.replace(temporary, destination)
    return destination, "created"
