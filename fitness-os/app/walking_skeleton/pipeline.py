"""Walking Skeleton v0.1: snapshot -> normalize -> evaluate -> task -> audit.

This module deliberately accepts a supplied snapshot and never connects to MOS.
It is a deterministic calibration harness, not a production retention service.
The same snapshot and policy always produce the same canonical bytes and run id.
"""

from __future__ import annotations

import hashlib
import json
import os
import tempfile
import uuid
from dataclasses import asdict, dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Mapping
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

SCHEMA_VERSION = "xcamp.walking-skeleton.v2"
ENGINE_VERSION = "calibration.2"
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
        if type(self.attendance_gap_days) is not int or self.attendance_gap_days < 1:
            raise ValueError("attendance_gap_days must be positive")
        if type(self.renewal_window_days) is not int or self.renewal_window_days < 1:
            raise ValueError("renewal_window_days must be positive")
        if not isinstance(self.owner_role, str) or not self.owner_role.strip():
            raise ValueError("owner_role must not be empty")
        if not isinstance(self.version, str) or not self.version.strip():
            raise ValueError("version must not be empty")


def canonical_json_bytes(value: Any) -> bytes:
    """Return stable UTF-8 JSON suitable for hashing and byte comparison."""

    return (
        json.dumps(value, ensure_ascii=False, sort_keys=True, allow_nan=False, separators=(",", ":")) + "\n"
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
    if not isinstance(snapshot, Mapping):
        raise SnapshotValidationError("snapshot must be an object")
    snapshot_id = _require_text(snapshot, "snapshot_id", "snapshot")
    observed_at = _parse_datetime(snapshot.get("observed_at"), "snapshot.observed_at")
    as_of = _parse_datetime(snapshot.get("as_of"), "snapshot.as_of")
    if observed_at > as_of:
        raise SnapshotValidationError("snapshot.observed_at cannot be after snapshot.as_of")
    timezone_name = snapshot.get("timezone", "UTC")
    try:
        ZoneInfo(timezone_name)
    except (ZoneInfoNotFoundError, TypeError, ValueError) as exc:
        raise SnapshotValidationError("snapshot.timezone must be an IANA timezone") from exc
    coverage = snapshot.get("attendance_coverage")
    if coverage is not None:
        if not isinstance(coverage, Mapping) or type(coverage.get("complete")) is not bool:
            raise SnapshotValidationError("attendance_coverage requires boolean complete")
        start = _parse_datetime(coverage.get("from"), "attendance_coverage.from")
        through = _parse_datetime(coverage.get("through"), "attendance_coverage.through")
        if not start <= through <= observed_at:
            raise SnapshotValidationError("attendance coverage must end by observed_at")
        coverage = {"from": start.isoformat(), "through": through.isoformat(),
                    "complete": coverage["complete"]}

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
                "status": _require_text(row, "status", "member").lower(),
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
                "status": _require_text(row, "status", "membership").lower(),
            }
        )

    for row in normalized_members:
        if row["status"] not in {"active", "inactive"}:
            raise SnapshotValidationError("unsupported member status")
    for row in normalized_memberships:
        if row["status"] not in {"active", "expired", "cancelled", "transfered",
                                 "freezed", "postponed", "suspended"}:
            raise SnapshotValidationError("unsupported membership status")
        if row["start_date"] > row["expiry_date"]:
            raise SnapshotValidationError("membership starts after expiry")

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
        if checked_in_at > observed_at:
            raise SnapshotValidationError("attendance event cannot be after observed_at")
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
        "timezone": timezone_name,
        "attendance_coverage": coverage,
        "members": sorted(normalized_members, key=lambda row: row["external_id"]),
        "memberships": sorted(
            normalized_memberships, key=lambda row: row["external_membership_id"]
        ),
        "attendance": sorted(normalized_attendance, key=lambda row: row["external_event_id"]),
    }


