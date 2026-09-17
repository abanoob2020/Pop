"""Command-line entry point for the Walking Skeleton calibration harness."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from app.walking_skeleton.pipeline import PilotPolicy, build_run, persist_run


def main() -> int:
    parser = argparse.ArgumentParser(description="Run XCAMP Walking Skeleton v0.1")
    parser.add_argument("snapshot", type=Path, help="MOS snapshot JSON file")
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--attendance-gap-days", type=int, default=10)
    parser.add_argument("--renewal-window-days", type=int, default=30)
    args = parser.parse_args()

    with args.snapshot.open(encoding="utf-8") as handle:
        snapshot = json.load(handle)
    policy = PilotPolicy(
        attendance_gap_days=args.attendance_gap_days,
        renewal_window_days=args.renewal_window_days,
    )
    run = build_run(snapshot, policy)
    path, status = persist_run(run, args.output_dir)
    print(json.dumps({"run_id": run["run_id"], "path": str(path), "status": status}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
