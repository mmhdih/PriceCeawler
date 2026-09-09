"""Extract intraday candles from TGJU for a requested time range.

This is the *extraction* path: ask TGJU for the prices of a past range at a
sub-daily resolution and hand them back. It is what you want when you need
"the last three days at 10-minute precision" right now, as opposed to the
sampler in :mod:`intraday`, which can only ever tell you about time that has
already passed while it was running.

TGJU's charts are drawn by a TradingView charting library, which talks the
UDF ("UDF" = TradingView's universal data feed) protocol:

    GET <base>/history?symbol=<sym>&resolution=<r>&from=<unix>&to=<unix>
    -> {"s": "ok", "t": [...], "o": [...], "h": [...], "l": [...], "c": [...]}

``s`` is the status: ``ok``, ``no_data`` (with an optional ``nextTime``), or
``error``. Because that endpoint is not a documented, contractual API, the
URL is *data driven*: :data:`CANDIDATES` lists the shapes we know how to try,
the user can override it from settings, and :func:`probe` reports which one a
given machine can actually reach. Nothing here guesses silently - if no
candidate answers, the caller gets a clear error naming what was tried.

Only the finest resolution TGJU will serve is fetched; 10-minute, hourly and
per-change rows are then produced by :mod:`intraday`'s own aggregation, so
one code path decides bucketing for both recorded and extracted data.
"""

from __future__ import annotations

import json
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Callable, Iterable, Sequence

from .symbols import Symbol
from .tgju import TgjuError, _USER_AGENT

__all__ = [
    "Candle",
    "CANDIDATES",
    "Endpoint",
    "fetch_candles",
    "parse_udf",
    "parse_rows",
    "probe",
    "endpoints_from_setting",
    "resolutions_from_setting",
    "MAX_SPAN_SECONDS",
    "median_gap",
    "is_intraday_spacing",
    "NATIVE_RESOLUTIONS",
]

# Resolution codes to try, finest first. Standard TradingView UDF uses bare
# minute counts, but this endpoint is undocumented and observably ignores
# values it does not recognise (returning daily bars instead of an error), so
# several spellings are tried and the *returned* spacing decides which one
# actually gave intraday data - see `is_intraday_spacing`.
NATIVE_RESOLUTIONS = (
    "1", "5", "10", "15", "30", "60",
    "1m", "5m", "10m", "15m", "30m", "60m",
    "1min", "5min", "60min", "1H",
)

# A day of seconds. Bars spaced this far apart are daily bars, whatever
# resolution was asked for, and cannot answer an intraday question.
DAY_SECONDS = 86400

# Spacing must be at least this much finer than a day to count as intraday.
# Half a day is deliberately loose: a feed with long market gaps can still be
# genuinely intraday, and the aggregation layer copes with sparse buckets.
MAX_INTRADAY_SPACING = DAY_SECONDS // 2

# Chart backends routinely cap how much history one request may span. Ask in
# windows and stitch, rather than sending one huge range and getting nothing.
MAX_SPAN_SECONDS = 7 * 86400

_REQUEST_TIMEOUT = 30.0
_MAX_WINDOWS = 60  # a hard stop, so a wide range cannot spin forever


@dataclass(frozen=True)
class Candle:
    """One OHLC bar at whatever resolution the source served."""

    ts: int
    open: float | None
    high: float | None
    low: float | None
    close: float | None


@dataclass(frozen=True)
class Endpoint:
    """One way of asking TGJU for intraday candles.

    ``url`` is a template taking ``symbol``, ``resolution``, ``from`` and
    ``to``; ``symbol_style`` says how to spell the symbol for this backend.
    """

    name: str
    url: str
    symbol_style: str = "key"          # "key" | "upper" | "upper_underscore"
    parser: str = "udf"                # "udf" | "rows"
    referer: str = "https://www.tgju.org/"


# Ordered by how likely they are to be the live endpoint. `probe()` walks
# this list; the first that returns usable candles wins and can be pinned
# into settings so later requests skip the probing entirely.
CANDIDATES: tuple[Endpoint, ...] = (
    Endpoint(
        name="platform-tvdata",
        url=(
            "https://platform.tgju.org/fa/tvdata/history"
            "?symbol={symbol}&resolution={resolution}&from={from}&to={to}"
        ),
        symbol_style="key",
        referer="https://platform.tgju.org/",
    ),
    Endpoint(
        name="platform-tvdata-upper",
        url=(
            "https://platform.tgju.org/fa/tvdata/history"
            "?symbol={symbol}&resolution={resolution}&from={from}&to={to}"
        ),
        symbol_style="upper_underscore",
        referer="https://platform.tgju.org/",
    ),
    Endpoint(
        name="api-tvdata",
        url=(
            "https://api.tgju.org/v1/tvdata/history"
            "?symbol={symbol}&resolution={resolution}&from={from}&to={to}"
        ),
        symbol_style="key",
    ),
    Endpoint(
        name="api-chart-intraday",
        url=(
            "https://api.tgju.org/v1/market/indicator/chart-data/{symbol}"
            "?resolution={resolution}&from={from}&to={to}"
        ),
        symbol_style="key",
        parser="rows",
    ),
)