def _evaluate(normalized: Mapping[str, Any], policy: PilotPolicy) -> list[dict[str, Any]]:
    as_of = _parse_datetime(normalized["as_of"], "normalized.as_of")
    as_of_date = as_of.astimezone(ZoneInfo(normalized["timezone"])).date()
    coverage = normalized["attendance_coverage"]
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
            and row["start_date"] <= as_of_date.isoformat() <= row["expiry_date"]
        ]
        if not active_memberships:
            continue

        reasons: list[dict[str, Any]] = []
        latest_expiry = max(_parse_date(row["expiry_date"], "expiry_date") for row in active_memberships)
        days_to_expiry = (latest_expiry - as_of_date).days
        if 0 <= days_to_expiry <= policy.renewal_window_days:
            reasons.append({"code": "RENEWAL_DUE", "days_to_expiry": days_to_expiry})

        visits = attendance_by_member.get(external_id, [])
        coverage_ok = bool(coverage and coverage["complete"] and
            _parse_datetime(coverage["through"], "through") >= as_of and
            _parse_datetime(coverage["from"], "from") <= as_of - timedelta(days=policy.attendance_gap_days))
        current_start = min(_parse_date(row["start_date"], "start") for row in active_memberships)
        current_start_at = datetime.combine(current_start, datetime.min.time(),
                                            ZoneInfo(normalized["timezone"]))
        visits = [visit for visit in visits if visit >= current_start_at and (
            not coverage or visit >= _parse_datetime(coverage["from"], "from"))]
        attendance_state = "unavailable_missing_or_incomplete_coverage"
        if coverage_ok and visits:
            last_visit = max(visits)
            gap_days = (as_of - last_visit).days
            if gap_days >= policy.attendance_gap_days:
                reasons.append({"code": "ATTENDANCE_GAP", "days_since_visit": gap_days})
            attendance_state = "evaluated"
        elif coverage_ok:
            # No observed visits does NOT prove never attended. Report only the
            # lower bound on a fully covered period within the current episode.
            since = max(current_start_at, _parse_datetime(coverage["from"], "from"))
            gap_days = (as_of - since).days
            if gap_days >= policy.attendance_gap_days:
                reasons.append({"code": "ATTENDANCE_GAP", "no_visits_in_covered_days": gap_days})
            attendance_state = "evaluated"

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
                "attendance_evaluation": attendance_state,
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
    run_id = str(uuid.uuid5(RUN_NAMESPACE, f"{ENGINE_VERSION}:{SCHEMA_VERSION}:{input_hash}:{policy_hash}"))
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
        "engine_version": ENGINE_VERSION,
        "limitations": [
            "Task IDs are run-scoped drafts, not cross-snapshot operational deduplication.",
            "No outcome or incremental revenue attribution is implemented.",
        ],
        "attendance_evaluation": "available" if (
            normalized["attendance_coverage"] and normalized["attendance_coverage"]["complete"]
            and _parse_datetime(normalized["attendance_coverage"]["through"], "through") >= as_of
            and _parse_datetime(normalized["attendance_coverage"]["from"], "from") <=
                as_of - timedelta(days=selected_policy.attendance_gap_days)
        ) else "unavailable_missing_or_incomplete_coverage",
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
    if str(uuid.UUID(run_id)) != run_id:
        raise ValueError("run_id must be a canonical UUID")
    destination_dir = Path(output_dir)
    destination_dir.mkdir(parents=True, exist_ok=True)
    destination = destination_dir / f"{run_id}.json"
    content = canonical_json_bytes(run)

    if destination.exists():
        if destination.read_bytes() != content:
            raise RuntimeError(f"idempotency violation for existing run {run_id}")
        return destination, "unchanged"

    # Publish using a no-clobber hard link, so concurrent writers cannot replace
    # a different artifact sharing this identity. Never overwrite existing data.
    with tempfile.NamedTemporaryFile(dir=destination_dir, delete=False) as handle:
        temporary = Path(handle.name)
        handle.write(content)
        handle.flush()
        os.fsync(handle.fileno())
    try:
        try:
            os.link(temporary, destination)
            return destination, "created"
        except FileExistsError:
            if destination.read_bytes() != content:
                raise RuntimeError(f"idempotency violation for existing run {run_id}")
            return destination, "unchanged"
    finally:
        temporary.unlink()
