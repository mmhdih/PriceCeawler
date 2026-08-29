"""Jalali (Shamsi) calendar helpers.

Implemented in pure Python so the packaged executable does not need any
calendar dependency. The conversion algorithm is the standard one used by
JalaliJSCalendar / ``jdatetime`` and is exact for Jalali years 1..3177.
"""

from __future__ import annotations

import datetime as _dt
import re
from typing import Iterator

__all__ = [
    "JalaliDate",
    "gregorian_to_jalali",
    "jalali_to_gregorian",
    "is_leap_year",
    "days_in_month",
    "MONTH_NAMES",
    "WEEKDAY_NAMES",
    "to_english_digits",
    "format_jalali",
]

MONTH_NAMES = (
    "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
    "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند",
)

# Ordered so that index == JalaliDate.weekday() (0 = Saturday).
WEEKDAY_NAMES = (
    "شنبه", "یک‌شنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنج‌شنبه", "جمعه",
)

_PERSIAN_DIGITS = "۰۱۲۳۴۵۶۷۸۹"
_ARABIC_DIGITS = "٠١٢٣٤٥٦٧٨٩"
_DIGIT_MAP = {ord(c): str(i) for i, c in enumerate(_PERSIAN_DIGITS)}
_DIGIT_MAP.update({ord(c): str(i) for i, c in enumerate(_ARABIC_DIGITS)})

_G_DAYS_IN_MONTH = (31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)
_J_DAYS_IN_MONTH = (31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29)

_DATE_RE = re.compile(r"^\s*(\d{3,4})\s*[/\-.]\s*(\d{1,2})\s*[/\-.]\s*(\d{1,2})\s*$")


def to_english_digits(value: str) -> str:
    """Normalise Persian/Arabic-Indic digits to ASCII digits."""
    return str(value).translate(_DIGIT_MAP)


def is_leap_year(jy: int) -> bool:
    """Return True when the given Jalali year has 366 days."""
    return _jalali_leap_offset(jy)[0] == 0


def days_in_month(jy: int, jm: int) -> int:
    if not 1 <= jm <= 12:
        raise ValueError(f"ماه نامعتبر است: {jm}")
    if jm == 12 and is_leap_year(jy):
        return 30
    return _J_DAYS_IN_MONTH[jm - 1]


