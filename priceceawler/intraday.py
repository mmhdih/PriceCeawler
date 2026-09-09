"""Intraday resampling, plus optional local sampling of live prices.

Two things live here. The important one is *resampling*: ``aggregate()`` and
``aggregate_candles()`` turn observations into the resolution the user asked
for - 10-minute and hourly OHLC buckets, or one row per price change. Both
the candles extracted from TGJU (see :mod:`tgju_intraday`) and any locally
recorded samples go through this same bucketing, so the two sources can never
disagree about where a bucket starts.

The secondary one is a local sample store: ``record()`` appends a
(timestamp, price) observation per symbol, kept one file per symbol per day
so a day is a small read and retention pruning is just "delete files whose
name is older than N days". That store is a *fallback* for when the chart
service cannot be reached - it only ever covers time that already passed
while sampling was switched on, which is why extraction is preferred.

All calendar/clock rendering uses Tehran time, not the machine's, so the
timestamps on a report are the trading clock regardless of where it runs.
"""

from __future__ import annotations

import math
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Mapping, Sequence

from .jalali import JalaliDate, gregorian_to_jalali
from .storage import data_dir, read_json, write_json
from .symbols import Symbol

__all__ = [
    "TEHRAN",
    "RESOLUTIONS",
    "RETENTION_DAYS",
    "is_intraday",
    "normalise_resolution",
    "record",
    "load_samples",
    "prune",
    "summary",
    "aggregate",
    "aggregate_candles",
    "build_rows",
    "explain_empty",
    "symbol_summary",
    "range_bounds",
]

# Iran has had no DST since 2022, so a fixed +03:30 is correct year-round.
TEHRAN = timezone(timedelta(hours=3, minutes=30))

RES_10M = "10m"
RES_1H = "1h"
RES_TICK = "tick"
RES_DAILY = "daily"

_BUCKET_SECONDS = {RES_10M: 600, RES_1H: 3600}

RESOLUTIONS: tuple[dict[str, Any], ...] = (
    {"id": RES_DAILY, "label": "روزانه", "intraday": False},
    {"id": RES_10M, "label": "هر ۱۰ دقیقه", "intraday": True},
    {"id": RES_1H, "label": "هر ۱ ساعت", "intraday": True},
    {"id": RES_TICK, "label": "هر تغییر قیمت", "intraday": True},
)

RETENTION_DAYS = 30
MAX_SAMPLES_PER_DAY = 2000


def is_intraday(resolution: str | None) -> bool:
    return resolution in (RES_10M, RES_1H, RES_TICK)


def normalise_resolution(resolution: Any) -> str:
    resolution = resolution.strip() if isinstance(resolution, str) else ""
    return resolution if is_intraday(resolution) else RES_DAILY


# -- Tehran-local clock helpers ---------------------------------------------


def _local(timestamp: float) -> datetime:
    return datetime.fromtimestamp(float(timestamp), TEHRAN)


def local_iso_date(timestamp: float) -> str:
    """The day-file key: Tehran-local ISO date."""
    return _local(timestamp).strftime("%Y-%m-%d")


def local_time(timestamp: float) -> str:
    return _local(timestamp).strftime("%H:%M")


def local_jalali(timestamp: float) -> JalaliDate:
    moment = _local(timestamp)
    return JalaliDate(*gregorian_to_jalali(moment.year, moment.month, moment.day))