def resolutions_from_setting(pinned: str) -> tuple[str, ...]:
    """Try a previously working resolution code first, then the rest.

    It stays a *preference*, not a lock: the code is still validated by the
    granularity check, so a feed that changes behaviour falls through to the
    other spellings instead of silently serving daily bars again.
    """
    pinned = (pinned or "").strip()
    if not pinned or pinned not in NATIVE_RESOLUTIONS:
        return NATIVE_RESOLUTIONS
    return (pinned,) + tuple(r for r in NATIVE_RESOLUTIONS if r != pinned)


def endpoints_from_setting(pinned: str) -> tuple[Endpoint, ...]:
    """Resolve the ``intraday_endpoint`` setting into endpoints to try.

    Empty means "try every candidate". A candidate's name pins it, so a
    machine that has already discovered the working one stops probing. A
    value containing ``{symbol}`` is a full URL template the user supplied
    themselves - the escape hatch for when none of our candidates is right,
    so a wrong guess here never needs a code change.
    """
    pinned = (pinned or "").strip()
    if not pinned:
        return CANDIDATES
    if "{symbol}" in pinned:
        return (
            Endpoint(
                name="custom",
                url=pinned,
                # A hand-pasted URL usually already carries the exact symbol
                # spelling the backend wants.
                symbol_style="key",
                parser="udf" if "tvdata" in pinned or "history" in pinned else "rows",
            ),
        )
    for endpoint in CANDIDATES:
        if endpoint.name == pinned:
            return (endpoint,)
    return CANDIDATES


def _symbol_for(symbol: Symbol, style: str) -> str:
    key = symbol.key
    if style == "upper":
        return key.upper()
    if style == "upper_underscore":
        return key.replace("-", "_").upper()
    return key


def _build_url(endpoint: Endpoint, symbol: Symbol, resolution: str,
               from_ts: int, to_ts: int) -> str:
    return endpoint.url.format(
        symbol=urllib.parse.quote(_symbol_for(symbol, endpoint.symbol_style), safe=""),
        resolution=urllib.parse.quote(str(resolution), safe=""),
        **{"from": int(from_ts), "to": int(to_ts)},
    )


# -- parsing (pure: no network) ----------------------------------------------


def _number(value: Any, divisor: float) -> float | None:
    """A price, converted to the display unit; anything unusable becomes None."""
    if value is None or isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    if number != number or number in (float("inf"), float("-inf")):  # NaN/inf
        return None
    if number == 0:
        return None
    return number / divisor


def parse_udf(payload: Any, symbol: Symbol) -> list[Candle]:
    """Parse TradingView's UDF history shape into candles.

    ``s: "no_data"`` is a valid, empty answer - the range simply has no bars -
    so it yields ``[]`` rather than raising. ``s: "error"`` is a real failure.
    """
    if not isinstance(payload, dict):
        raise TgjuError("پاسخ نمودار درون‌روزی TGJU قابل خواندن نیست.")

    status = str(payload.get("s", "")).lower()
    if status == "error":
        detail = str(payload.get("errmsg") or payload.get("error") or "").strip()
        raise TgjuError(
            "سرویس نمودار TGJU خطا برگرداند" + (f": {detail}" if detail else ".")
        )
    if status == "no_data":
        return []
    if status and status != "ok":
        raise TgjuError(f"وضعیت ناشناخته «{status}» از سرویس نمودار TGJU.")

    times = payload.get("t")
    if not isinstance(times, list):
        raise TgjuError("سرویس نمودار TGJU فهرست زمان‌ها را برنگرداند.")

    divisor = symbol.divisor

    def column(name: str) -> list[Any]:
        values = payload.get(name)
        return values if isinstance(values, list) else []

    opens, highs, lows, closes = (column(k) for k in ("o", "h", "l", "c"))

    def at(values: list[Any], index: int) -> float | None:
        return _number(values[index], divisor) if index < len(values) else None

    candles: list[Candle] = []
    for index, raw_ts in enumerate(times):
        try:
            ts = int(raw_ts)
        except (TypeError, ValueError):
            continue
        if ts <= 0:
            continue
        # Some feeds send milliseconds; anything past the year 5000 is one.
        if ts > 100_000_000_000:
            ts //= 1000
        candle = Candle(ts, at(opens, index), at(highs, index),
                        at(lows, index), at(closes, index))
        if candle.close is None and candle.open is None:
            continue  # a bar with no price at all is not an observation
        candles.append(candle)

    candles.sort(key=lambda c: c.ts)
    return candles


