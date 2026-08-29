"""Client for the TGJU (tgju.org) historical price API."""

from __future__ import annotations

import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, asdict
from typing import Any, Sequence

from .jalali import JalaliDate, to_english_digits
from .symbols import Symbol
from .version import __version__

__all__ = ["TgjuError", "PricePoint", "fetch_history", "parse_rows", "API_URL"]

API_URL = (
    "https://api.tgju.org/v1/market/indicator/summary-table-data/{symbol}"
    "?lang=fa&order_dir=asc&start=0&length={length}"
)

_USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    f"(KHTML, like Gecko) Chrome/124.0 Safari/537.36 PriceCeawler/{__version__}"
)

_TAG_RE = re.compile(r"<[^>]+>")
_JALALI_RE = re.compile(r"^1[2-5]\d{2}/\d{1,2}/\d{1,2}$")
_GREGORIAN_RE = re.compile(r"^(19|20)\d{2}[/-]\d{1,2}[/-]\d{1,2}$")
_NUMBER_RE = re.compile(r"^-?\d+(\.\d+)?$")


class TgjuError(RuntimeError):
    """Raised when data cannot be fetched or understood."""


@dataclass(frozen=True)
class PricePoint:
    """One trading day for one symbol, already converted to the display unit."""

    date: str          # Jalali, YYYY/MM/DD
    gregorian: str     # ISO, YYYY-MM-DD ("" when the API omits it)
    open: float | None
    low: float | None
    high: float | None
    close: float | None

    @property
    def average(self) -> float | None:
        """Mean of low/high/close - the "میانگین معاملاتی" used in reports."""
        values = [v for v in (self.low, self.high, self.close) if v is not None]
        if not values:
            return None
        return sum(values) / len(values)

    def to_dict(self) -> dict:
        data = asdict(self)
        data["average"] = self.average
        return data


def _clean(value: Any) -> str:
    """Strip HTML, thousands separators and Persian digits from a cell."""
    text = _TAG_RE.sub("", str(value if value is not None else ""))
    text = to_english_digits(text)
    return text.replace(",", "").replace("٬", "").replace("%", "").strip()


def _as_number(value: Any, divisor: float) -> float | None:
    text = _clean(value)
    if not text or not _NUMBER_RE.match(text):
        return None
    number = float(text)
    if number == 0:
        return None
    return number / divisor


def _normalise_jalali(value: Any) -> str | None:
    text = to_english_digits(_TAG_RE.sub("", str(value or ""))).strip().replace("-", "/")
    if not _JALALI_RE.match(text):
        return None
    year, month, day = (int(part) for part in text.split("/"))
    try:
        return str(JalaliDate(year, month, day))
    except ValueError:
        return None


def _normalise_gregorian(value: Any) -> str:
    text = to_english_digits(_TAG_RE.sub("", str(value or ""))).strip().replace("/", "-")
    if not _GREGORIAN_RE.match(text.replace("-", "/").replace("/", "-")):
        return ""
    year, month, day = (int(part) for part in text.split("-"))
    return f"{year:04d}-{month:02d}-{day:02d}"


def _row_values(row: Any) -> list[Any]:
    if isinstance(row, dict):
        preferred = ("open", "low", "high", "close", "change", "percent", "date_gregorian", "date")
        if any(key in row for key in preferred):
            return [row.get(key) for key in preferred]
        return list(row.values())
    if isinstance(row, (list, tuple)):
        return list(row)
    raise TgjuError("قالب داده دریافتی از TGJU شناخته شده نیست.")


def parse_rows(payload: Any, symbol: Symbol) -> list[PricePoint]:
    """Turn a raw API payload into sorted, de-duplicated price points.

    The table columns are ``[open, low, high, close, change, percent,
    gregorian, jalali]``, but the endpoint is not contractual, so dates are
    located by pattern and prices are read from the leading numeric cells.
    """
    if isinstance(payload, dict):
        rows = payload.get("data") or payload.get("rows") or []
    elif isinstance(payload, list):
        rows = payload
    else:
        raise TgjuError("پاسخ دریافتی از TGJU قابل خواندن نیست.")
    if not isinstance(rows, list):
        raise TgjuError("فهرست داده‌ها در پاسخ TGJU یافت نشد.")

    divisor = symbol.divisor
    by_date: dict[str, PricePoint] = {}

    for row in rows:
        try:
            cells = _row_values(row)
        except TgjuError:
            continue

        jalali = next((d for d in (_normalise_jalali(c) for c in reversed(cells)) if d), None)
        if not jalali:
            continue
        gregorian = next((d for d in (_normalise_gregorian(c) for c in reversed(cells)) if d), "")

        numbers = [_as_number(c, divisor) for c in cells[:4]]
        while len(numbers) < 4:
            numbers.append(None)
        open_, low, high, close = numbers[:4]

        # Guard against a shifted table: low must not exceed high.
        if low is not None and high is not None and low > high:
            low, high = high, low

        if close is None and high is not None and low is not None:
            close = (high + low) / 2
        if low is None and close is not None:
            low = close
        if high is None and close is not None:
            high = close
        if close is None:
            continue

        by_date[jalali] = PricePoint(jalali, gregorian, open_, low, high, close)

    if not by_date:
        raise TgjuError(
            f"هیچ رکورد معتبری برای نماد «{symbol.key}» در پاسخ TGJU پیدا نشد."
        )
    return sorted(by_date.values(), key=lambda p: p.date)


def _request(url: str, symbol_key: str, timeout: float) -> Any:
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": _USER_AGENT,
            "X-Requested-With": "XMLHttpRequest",
            "Accept": "application/json, text/javascript, */*; q=0.01",
            "Accept-Language": "fa,en;q=0.8",
            "Referer": f"https://www.tgju.org/profile/{symbol_key}/history",
        },
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        raw = response.read()
    try:
        return json.loads(raw.decode("utf-8", errors="replace"))
    except json.JSONDecodeError as exc:
        raise TgjuError("پاسخ TGJU یک JSON معتبر نبود (احتمالاً صفحه خطا).") from exc


def fetch_history(
    symbol: Symbol,
    *,
    length: int = 5000,
    timeout: float = 30.0,
    retries: int = 3,
    backoff: float = 1.5,
) -> list[PricePoint]:
    """Download the full price history for ``symbol``.

    Retries transient network failures with an exponential backoff and raises
    :class:`TgjuError` with a Persian message when it finally gives up.
    """
    url = API_URL.format(symbol=urllib.parse.quote(symbol.key, safe="-_"), length=int(length))
    last_error: Exception | None = None

    for attempt in range(max(1, retries)):
        try:
            return parse_rows(_request(url, symbol.key, timeout), symbol)
        except urllib.error.HTTPError as exc:
            last_error = exc
            if exc.code == 404:
                raise TgjuError(
                    f"نماد «{symbol.key}» در TGJU وجود ندارد (خطای ۴۰۴)."
                ) from exc
            if exc.code < 500 and exc.code != 429:
                raise TgjuError(
                    f"دریافت «{symbol.name}» با خطای {exc.code} از TGJU روبه‌رو شد."
                ) from exc
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            last_error = exc
        except TgjuError:
            raise

        if attempt < retries - 1:
            time.sleep(backoff * (2 ** attempt))

    raise TgjuError(
        f"اتصال به TGJU برای «{symbol.name}» برقرار نشد. "
        f"اتصال اینترنت را بررسی کنید. ({type(last_error).__name__})"
    )


def latest(points: Sequence[PricePoint]) -> PricePoint | None:
    return points[-1] if points else None
