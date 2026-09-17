"""Read-only, retrospective calibration of an existing integrated workbook.

Run with a Python environment containing openpyxl (analysis-only dependency).
Never commit the input, output, member identifiers, or derived task drafts.
No MOS access, database writes, or employee contact occurs.
"""
from __future__ import annotations

import argparse
from collections import Counter
from datetime import date, datetime, time
import hashlib
import json
from pathlib import Path
import sys
from zoneinfo import ZoneInfo

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from app.walking_skeleton.pipeline import build_run, PilotPolicy, canonical_json_bytes


def snapshot_from_rows(rows, source_hash, as_of_date):
    """Preserve the prior workbook's conservative core classification, explicitly.

    Member status is cohort eligibility, not an inferred MOS master status.
    Source lacks subscription IDs; row IDs are export-local, never MOS IDs.
    """
    memberships = []
    member_ids = set()
    quarantined = []
    blocked_codes = set()
    core_rows = 0
    required = {"كود العضوية", "تاريخ البدء", "تاريخ الانتهاء", "الحالة", "Core Membership?"}
    for number, row in rows:
        if not required.issubset(row):
            raise ValueError("Missing required workbook headers")
        if row["Core Membership?"] not in {"Yes", "No"}:
            raise ValueError(f"Unclassified source row {number}")
        if row["Core Membership?"] != "Yes":
            continue
        core_rows += 1
        member_code = row["كود العضوية"]
        if isinstance(member_code, bool) or member_code is None:
            raise ValueError(f"Invalid membership code at row {number}")
        if isinstance(member_code, (float, int)):
            if int(member_code) != member_code:
                raise ValueError("Non-integer member code")
            member_code = str(int(member_code))
        if not isinstance(member_code, str) or not member_code.strip():
            raise ValueError("Invalid member code")
        member_code = member_code.strip()
        start, end = row["تاريخ البدء"], row["تاريخ الانتهاء"]
        if not isinstance(start, (datetime, date)) or not isinstance(end, (datetime, date)):
            quarantined.append({"row": number, "reason": "missing_or_untyped_dates"})
            blocked_codes.add(member_code)
            continue
        status = row["الحالة"]
        if not isinstance(status, str):
            raise ValueError(f"Missing status at row {number}")
        memberships.append({
            "external_membership_id": f"export-row:{source_hash}:{number}",
            "member_external_id": member_code,
            "start_date": start.strftime("%Y-%m-%d"),
            "expiry_date": end.strftime("%Y-%m-%d"),
            "status": status.lower(),
        })
        member_ids.add(member_code)
    anchor = datetime.combine(as_of_date, time.min, ZoneInfo("Africa/Cairo")).isoformat()
    memberships = [r for r in memberships if r["member_external_id"] not in blocked_codes]
    member_ids -= blocked_codes
    return {
        "snapshot_id": f"derived-workbook:{source_hash}",
        "as_of": anchor, "observed_at": anchor, "timezone": "Africa/Cairo",
        "members": [{"external_id": code, "status": "active"} for code in sorted(member_ids)],
        "memberships": memberships, "attendance": [],
    }, {"core_rows_before_quarantine": core_rows, "invalid_rows": quarantined,
        "excluded_member_codes": len(blocked_codes)}


def main():
    import openpyxl
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("workbook", type=Path)
    parser.add_argument("--as-of", type=date.fromisoformat, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    digest = hashlib.sha256(args.workbook.read_bytes()).hexdigest()
    workbook = openpyxl.load_workbook(args.workbook, read_only=True, data_only=True)
    try:
        iterator = workbook["Raw_Subscriptions"].iter_rows(values_only=True)
        headers = next(iterator)
        rows = [(i, dict(zip(headers, row))) for i, row in enumerate(iterator, 2)
                if row and row[0] is not None]
        snapshot, quarantine = snapshot_from_rows(rows, digest, args.as_of)
    finally:
        workbook.close()
    runs = {str(window): build_run(snapshot, PilotPolicy(renewal_window_days=window))
            for window in (7, 14, 30)}
    run = runs["30"]
    day = args.as_of.isoformat()
    active = {r["member_external_id"] for r in snapshot["memberships"]
              if r["status"] == "active" and r["start_date"] <= day <= r["expiry_date"]}
    frozen = {r["member_external_id"] for r in snapshot["memberships"]
              if r["status"] == "freezed" and r["start_date"] <= day <= r["expiry_date"]}
    report = {
        "mode": "retrospective_renewal_calibration_only",
        "as_of_date": day, "timezone": "Africa/Cairo", "source_sha256": digest,
        "engine_version": run["engine_version"],
        "source_rows": len(rows), "core_rows": len(snapshot["memberships"]),
        "quarantine": quarantine,
        "core_member_codes": len(snapshot["members"]),
        "eligible_active_members": len(active), "frozen_members_excluded": len(frozen - active),
        "membership_status_counts": dict(Counter(r["status"] for r in snapshot["memberships"])),
        "renewal_candidates": {window: len(r["decisions"]) for window, r in runs.items()},
        "run_ids": {window: r["run_id"] for window, r in runs.items()},
        "attendance_evaluation": run["attendance_evaluation"],
        "repeat_byte_equal": canonical_json_bytes(run) == canonical_json_bytes(build_run(snapshot)),
        "limitations": [
            "No timestamped member check-ins. Absence calibration is blocked, not zero.",
            "2026 export only; cohort is not the total active gym population.",
            "Members with missing episode dates are quarantined entirely, not treated as non-risk.",
            "Core-product classification is inherited from the prior analysis, not independently approved.",
            "Statuses and date ranges are used as reported; cancelled, frozen, postponed, suspended rows excluded.",
            "Future contracts require manual review before operational contact; no confirmed renewal inference.",
            "No MOS subscription IDs: export-local row identities are not safe for cross-export deduplication.",
            "Date-only source: midnight Cairo is an analysis anchor, not a claimed extraction timestamp.",
            "No tasks assigned, contacts made, outcomes measured, or revenue recovery claimed.",
        ],
    }
    args.report.parent.mkdir(parents=True, exist_ok=True)
    with args.report.open("xb") as handle:
        handle.write(canonical_json_bytes(report))
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
