#!/usr/bin/env python3
"""Print the migration target (host / db / user) WITHOUT the password.

Run this before any migration so the operator can confirm they are pointed at
staging, not production.
"""

from __future__ import annotations

import sys

sys.path.insert(0, ".")

from app.config import get_settings  # noqa: E402


def main() -> int:
    settings = get_settings()
    summary = settings.safe_summary()
    print("=== XCAMP Fitness OS migration target (password hidden) ===")
    for key, value in summary.items():
        print(f"  {key:>9}: {value}")
    print("===========================================================")

    # Guard-rail: refuse to look like production.
    if settings.name == "xcamp_os" or settings.name.endswith("_prod"):
        print("REFUSING: target database name looks like PRODUCTION.", file=sys.stderr)
        return 2
    if "staging" not in settings.name and settings.name != "xcamp_os_test":
        print(
            "WARNING: database name does not contain 'staging'. "
            "Confirm this is an approved non-production target.",
            file=sys.stderr,
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
