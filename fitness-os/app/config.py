"""Runtime configuration for the Fitness OS schema tooling.

The database URL is assembled from environment variables so that no credentials
are ever committed to git. Staging defaults are intentionally local-only.

Environment variables (all optional; sensible local-staging defaults applied):
    FITNESS_DB_HOST      default 127.0.0.1
    FITNESS_DB_PORT      default 5432
    FITNESS_DB_NAME      default xcamp_os_staging
    FITNESS_DB_USER      default xcamp_app
    FITNESS_DB_PASSWORD  default "" (empty)
    FITNESS_DB_SSLMODE   optional (e.g. "require" for Cloud SQL)
    FITNESS_DATABASE_URL if set, used verbatim and overrides the parts above
"""

from __future__ import annotations

import os
from dataclasses import dataclass

try:  # optional convenience; never required
    from dotenv import load_dotenv

    load_dotenv()
except Exception:  # pragma: no cover - dotenv is optional
    pass


@dataclass(frozen=True)
class DatabaseSettings:
    host: str
    port: int
    name: str
    user: str
    password: str
    sslmode: str | None

    @property
    def url(self) -> str:
        """SQLAlchemy URL using the psycopg (v3) driver."""
        override = os.getenv("FITNESS_DATABASE_URL")
        if override:
            return override
        auth = self.user
        if self.password:
            auth = f"{self.user}:{self.password}"
        url = f"postgresql+psycopg://{auth}@{self.host}:{self.port}/{self.name}"
        if self.sslmode:
            url = f"{url}?sslmode={self.sslmode}"
        return url

    def safe_summary(self) -> dict[str, str]:
        """Connection details WITHOUT the password, for pre-flight printing."""
        return {
            "host": self.host,
            "port": str(self.port),
            "database": self.name,
            "user": self.user,
            "sslmode": self.sslmode or "(default)",
        }


def get_settings() -> DatabaseSettings:
    return DatabaseSettings(
        host=os.getenv("FITNESS_DB_HOST", "127.0.0.1"),
        port=int(os.getenv("FITNESS_DB_PORT", "5432")),
        name=os.getenv("FITNESS_DB_NAME", "xcamp_os_staging"),
        user=os.getenv("FITNESS_DB_USER", "xcamp_app"),
        password=os.getenv("FITNESS_DB_PASSWORD", ""),
        sslmode=os.getenv("FITNESS_DB_SSLMODE") or None,
    )


def get_database_url() -> str:
    return get_settings().url