def parse_rows(payload: Any, symbol: Symbol) -> list[Candle]:
    """Parse a row-per-bar shape: ``{"data": [[ts, o, h, l, c], ...]}``.

    Also accepts dict rows keyed by name, since a second endpoint shape is
    the whole reason this parser exists.
    """
    if isinstance(payload, dict):
        rows = payload.get("data") or payload.get("rows") or payload.get("candles") or []
    elif isinstance(payload, list):
        rows = payload
    else:
        raise TgjuError("پاسخ نمودار درون‌روزی TGJU قابل خواندن نیست.")
    if not isinstance(rows, list):
        raise TgjuError("فهرست داده‌های نمودار در پاسخ TGJU یافت نشد.")

    divisor = symbol.divisor
    candles: list[Candle] = []
    for row in rows:
        if isinstance(row, dict):
            raw_ts = row.get("t") or row.get("time") or row.get("timestamp") or row.get("date")
            values = (row.get("o") or row.get("open"), row.get("h") or row.get("high"),
                      row.get("l") or row.get("low"), row.get("c") or row.get("close"))
        elif isinstance(row, (list, tuple)) and len(row) >= 2:
            raw_ts = row[0]
            padded = list(row[1:5]) + [None] * 4
            values = tuple(padded[:4])
        else:
            continue

        try:
            ts = int(float(raw_ts))
        except (TypeError, ValueError):
            continue
        if ts <= 0:
            continue
        if ts > 100_000_000_000:
            ts //= 1000

        open_, high, low, close = (_number(v, divisor) for v in values)
        if close is None and open_ is None:
            continue
        candles.append(Candle(ts, open_, high, low, close))

    candles.sort(key=lambda c: c.ts)
    return candles


_PARSERS: dict[str, Callable[[Any, Symbol], list[Candle]]] = {
    "udf": parse_udf,
    "rows": parse_rows,
}


# -- network ------------------------------------------------------------------


def _request(url: str, referer: str, timeout: float, opener=None) -> Any:
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": _USER_AGENT,
            "X-Requested-With": "XMLHttpRequest",
            "Accept": "application/json, text/javascript, */*; q=0.01",
            "Accept-Language": "fa,en;q=0.8",
            "Referer": referer,
        },
    )
    open_url = opener or urllib.request.urlopen
    with open_url(request, timeout=timeout) as response:
        raw = response.read()
    try:
        return json.loads(raw.decode("utf-8", errors="replace"))
    except json.JSONDecodeError as exc:
        raise TgjuError("پاسخ سرویس نمودار TGJU یک JSON معتبر نبود.") from exc


def _windows(from_ts: int, to_ts: int, span: int) -> Iterable[tuple[int, int]]:
    """Split a range into request-sized windows, oldest first."""
    start = int(from_ts)
    end = int(to_ts)
    guard = 0
    while start <= end and guard < _MAX_WINDOWS:
        stop = min(start + span, end)
        yield start, stop
        if stop >= end:
            return
        start = stop + 1
        guard += 1


def median_gap(candles: Sequence[Candle]) -> int | None:
    """Median seconds between consecutive bars, or None with fewer than two.

    The median, not the mean: an overnight or weekend gap would drag a mean
    up past a day and make genuinely intraday data look daily.
    """
    if len(candles) < 2:
        return None
    gaps = sorted(
        int(later.ts) - int(earlier.ts)
        for earlier, later in zip(candles, candles[1:])
        if int(later.ts) > int(earlier.ts)
    )
    if not gaps:
        return None
    middle = len(gaps) // 2
    if len(gaps) % 2:
        return gaps[middle]
    return (gaps[middle - 1] + gaps[middle]) // 2


def is_intraday_spacing(candles: Sequence[Candle]) -> bool:
    """Whether these bars are actually finer than daily.

    This endpoint accepts an unrecognised resolution and answers with daily
    bars rather than an error, so "I got rows" is not evidence of intraday
    data. Without this check a daily series would be relabelled as
    10-minute rows - a wrong answer, which is worse than no answer.

    A single bar carries no spacing, so it is judged by its timestamp: a
    daily bar sits exactly on a day boundary (00:00 UTC for this feed).
    """
    gap = median_gap(candles)
    if gap is not None:
        return gap <= MAX_INTRADAY_SPACING
    if len(candles) == 1:
        return int(candles[0].ts) % DAY_SECONDS != 0
    return False


def _fetch_one(endpoint: Endpoint, symbol: Symbol, resolution: str,
               from_ts: int, to_ts: int, *, timeout: float, opener=None) -> list[Candle]:
    parser = _PARSERS.get(endpoint.parser, parse_udf)
    candles: dict[int, Candle] = {}
    for window_from, window_to in _windows(from_ts, to_ts, MAX_SPAN_SECONDS):
        url = _build_url(endpoint, symbol, resolution, window_from, window_to)
        payload = _request(url, endpoint.referer, timeout, opener)
        for candle in parser(payload, symbol):
            if from_ts <= candle.ts <= to_ts:
                candles[candle.ts] = candle
    return [candles[ts] for ts in sorted(candles)]