def bucket_start(timestamp: float, resolution: str) -> int:
    """Start of the bucket a timestamp falls in, aligned to the Tehran clock.

    Aligning on local time matters: Tehran is offset by a half hour, so a
    UTC-aligned hourly bucket would start at :30 past every local hour.
    """
    width = _BUCKET_SECONDS.get(resolution, 0)
    if width <= 0:
        return int(timestamp)
    offset = int(TEHRAN.utcoffset(None).total_seconds())
    return ((int(timestamp) + offset) // width) * width - offset


def range_bounds(start: JalaliDate, end: JalaliDate) -> tuple[int, int]:
    """Inclusive unix range covering a Jalali start..end day pair, Tehran-local."""
    first = start.to_gregorian()
    last = end.to_gregorian()
    from_ts = datetime(first.year, first.month, first.day, 0, 0, 0, tzinfo=TEHRAN)
    to_ts = datetime(last.year, last.month, last.day, 23, 59, 59, tzinfo=TEHRAN)
    return int(from_ts.timestamp()), int(to_ts.timestamp())


# -- storage ----------------------------------------------------------------


def base_dir() -> Path:
    directory = data_dir() / "intraday"
    directory.mkdir(parents=True, exist_ok=True)
    return directory


def _safe(symbol_key: str) -> str:
    return "".join(c if c.isalnum() or c in "-_." else "_" for c in symbol_key) or "_"


def _symbol_dir(symbol_key: str) -> Path:
    return base_dir() / _safe(symbol_key)


def _day_path(symbol_key: str, iso_date: str) -> Path:
    return _symbol_dir(symbol_key) / f"{iso_date}.json"


def _read_day(path: Path) -> dict[int, float]:
    payload = read_json(path)
    if not isinstance(payload, dict):
        return {}
    samples: dict[int, float] = {}
    for pair in payload.get("samples") or []:
        if not isinstance(pair, (list, tuple)) or len(pair) < 2:
            continue
        try:
            ts, price = int(pair[0]), float(pair[1])
        except (TypeError, ValueError):
            continue
        if ts > 0 and price > 0:
            samples[ts] = price
    return dict(sorted(samples.items()))


def record(symbol_key: str, price: float | None, timestamp: float | None = None) -> bool:
    """Append one observed price.

    Returns False when the price is unusable or that exact second is already
    recorded, so a double-fired scheduler is harmless.
    """
    try:
        price = float(price)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return False
    # NaN/inf survive a `<= 0` test but serialise as the bare tokens NaN and
    # Infinity, which are not valid JSON - one such sample would make every
    # later JSON.parse of this symbol's data fail in the browser.
    if not math.isfinite(price) or price <= 0:
        return False

    ts = int(time.time() if timestamp is None else timestamp)
    path = _day_path(symbol_key, local_iso_date(ts))
    samples = _read_day(path)
    if ts in samples:
        return False

    samples[ts] = price
    ordered = dict(sorted(samples.items()))
    if len(ordered) > MAX_SAMPLES_PER_DAY:
        ordered = dict(list(ordered.items())[-MAX_SAMPLES_PER_DAY:])

    path.parent.mkdir(parents=True, exist_ok=True)
    write_json(path, {"symbol": symbol_key, "samples": [[ts, p] for ts, p in ordered.items()]})
    return True


def load_samples(symbol_key: str, from_ts: float, to_ts: float) -> dict[int, float]:
    """Every stored sample for a symbol in a unix range, across day files."""
    samples: dict[int, float] = {}
    day = _local(from_ts).date()
    last = _local(to_ts).date()
    while day <= last:
        for ts, price in _read_day(_day_path(symbol_key, day.strftime("%Y-%m-%d"))).items():
            if from_ts <= ts <= to_ts:
                samples[ts] = price
        day += timedelta(days=1)
    return dict(sorted(samples.items()))


def prune(now: float | None = None, days: int | None = None) -> int:
    """Delete day files past the retention window. Returns files removed.

    ``days`` defaults to the user's setting; RETENTION_DAYS is only the
    fallback when no settings file has been written yet.
    """
    now = time.time() if now is None else now
    if days is None:
        from .storage import Settings

        days = Settings().retention_days()
    days = max(1, int(days))
    cutoff = local_iso_date(now - days * 86400)
    removed = 0
    for directory in base_dir().iterdir():
        if not directory.is_dir():
            continue
        for path in directory.glob("*.json"):
            if path.stem < cutoff:
                try:
                    path.unlink()
                    removed += 1
                except OSError:
                    pass
    return removed


def summary() -> list[dict[str, Any]]:
    """Per-symbol overview: how much intraday data actually exists."""
    rows: list[dict[str, Any]] = []
    for directory in sorted(base_dir().iterdir()):
        if not directory.is_dir():
            continue
        files = sorted(directory.glob("*.json"))
        if not files:
            continue
        rows.append(
            {
                "key": directory.name,
                "days": len(files),
                "samples": sum(len(_read_day(path)) for path in files),
                "first": files[0].stem,
                "last": files[-1].stem,
            }
        )
    return rows


# -- aggregation (pure: no storage, no network) ------------------------------


def _round(value: float | None, decimals: int) -> float | int | None:
    if value is None:
        return None
    return int(round(value)) if decimals <= 0 else round(value, decimals)


def _make_row(
    ts: int,
    open_: float,
    low: float,
    high: float,
    close: float,
    average: float,
    count: int,
    decimals: int,
) -> dict[str, Any]:
    jalali = local_jalali(ts)
    return {
        "ts": int(ts),
        "date": str(jalali),
        "time": local_time(ts),
        "weekday": jalali.weekday_name,
        "open": _round(open_, decimals),
        "low": _round(low, decimals),
        "high": _round(high, decimals),
        "close": _round(close, decimals),
        "average": _round(average, decimals),
        "samples": int(count),
        "status": "معامله شده",
        "live": True,
    }


def aggregate(
    samples: Mapping[int, float], resolution: str, decimals: int = 0
) -> list[dict[str, Any]]:
    """Resample raw samples into the requested resolution.

    10m/1h produce one OHLC row per non-empty bucket - empty buckets are
    skipped rather than carried forward, because an unsampled ten minutes is
    genuinely "no observation", not a flat price. ``tick`` keeps only the
    samples whose price differs from the previous kept one, which is what
    "every time it changed" means for sampled data.
    """
    if not samples:
        return []
    ordered = sorted(samples.items())

    if resolution == RES_TICK:
        rows: list[dict[str, Any]] = []
        previous: float | None = None
        for ts, price in ordered:
            price = float(price)
            if previous is not None and _round(price, decimals) == _round(previous, decimals):
                continue
            row = _make_row(ts, price, price, price, price, price, 1, decimals)
            row["change"] = None if previous is None else _round(price - previous, decimals)
            rows.append(row)
            previous = price
        return rows

    if resolution not in _BUCKET_SECONDS:
        return []

    buckets: dict[int, list[float]] = {}
    for ts, price in ordered:
        buckets.setdefault(bucket_start(ts, resolution), []).append(float(price))

    return [
        _make_row(
            start,
            prices[0],
            min(prices),
            max(prices),
            prices[-1],
            # The mean of the actual observations in the window - a truer
            # "average traded price" than the daily table's (low+high+close)/3
            # stand-in, which exists only because the daily endpoint gives no
            # intra-day observations at all.
            sum(prices) / len(prices),
            len(prices),
            decimals,
        )
        for start, prices in sorted(buckets.items())
    ]


def symbol_summary(symbol_key: str) -> dict[str, str] | None:
    """First/last recorded day for one symbol, or None if it has no data."""
    files = sorted(_symbol_dir(symbol_key).glob("*.json"))
    if not files:
        return None
    return {"first": files[0].stem, "last": files[-1].stem}


_FA_DIGITS = str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹")


def _jalali_of_iso(iso: str) -> str:
    """'2025-08-19' -> '۱۴۰۴/۰۵/۲۸', to read like the rest of the Persian UI."""
    parts = iso.split("-")
    if len(parts) != 3:
        return iso
    try:
        year, month, day = (int(p) for p in parts)
    except ValueError:
        return iso
    return str(JalaliDate(*gregorian_to_jalali(year, month, day))).translate(_FA_DIGITS)


def explain_empty(symbol: Symbol, recording: bool) -> str:
    """Say *why* a symbol has no intraday rows, and what to do about it.

    "No samples recorded" on its own is a dead end: intraday data only exists
    from the moment recording starts, so the useful answer is whether
    recording is off, or on but younger than the range that was asked for.
    """
    stored = symbol_summary(symbol.key)
    if not stored:
        if recording:
            return (
                f"ثبت خودکار روشن است اما هنوز هیچ نمونه‌ای برای «{symbol.name}» ذخیره نشده؛"
                " چند دقیقه صبر کنید یا «ثبت نمونه همین حالا» را بزنید."
            )
        return (
            f"برای «{symbol.name}» هنوز داده درون‌روزی وجود ندارد. کلید «ثبت خودکار قیمت هر ۱۰ دقیقه»"
            " را روشن کنید؛ از همان لحظه ثبت شروع می‌شود (داده گذشته قابل بازیابی نیست)."
        )
    first = _jalali_of_iso(stored["first"])
    last = _jalali_of_iso(stored["last"])
    return (
        f"ثبت درون‌روزی «{symbol.name}» از {first} شروع شده و تا {last} داده دارد؛"
        " بازه‌ای که انتخاب کرده‌اید بیرون از این محدوده است. بازه را به «امروز» تغییر دهید."
    )


def aggregate_candles(
    candles: Sequence[Any], resolution: str, decimals: int = 0
) -> list[dict[str, Any]]:
    """Resample OHLC bars (from TGJU) into the requested resolution.

    Distinct from :func:`aggregate`, which takes single observed prices: when
    the source already gives bars, a bucket's high is the max of the bars'
    highs and its low the min of their lows. Collapsing them to closes first
    would quietly discard the extremes inside each bucket.
    """
    if not candles:
        return []
    ordered = sorted(candles, key=lambda c: int(c.ts))

    if resolution == RES_TICK:
        # "Every change" over historical bars means: one row per bar whose
        # close differs from the previous kept one.
        rows: list[dict[str, Any]] = []
        previous: float | None = None
        for candle in ordered:
            price = candle.close if candle.close is not None else candle.open
            if price is None:
                continue
            price = float(price)
            if previous is not None and _round(price, decimals) == _round(previous, decimals):
                continue
            row = _make_row(int(candle.ts), price, price, price, price, price, 1, decimals)
            row["change"] = None if previous is None else _round(price - previous, decimals)
            rows.append(row)
            previous = price
        return rows

    if resolution not in _BUCKET_SECONDS:
        return []

    buckets: dict[int, list[Any]] = {}
    for candle in ordered:
        buckets.setdefault(bucket_start(int(candle.ts), resolution), []).append(candle)

    rows = []
    for start, bars in sorted(buckets.items()):
        closes = [float(b.close) for b in bars if b.close is not None]
        opens = [float(b.open) for b in bars if b.open is not None]
        highs = [float(b.high) for b in bars if b.high is not None] or closes
        lows = [float(b.low) for b in bars if b.low is not None] or closes
        if not closes and not opens:
            continue
        open_ = opens[0] if opens else closes[0]
        close = closes[-1] if closes else opens[-1]
        prices = closes or opens
        rows.append(
            _make_row(
                start,
                open_,
                min(lows) if lows else close,
                max(highs) if highs else close,
                close,
                sum(prices) / len(prices),
                len(bars),
                decimals,
            )
        )
    return rows


def build_rows(
    symbol: Symbol, start: JalaliDate, end: JalaliDate, resolution: str
) -> list[dict[str, Any]]:
    """Aggregated intraday rows for one symbol over a Jalali range."""
    from_ts, to_ts = range_bounds(start, end)
    return aggregate(load_samples(symbol.key, from_ts, to_ts), resolution, symbol.decimals)


def stats(rows: Sequence[Mapping[str, Any]], symbol: Symbol) -> dict[str, Any]:
    """Statistics in the same shape report.build_series() produces."""
    closes = [row["close"] for row in rows if row.get("close") is not None]
    first = closes[0] if closes else None
    last = closes[-1] if closes else None

    change = change_pct = None
    if first is not None and last is not None and first != 0:
        change = _round(last - first, symbol.decimals)
        change_pct = round(((last - first) / first) * 100, 2)

    return {
        "days": len(rows),
        "trading_days": len(closes),
        "first": first,
        "last": last,
        "min": min(closes) if closes else None,
        "max": max(closes) if closes else None,
        "mean": _round(sum(closes) / len(closes), symbol.decimals) if closes else None,
        "change": change,
        "change_pct": change_pct,
        "unit": symbol.unit_label,
    }
