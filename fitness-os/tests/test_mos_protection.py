"""MOS isolation: the Fitness OS code must contain NO write path to MOS and no
outbound HTTP write integration at all. This is a static guard over the package
source (excluding this test file, which necessarily names the patterns)."""

from __future__ import annotations

import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(HERE)
APP_DIR = os.path.join(PROJECT_ROOT, "app")
MIGRATIONS_DIR = os.path.join(PROJECT_ROOT, "migrations")

# Outbound-write / MOS-client indicators that must never appear in Fitness OS code.
FORBIDDEN_PATTERNS = [
    r"requests\.post",
    r"requests\.put",
    r"requests\.patch",
    r"requests\.delete",
    r"httpx\.(post|put|patch|delete)",
    r"urllib\.request\.urlopen",
    r"\bPOST\s+MOS\b",
    r"\bPUT\s+MOS\b",
    r"\bPATCH\s+MOS\b",
    r"\bDELETE\s+MOS\b",
]


def _iter_python_files():
    for base in (APP_DIR, MIGRATIONS_DIR):
        for root, _dirs, files in os.walk(base):
            for f in files:
                if f.endswith(".py"):
                    yield os.path.join(root, f)


def test_no_outbound_write_or_mos_client_in_source():
    violations = []
    for path in _iter_python_files():
        with open(path, "r", encoding="utf-8") as fh:
            for lineno, line in enumerate(fh, start=1):
                for pat in FORBIDDEN_PATTERNS:
                    if re.search(pat, line):
                        violations.append(f"{path}:{lineno}: {line.strip()}")
    assert not violations, "Forbidden MOS/outbound-write patterns found:\n" + "\n".join(violations)


def test_no_http_client_libraries_imported():
    """Fitness OS is a schema layer - it must not import an HTTP client."""
    banned_imports = re.compile(r"^\s*(import|from)\s+(requests|httpx|aiohttp)\b", re.M)
    offenders = []
    for path in _iter_python_files():
        with open(path, "r", encoding="utf-8") as fh:
            if banned_imports.search(fh.read()):
                offenders.append(path)
    assert not offenders, f"HTTP client imported in schema layer: {offenders}"
