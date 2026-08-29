"""Coordinates fetching, caching and archiving of TGJU price data."""

from __future__ import annotations

import threading
import time
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from typing import Sequence

from .jalali import JalaliDate
from .report import Series, build_series
from .storage import Archive, Settings, data_dir, read_json, write_json
from .symbols import CATALOG, Symbol, custom_symbol, resolve
from .tgju import PricePoint, TgjuError, fetch_history

__all__ = ["Crawler", "CrawlResult"]

CACHE_TTL_SECONDS = 15 * 60
MAX_PARALLEL_FETCHES = 4


@dataclass
class CrawlResult:
    """Outcome of one build request: what succeeded and what did not."""

    series: list[Series]
    errors: list[dict[str, str]]
    from_cache: list[str]

    def to_dict(self) -> dict:
        return {
            "series": [s.to_dict() for s in self.series],
            "errors": self.errors,
            "fromCache": self.from_cache,
        }


class Crawler:
    """Fetches price history, with a disk cache shared across app restarts."""

    def __init__(self, settings: Settings | None = None) -> None:
        self.settings = settings or Settings()
        self.archive = Archive()
        self.cache_dir = data_dir() / "cache"
        self._lock = threading.RLock()
        self._memory: dict[str, tuple[float, list[PricePoint]]] = {}

    # -- symbols ---------------------------------------------------------
    def known_symbols(self) -> list[Symbol]:
        """Built-in catalogue plus any custom symbols the user has added."""
        symbols = list(CATALOG.values())
        for entry in self.settings.get("custom_symbols", []) or []:
            try:
                key = str(entry.get("key", "")).strip()
                if key and key not in CATALOG:
                    symbols.append(
                        custom_symbol(key, entry.get("name"), entry.get("currency", "IRR"))
                    )
            except (AttributeError, ValueError):
                continue
        return symbols

    def resolve(self, keys: Sequence[str]) -> list[Symbol]:
        """Resolve keys, preferring the user's own definition of a custom symbol."""
        known = {symbol.key: symbol for symbol in self.known_symbols()}
        return [known.get(symbol.key, symbol) for symbol in resolve(keys)]

    # -- caching ---------------------------------------------------------
    def _cache_path(self, symbol: Symbol):
        safe = "".join(c if c.isalnum() or c in "-_" else "_" for c in symbol.key)
        return self.cache_dir / f"{safe}.json"

    def _read_cache(self, symbol: Symbol) -> tuple[float, list[PricePoint]] | None:
        with self._lock:
            hit = self._memory.get(symbol.key)
        if hit:
            return hit
        payload = read_json(self._cache_path(symbol))
        if not isinstance(payload, dict) or "points" not in payload:
            return None
        try:
            points = [
                PricePoint(
                    p["date"], p.get("gregorian", ""), p.get("open"),
                    p.get("low"), p.get("high"), p.get("close"),
                )
                for p in payload["points"]
            ]
        except (KeyError, TypeError):
            return None
        entry = (float(payload.get("fetched_at", 0)), points)
        with self._lock:
            self._memory[symbol.key] = entry
        return entry

    def _write_cache(self, symbol: Symbol, points: list[PricePoint]) -> None:
        now = time.time()
        with self._lock:
            self._memory[symbol.key] = (now, points)
        write_json(
            self._cache_path(symbol),
            {
                "symbol": symbol.key,
                "fetched_at": now,
                "points": [
                    {
                        "date": p.date, "gregorian": p.gregorian, "open": p.open,
                        "low": p.low, "high": p.high, "close": p.close,
                    }
                    for p in points
                ],
            },
        )

    def points_for(self, symbol: Symbol, *, force: bool = False) -> tuple[list[PricePoint], bool]:
        """Return ``(points, served_from_cache)`` for one symbol."""
        cached = self._read_cache(symbol)
        if cached and not force and (time.time() - cached[0]) < CACHE_TTL_SECONDS:
            return cached[1], True
        try:
            points = fetch_history(symbol)
        except TgjuError:
            if cached and cached[1]:
                return cached[1], True  # stale data beats no data
            raise
        self._write_cache(symbol, points)
        return points, False

    # -- high level ------------------------------------------------------
    def build(
        self,
        keys: Sequence[str],
        start: JalaliDate,
        end: JalaliDate,
        *,
        fill_gaps: bool = True,
        force: bool = False,
    ) -> CrawlResult:
        """Fetch every requested symbol in parallel and build its series."""
        symbols = self.resolve(keys)
        series: list[Series] = []
        errors: list[dict[str, str]] = []
        from_cache: list[str] = []

        def work(symbol: Symbol):
            return symbol, self.points_for(symbol, force=force)

        workers = min(MAX_PARALLEL_FETCHES, max(1, len(symbols)))
        with ThreadPoolExecutor(max_workers=workers) as pool:
            futures = {pool.submit(work, symbol): symbol for symbol in symbols}
            results: dict[str, tuple[Symbol, list[PricePoint], bool]] = {}
            for future in futures:
                symbol = futures[future]
                try:
                    symbol, (points, cached) = future.result()
                    results[symbol.key] = (symbol, points, cached)
                except TgjuError as exc:
                    errors.append({"symbol": symbol.key, "name": symbol.name, "message": str(exc)})
                except Exception as exc:  # pragma: no cover - defensive
                    errors.append(
                        {"symbol": symbol.key, "name": symbol.name,
                         "message": f"خطای پیش‌بینی‌نشده: {exc}"}
                    )

        for symbol in symbols:  # keep the user's ordering
            found = results.get(symbol.key)
            if not found:
                continue
            _, points, cached = found
            if cached:
                from_cache.append(symbol.key)
            series.append(build_series(symbol, points, start, end, fill_gaps=fill_gaps))
            self.archive.merge(symbol.key, [p.to_dict() for p in points[-400:]])

        return CrawlResult(series, errors, from_cache)

    def daily_crawl(self, keys: Sequence[str] | None = None) -> dict:
        """Refresh the archive for the watched symbols; used by ``--crawl``."""
        keys = list(keys or self.settings.get("symbols") or [])
        today = JalaliDate.today()
        added: dict[str, int] = {}
        errors: list[dict[str, str]] = []

        for symbol in self.resolve(keys):
            try:
                points, _ = self.points_for(symbol, force=True)
            except TgjuError as exc:
                errors.append({"symbol": symbol.key, "name": symbol.name, "message": str(exc)})
                continue
            added[symbol.key] = self.archive.merge(symbol.key, [p.to_dict() for p in points])

        if added:
            self.settings.update({"last_crawl": str(today)})
        return {
            "date": str(today),
            "added": added,
            "errors": errors,
            "archive": self.archive.summary(),
        }

    def maybe_daily_crawl(self) -> dict | None:
        """Run the daily crawl once per day when auto-crawl is enabled."""
        if not self.settings.get("auto_crawl", True):
            return None
        if self.settings.get("last_crawl") == str(JalaliDate.today()):
            return None
        return self.daily_crawl()
