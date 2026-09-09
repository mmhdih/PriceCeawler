"""Coordinates fetching, caching and archiving of TGJU price data."""

from __future__ import annotations

import threading
import time
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from typing import Sequence

from . import intraday, tgju_intraday
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
    # Echoed back so a client knows which resolution the rows actually are -
    # an unrecognised request silently falls back to daily.
    resolution: str = "daily"

    def to_dict(self) -> dict:
        return {
            "series": [s.to_dict() for s in self.series],
            "errors": self.errors,
            "fromCache": self.from_cache,
            "resolution": self.resolution,
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
        """Built-in catalogue (minus anything disabled from Settings) plus any
        custom symbols the user has added there."""
        disabled = set(self.settings.get("disabled_symbols", []) or [])
        symbols = [s for s in CATALOG.values() if s.key not in disabled]
        known_keys = {s.key for s in symbols}
        for entry in self.settings.get("custom_symbols", []) or []:
            try:
                key = str(entry.get("key", "")).strip()
                if key and key not in known_keys:
                    symbols.append(
                        custom_symbol(
                            key, entry.get("name"), entry.get("currency", "IRR"),
                            entry.get("group"), entry.get("decimals"),
                        )
                    )
                    known_keys.add(key)
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

    # -- intraday --------------------------------------------------------
    def build_at(
        self,
        keys: Sequence[str],
        start: JalaliDate,
        end: JalaliDate,
        *,
        fill_gaps: bool = True,
        force: bool = False,
        resolution: str = "daily",
    ) -> CrawlResult:
        """Build series at the requested resolution.

        Daily goes to TGJU (which only serves daily rows); the intraday
        resolutions are served from our own recorded samples, since no amount
        of asking the daily endpoint can recover the last ten minutes.
        """
        resolution = intraday.normalise_resolution(resolution)
        if not intraday.is_intraday(resolution):
            daily = self.build(keys, start, end, fill_gaps=fill_gaps, force=force)
            daily.resolution = resolution
            return daily

        source = str(self.settings.get("intraday_source") or "auto")
        endpoints = tgju_intraday.endpoints_from_setting(
            str(self.settings.get("intraday_endpoint") or "")
        )
        from_ts, to_ts = intraday.range_bounds(start, end)

        series: list[Series] = []
        errors: list[dict[str, str]] = []
        recording = bool(self.settings.get("intraday_recording"))

        for symbol in self.resolve(keys):
            rows: list[dict] = []
            fetch_error: str | None = None

            # Extraction first: it can answer for any past range, whereas
            # recorded samples only cover time the sampler was running for.
            if source in ("auto", "tgju"):
                try:
                    candles, endpoint, native = tgju_intraday.fetch_candles(
                        symbol, from_ts, to_ts, endpoints=endpoints
                    )
                    rows = intraday.aggregate_candles(candles, resolution, symbol.decimals)
                    if rows:
                        # Remember what worked so later requests skip probing.
                        self._pin_endpoint(endpoint.name)
                except TgjuError as exc:
                    fetch_error = str(exc)

            if not rows and source in ("auto", "recorded"):
                rows = intraday.build_rows(symbol, start, end, resolution)

            if not rows:
                # Say why there is nothing and what to do about it - "no data"
                # alone leaves the user with no next step.
                if source == "recorded":
                    message = intraday.explain_empty(symbol, recording)
                elif fetch_error:
                    message = f"«{symbol.name}»: {fetch_error}"
                else:
                    message = (
                        f"برای «{symbol.name}» در این بازه داده درون‌روزی از TGJU دریافت نشد."
                    )
                errors.append({"symbol": symbol.key, "name": symbol.name, "message": message})
                continue

            series.append(Series(symbol, rows, intraday.stats(rows, symbol)))
        return CrawlResult(series, errors, [], resolution)

    def _pin_endpoint(self, name: str) -> None:
        """Persist the chart endpoint that answered, so we stop probing."""
        if name == "custom" or self.settings.get("intraday_endpoint") == name:
            return  # a user-supplied URL is theirs to keep; no churn either
        try:
            self.settings.update({"intraday_endpoint": name})
        except OSError:  # pragma: no cover - a read-only data dir must not fail a report
            pass

    def probe_intraday(self, keys: Sequence[str] | None = None) -> dict:
        """Report which TGJU chart endpoint this machine can actually reach."""
        keys = list(keys or self.settings.get("symbols") or ["geram18"])
        symbol = self.resolve(keys[:1])[0]
        report = tgju_intraday.probe(symbol)
        working = next((row for row in report if row.get("ok")), None)
        if working:
            self._pin_endpoint(working["endpoint"])
        return {"symbol": symbol.key, "working": working, "attempts": report}

    def sample_intraday(self, keys: Sequence[str] | None = None) -> dict:
        """Record one intraday sample per watched symbol.

        A failed fetch records nothing: ``points_for`` deliberately falls back
        to cached data when the network is down ("stale data beats no data"),
        which is right for a report but wrong here - storing a stale price
        under a fresh timestamp would invent an observation that never
        happened.
        """
        keys = list(keys or self.settings.get("symbols") or [])
        recorded: list[str] = []
        errors: list[dict[str, str]] = []

        for symbol in self.resolve(keys):
            try:
                points, from_cache = self.points_for(symbol, force=True)
            except TgjuError as exc:
                errors.append({"symbol": symbol.key, "name": symbol.name, "message": str(exc)})
                continue
            if from_cache:
                errors.append(
                    {
                        "symbol": symbol.key,
                        "name": symbol.name,
                        "message": "قیمت تازه از TGJU دریافت نشد؛ برای جلوگیری از ثبت داده نادرست، نمونه‌ای ذخیره نشد.",
                    }
                )
                continue
            if points and intraday.record(symbol.key, points[-1].close):
                recorded.append(symbol.key)

        pruned = intraday.prune()
        self.settings.update({"last_sample": int(time.time())})
        return {
            "recorded": recorded,
            "errors": errors,
            "pruned": pruned,
            "intraday": intraday.summary(),
        }

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