def _jalali_leap_offset(jy: int) -> tuple[int, int, int]:
    """Return ``(leap, gy, march)`` for the Jalali year ``jy``.

    ``leap`` is 0 when ``jy`` is a leap year, ``gy`` the matching Gregorian
    year and ``march`` the Gregorian day of March that holds Farvardin 1.
    """
    breaks = (
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060,
        2097, 2192, 2262, 2324, 2394, 2456, 3178,
    )
    gy = jy + 621
    leap_j = -14
    jp = breaks[0]
    if jy < jp or jy >= breaks[-1]:
        raise ValueError(f"سال شمسی خارج از محدوده پشتیبانی است: {jy}")

    jump = 0
    for jm in breaks[1:]:
        jump = jm - jp
        if jy < jm:
            break
        leap_j += jump // 33 * 8 + (jump % 33) // 4
        jp = jm

    n = jy - jp
    leap_j += n // 33 * 8 + ((n % 33) + 3) // 4
    if jump % 33 == 4 and jump - n == 4:
        leap_j += 1

    leap_g = (gy // 4) - ((gy // 100 + 1) * 3 // 4) - 150
    march = 20 + leap_j - leap_g

    if jump - n < 6:
        n = n - jump + (jump + 4) // 33 * 33
    leap = ((n + 1) % 33 - 1) % 4
    if leap == -1:
        leap = 4
    return leap, gy, march


def jalali_to_gregorian(jy: int, jm: int, jd: int) -> tuple[int, int, int]:
    """Convert a Jalali date to a Gregorian ``(year, month, day)`` tuple."""
    _, gy, march = _jalali_leap_offset(jy)
    ordinal = _dt.date(gy, 3, march).toordinal()
    if jm < 7:
        ordinal += (jm - 1) * 31 + jd - 1
    else:
        ordinal += 6 * 31 + (jm - 7) * 30 + jd - 1
    g = _dt.date.fromordinal(ordinal)
    return g.year, g.month, g.day


def gregorian_to_jalali(gy: int, gm: int, gd: int) -> tuple[int, int, int]:
    """Convert a Gregorian date to a Jalali ``(year, month, day)`` tuple."""
    ordinal = _dt.date(gy, gm, gd).toordinal()
    jy = gy - 621
    # Farvardin 1 of `jy` may still be ahead of this date early in January.
    _, _, march = _jalali_leap_offset(jy)
    if ordinal < _dt.date(gy, 3, march).toordinal():
        jy -= 1
        _, ny, march = _jalali_leap_offset(jy)
        start = _dt.date(ny, 3, march).toordinal()
    else:
        start = _dt.date(gy, 3, march).toordinal()

    days = ordinal - start
    if days < 186:
        jm = 1 + days // 31
        jd = 1 + days % 31
    else:
        days -= 186
        jm = 7 + days // 30
        jd = 1 + days % 30
    return jy, jm, jd


class JalaliDate:
    """An immutable Jalali calendar date with just enough arithmetic."""

    __slots__ = ("year", "month", "day", "_ordinal")

    def __init__(self, year: int, month: int, day: int) -> None:
        year, month, day = int(year), int(month), int(day)
        if not 1 <= month <= 12:
            raise ValueError(f"ماه باید بین ۱ تا ۱۲ باشد (دریافت شد: {month})")
        limit = days_in_month(year, month)
        if not 1 <= day <= limit:
            raise ValueError(
                f"روز {day} برای {MONTH_NAMES[month - 1]} {year} نامعتبر است "
                f"(حداکثر {limit})"
            )
        object.__setattr__(self, "year", year)
        object.__setattr__(self, "month", month)
        object.__setattr__(self, "day", day)
        object.__setattr__(self, "_ordinal", _dt.date(*jalali_to_gregorian(year, month, day)).toordinal())

    # -- constructors ----------------------------------------------------
    @classmethod
    def today(cls) -> "JalaliDate":
        return cls.from_gregorian(_dt.date.today())

    @classmethod
    def from_gregorian(cls, date: _dt.date) -> "JalaliDate":
        return cls(*gregorian_to_jalali(date.year, date.month, date.day))

    @classmethod
    def fromordinal(cls, ordinal: int) -> "JalaliDate":
        return cls.from_gregorian(_dt.date.fromordinal(ordinal))

    @classmethod
    def parse(cls, text: str) -> "JalaliDate":
        """Parse ``YYYY/MM/DD`` (also accepting ``-``/``.`` and Persian digits)."""
        match = _DATE_RE.match(to_english_digits(str(text)))
        if not match:
            raise ValueError(
                f"قالب تاریخ نامعتبر است: «{text}» — قالب درست: ۱۴۰۴/۰۱/۰۱"
            )
        return cls(int(match.group(1)), int(match.group(2)), int(match.group(3)))

    # -- conversions -----------------------------------------------------
    def to_gregorian(self) -> _dt.date:
        return _dt.date.fromordinal(self._ordinal)

    def toordinal(self) -> int:
        return self._ordinal

    def weekday(self) -> int:
        """0 = Saturday ... 6 = Friday."""
        return (self.to_gregorian().weekday() + 2) % 7

    @property
    def month_name(self) -> str:
        return MONTH_NAMES[self.month - 1]

    @property
    def weekday_name(self) -> str:
        return WEEKDAY_NAMES[self.weekday()]

    def is_weekend(self) -> bool:
        return self.weekday() == 6  # جمعه

    # -- arithmetic ------------------------------------------------------
    def add_days(self, days: int) -> "JalaliDate":
        return JalaliDate.fromordinal(self._ordinal + days)

    def replace(self, year: int | None = None, month: int | None = None, day: int | None = None) -> "JalaliDate":
        return JalaliDate(
            self.year if year is None else year,
            self.month if month is None else month,
            self.day if day is None else day,
        )

    def __sub__(self, other: "JalaliDate") -> int:
        if not isinstance(other, JalaliDate):
            return NotImplemented
        return self._ordinal - other._ordinal

    # -- protocol --------------------------------------------------------
    def __str__(self) -> str:
        return f"{self.year:04d}/{self.month:02d}/{self.day:02d}"

    def __repr__(self) -> str:
        return f"JalaliDate({self.year}, {self.month}, {self.day})"

    def __hash__(self) -> int:
        return hash(self._ordinal)

    def __eq__(self, other: object) -> bool:
        return isinstance(other, JalaliDate) and self._ordinal == other._ordinal

    def __lt__(self, other: "JalaliDate") -> bool:
        return self._ordinal < other._ordinal

    def __le__(self, other: "JalaliDate") -> bool:
        return self._ordinal <= other._ordinal

    def __gt__(self, other: "JalaliDate") -> bool:
        return self._ordinal > other._ordinal

    def __ge__(self, other: "JalaliDate") -> bool:
        return self._ordinal >= other._ordinal

    def __setattr__(self, *_args: object) -> None:  # pragma: no cover - guard
        raise AttributeError("JalaliDate تغییرناپذیر است")


def date_range(start: JalaliDate, end: JalaliDate) -> Iterator[JalaliDate]:
    """Yield every Jalali date from ``start`` to ``end`` inclusive."""
    for ordinal in range(start.toordinal(), end.toordinal() + 1):
        yield JalaliDate.fromordinal(ordinal)


def format_jalali(date: JalaliDate, long: bool = False) -> str:
    """Format a date as ``1404/05/12`` or ``۱۲ مرداد ۱۴۰۴`` when ``long``."""
    if not long:
        return str(date)
    return f"{date.day} {date.month_name} {date.year}"
