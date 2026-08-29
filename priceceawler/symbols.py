"""Catalogue of TGJU indicators the app can crawl."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Iterable

__all__ = ["Symbol", "CATALOG", "GROUPS", "get_symbol", "resolve", "custom_symbol"]

# Currency codes used for display and for deciding whether the raw TGJU value
# (quoted in Rial for domestic markets) must be divided by 10.
IRR = "IRR"   # ریال -> نمایش به تومان
USD = "USD"   # دلار جهانی
UNIT_LABELS = {IRR: "تومان", USD: "دلار"}


@dataclass(frozen=True)
class Symbol:
    """A single TGJU indicator."""

    key: str
    name: str
    group: str
    currency: str = IRR
    decimals: int = 0
    custom: bool = False
    aliases: tuple[str, ...] = field(default=())

    @property
    def divisor(self) -> float:
        """Raw TGJU values for domestic markets are Rial; we display Toman."""
        return 10.0 if self.currency == IRR else 1.0

    @property
    def unit_label(self) -> str:
        return UNIT_LABELS.get(self.currency, self.currency)

    def to_dict(self) -> dict:
        return {
            "key": self.key,
            "name": self.name,
            "group": self.group,
            "currency": self.currency,
            "decimals": self.decimals,
            "unit": self.unit_label,
            "custom": self.custom,
        }


_DEFS: tuple[Symbol, ...] = (
    # --- طلا و نقره -----------------------------------------------------
    Symbol("geram18", "طلای ۱۸ عیار", "طلا و نقره"),
    Symbol("geram24", "طلای ۲۴ عیار", "طلا و نقره"),
    Symbol("mesghal", "مثقال طلا", "طلا و نقره"),
    Symbol("gold_17", "طلای ۱۷ عیار", "طلا و نقره"),
    Symbol("ons", "انس جهانی طلا", "طلا و نقره", currency=USD, decimals=2),
    Symbol("silver", "انس جهانی نقره", "طلا و نقره", currency=USD, decimals=2),
    Symbol("silver_925", "نقره ۹۲۵", "طلا و نقره"),
    Symbol("platinum", "انس پلاتین", "طلا و نقره", currency=USD, decimals=2),
    # --- سکه -------------------------------------------------------------
    Symbol("sekee", "سکه امامی", "سکه"),
    Symbol("sekeb", "سکه بهار آزادی", "سکه"),
    Symbol("nim", "نیم سکه", "سکه"),
    Symbol("rob", "ربع سکه", "سکه"),
    Symbol("gerami", "سکه گرمی", "سکه"),
    Symbol("retail_sekee", "سکه امامی (خرده‌فروشی)", "سکه"),
    # --- ارز --------------------------------------------------------------
    Symbol("price_dollar_rl", "دلار آمریکا", "ارز"),
    Symbol("price_eur", "یورو", "ارز"),
    Symbol("price_gbp", "پوند انگلیس", "ارز"),
    Symbol("price_aed", "درهم امارات", "ارز"),
    Symbol("price_try", "لیر ترکیه", "ارز"),
    Symbol("price_cad", "دلار کانادا", "ارز"),
    Symbol("price_aud", "دلار استرالیا", "ارز"),
    Symbol("price_chf", "فرانک سوئیس", "ارز"),
    Symbol("price_cny", "یوان چین", "ارز"),
    Symbol("price_rub", "روبل روسیه", "ارز"),
    Symbol("price_jpy", "ین ژاپن", "ارز"),
    Symbol("price_iqd", "دینار عراق", "ارز"),
    # --- رمزارز ----------------------------------------------------------
    Symbol("crypto-bitcoin", "بیت‌کوین", "رمزارز", currency=USD, decimals=2),
    Symbol("crypto-ethereum", "اتریوم", "رمزارز", currency=USD, decimals=2),
    Symbol("crypto-tether", "تتر", "رمزارز", currency=USD, decimals=4),
    # --- نفت و کالا -------------------------------------------------------
    Symbol("oil_brent", "نفت برنت", "نفت و کالا", currency=USD, decimals=2),
    Symbol("oil_wti", "نفت وست تگزاس", "نفت و کالا", currency=USD, decimals=2),
    Symbol("copper", "مس", "نفت و کالا", currency=USD, decimals=4),
)

CATALOG: dict[str, Symbol] = {s.key: s for s in _DEFS}

GROUPS: tuple[str, ...] = tuple(dict.fromkeys(s.group for s in _DEFS))

DEFAULT_SELECTION: tuple[str, ...] = ("geram18", "sekee", "price_dollar_rl")


def custom_symbol(key: str, name: str | None = None, currency: str = IRR) -> Symbol:
    """Build a Symbol for a TGJU key that is not in the built-in catalogue."""
    key = key.strip()
    if not key:
        raise ValueError("شناسه نماد نمی‌تواند خالی باشد.")
    return Symbol(key, (name or key).strip(), "نمادهای دلخواه", currency=currency, custom=True)


def get_symbol(key: str) -> Symbol | None:
    return CATALOG.get(key.strip())


def resolve(keys: Iterable[str]) -> list[Symbol]:
    """Map keys to Symbols, falling back to a custom symbol for unknown keys."""
    resolved: list[Symbol] = []
    seen: set[str] = set()
    for key in keys:
        key = str(key).strip()
        if not key or key in seen:
            continue
        seen.add(key)
        resolved.append(CATALOG.get(key) or custom_symbol(key))
    return resolved