def fetch_candles(
    symbol: Symbol,
    from_ts: int,
    to_ts: int,
    *,
    endpoints: Sequence[Endpoint] | None = None,
    resolutions: Sequence[str] = NATIVE_RESOLUTIONS,
    timeout: float = _REQUEST_TIMEOUT,
    opener=None,
) -> tuple[list[Candle], Endpoint, str]:
    """Fetch the finest intraday candles available for a range.

    Returns ``(candles, endpoint, resolution)`` so the caller can report - and
    pin - whatever actually worked. Raises :class:`TgjuError` naming every
    attempt when nothing does.
    """
    if to_ts < from_ts:
        raise TgjuError("بازه درخواستی نامعتبر است (پایان قبل از شروع).")

    attempts: list[str] = []
    saw_daily = False
    for endpoint in (endpoints or CANDIDATES):
        for resolution in resolutions:
            try:
                candles = _fetch_one(
                    endpoint, symbol, resolution, from_ts, to_ts,
                    timeout=timeout, opener=opener,
                )
            except (TgjuError, urllib.error.URLError, OSError, ValueError) as exc:
                attempts.append(f"{endpoint.name}/{resolution}: {type(exc).__name__}")
                continue
            if not candles:
                attempts.append(f"{endpoint.name}/{resolution}: بدون داده")
                continue
            # Rows are not enough: this endpoint answers an unrecognised
            # resolution with DAILY bars instead of erroring, and relabelling
            # those as 10-minute rows would be a wrong answer.
            if not is_intraday_spacing(candles):
                saw_daily = True
                gap = median_gap(candles)
                attempts.append(
                    f"{endpoint.name}/{resolution}: داده روزانه"
                    + (f" (فاصله {gap // 3600} ساعت)" if gap else "")
                )
                continue
            return candles, endpoint, resolution

    if saw_daily:
        raise TgjuError(
            "سرویس نمودار TGJU برای این نماد فقط داده روزانه برگرداند و هیچ‌کدام از"
            " دقت‌های درون‌روزی را نپذیرفت؛ پس گزارش ۱۰ دقیقه/۱ ساعت از این منبع"
            " ساخته نمی‌شود. آدرس درست سرویس درون‌روزی را از DevTools بردارید و در"
            " تنظیمات وارد کنید. تلاش‌ها: " + "؛ ".join(attempts[:6])
        )
    raise TgjuError(
        "سرویس نمودار درون‌روزی TGJU پاسخ قابل استفاده‌ای نداد. "
        "تلاش‌ها: " + "؛ ".join(attempts[:8])
    )


def probe(symbol: Symbol, *, hours: int = 48, timeout: float = 15.0,
          opener=None) -> list[dict[str, Any]]:
    """Try every candidate endpoint and report what each one did.

    This is the diagnostic behind the ``probe-intraday`` command: it turns
    "the chart endpoint is undocumented" into one command whose output says
    exactly which URL works from the user's own network.
    """
    to_ts = int(time.time())
    from_ts = to_ts - hours * 3600
    report: list[dict[str, Any]] = []

    for endpoint in CANDIDATES:
        for resolution in NATIVE_RESOLUTIONS:
            row: dict[str, Any] = {
                "endpoint": endpoint.name,
                "resolution": resolution,
                "url": _build_url(endpoint, symbol, resolution, from_ts, to_ts),
                # Default to "not working": every later branch either proves
                # intraday data or leaves this false, so a row is never
                # missing the verdict callers read.
                "ok": False,
            }
            try:
                candles = _fetch_one(
                    endpoint, symbol, resolution, from_ts, to_ts,
                    timeout=timeout, opener=opener,
                )
                row["candles"] = len(candles)
                if candles:
                    gap = median_gap(candles)
                    row["spacing_seconds"] = gap
                    row["intraday"] = is_intraday_spacing(candles)
                    row["first"] = candles[0].ts
                    row["last"] = candles[-1].ts
                    row["sample_close"] = candles[-1].close
                    # Only genuinely sub-daily data counts as working; daily
                    # bars are reported so the output shows what went wrong.
                    row["ok"] = row["intraday"]
                    if not row["intraday"]:
                        row["error"] = "داده روزانه، نه درون‌روزی"
            except Exception as exc:  # a probe must report, never raise
                row["ok"] = False
                row["error"] = f"{type(exc).__name__}: {exc}"
            report.append(row)
            if row.get("ok"):
                return report  # first success is enough; stop hammering
    return report
