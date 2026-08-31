"""Portable, side-by-side storage for settings, cache and the daily archive.

Everything lives in a ``PriceCeawler-Data`` folder next to the executable so
the app stays portable on a flash drive. When that location is read-only the
user profile directory is used instead.
"""

from __future__ import annotations

import json
import os
import secrets
import sys
import tempfile
import threading
from pathlib import Path
from typing import Any

from .symbols import DEFAULT_SELECTION

__all__ = ["data_dir", "Settings", "Archive", "read_json", "write_json", "get_or_create_secret"]

_DATA_DIR_NAME = "PriceCeawler-Data"
_lock = threading.RLock()
_cached_dir: Path | None = None


def _base_dir() -> Path:
    """Directory of the .exe when frozen, otherwise the project root."""
    if getattr(sys, "frozen", False):
        return Path(sys.executable).resolve().parent
    return Path(__file__).resolve().parent.parent


def _is_writable(path: Path) -> bool:
    try:
        path.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(dir=path, prefix=".w-", delete=True):
            return True
    except OSError:
        return False


def data_dir() -> Path:
    """Return (and create) the folder holding all persisted state."""
    global _cached_dir
    with _lock:
        if _cached_dir is not None:
            return _cached_dir
        override = os.environ.get("PRICECEAWLER_DATA_DIR")
        candidates = [Path(override)] if override else []
        candidates.append(_base_dir() / _DATA_DIR_NAME)
        candidates.append(Path.home() / _DATA_DIR_NAME)
        candidates.append(Path(tempfile.gettempdir()) / _DATA_DIR_NAME)
        for candidate in candidates:
            if _is_writable(candidate):
                _cached_dir = candidate
                break
        else:  # pragma: no cover - all candidates unwritable
            raise RuntimeError("هیچ مسیر قابل نوشتنی برای ذخیره داده‌ها پیدا نشد.")
        for sub in ("cache", "archive", "exports"):
            (_cached_dir / sub).mkdir(parents=True, exist_ok=True)
        return _cached_dir


def read_json(path: Path, default: Any = None) -> Any:
    try:
        with path.open("r", encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, json.JSONDecodeError):
        return default


def write_json(path: Path, payload: Any) -> None:
    """Write JSON atomically so a crash never leaves a truncated file."""
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
    os.replace(tmp, path)


def get_or_create_secret(name: str) -> str:
    """Return a token that stays the same across process restarts.

    The standalone desktop app mints a fresh random token per run on purpose
    (closing the window should invalidate any stale browser tab). A WSGI
    deployment behind a web server (e.g. Passenger) instead spawns several
    worker *processes* that must all agree on the same token, since a page
    served by one worker embeds a token that API calls may hit a different
    worker with. Persisting it to disk makes every worker converge on it.
    """
    path = data_dir() / f"{name}.key"
    with _lock:
        if path.is_file():
            existing = path.read_text(encoding="utf-8").strip()
            if existing:
                return existing
        candidate = secrets.token_urlsafe(24)
        try:
            # O_EXCL makes the first writer win; a loser just reads the winner's file.
            fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except FileExistsError:
            return path.read_text(encoding="utf-8").strip()
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(candidate)
        return candidate


DEFAULT_SETTINGS: dict[str, Any] = {
    "symbols": list(DEFAULT_SELECTION),
    "custom_symbols": [],        # [{"key": ..., "name": ..., "currency": ...}]
    "range_preset": "30",
    "start": "",
    "end": "",
    "fill_gaps": True,
    "auto_crawl": True,          # crawl once per day on startup
    "theme": "light",
    "last_crawl": "",            # Jalali date of the last successful crawl
}


class Settings:
    """User preferences persisted as ``settings.json``."""

    def __init__(self, path: Path | str | None = None) -> None:
        self.path = Path(path) if path else (data_dir() / "settings.json")
        stored = read_json(self.path, {}) or {}
        self._data = {**DEFAULT_SETTINGS, **{k: v for k, v in stored.items() if k in DEFAULT_SETTINGS}}

    def __getitem__(self, key: str) -> Any:
        return self._data[key]

    def get(self, key: str, default: Any = None) -> Any:
        return self._data.get(key, default)

    def as_dict(self) -> dict[str, Any]:
        return dict(self._data)

    def update(self, values: dict[str, Any]) -> dict[str, Any]:
        with _lock:
            for key, value in values.items():
                if key in DEFAULT_SETTINGS:
                    self._data[key] = value
            write_json(self.path, self._data)
        return self.as_dict()


class Archive:
    """Append-only daily snapshots, one JSON file per symbol."""

    def __init__(self, directory: Path | str | None = None) -> None:
        self.dir = Path(directory) if directory else (data_dir() / "archive")
        self.dir.mkdir(parents=True, exist_ok=True)

    def _path(self, symbol_key: str) -> Path:
        safe = "".join(c if c.isalnum() or c in "-_" else "_" for c in symbol_key)
        return self.dir / f"{safe}.json"

    def load(self, symbol_key: str) -> dict[str, dict]:
        data = read_json(self._path(symbol_key), {}) or {}
        return data if isinstance(data, dict) else {}

    def merge(self, symbol_key: str, points: list[dict]) -> int:
        """Merge price dicts into the archive; returns the number of new days."""
        with _lock:
            existing = self.load(symbol_key)
            added = 0
            for point in points:
                date = point.get("date")
                if not date:
                    continue
                if date not in existing:
                    added += 1
                existing[date] = point
            write_json(self._path(symbol_key), dict(sorted(existing.items())))
            return added

    def summary(self) -> list[dict]:
        rows: list[dict] = []
        for path in sorted(self.dir.glob("*.json")):
            data = read_json(path, {}) or {}
            if not isinstance(data, dict) or not data:
                continue
            dates = sorted(data)
            rows.append(
                {
                    "key": path.stem,
                    "days": len(dates),
                    "first": dates[0],
                    "last": dates[-1],
                    "latest": data[dates[-1]],
                }
            )
        return rows
