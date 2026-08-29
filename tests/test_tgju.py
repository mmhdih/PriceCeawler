import unittest

from priceceawler.symbols import CATALOG, custom_symbol
from priceceawler.tgju import TgjuError, parse_rows

GOLD = CATALOG["geram18"]
ONS = CATALOG["ons"]


def payload(rows):
    return {"data": rows}


class TestParseRows(unittest.TestCase):
    def test_standard_row_converts_rial_to_toman(self):
        points = parse_rows(
            payload([["7,500,000", "7,480,000", "7,560,000", "7,540,000", "40,000", "0.53", "2025-08-20", "1404/05/29"]]),
            GOLD,
        )
        self.assertEqual(len(points), 1)
        point = points[0]
        self.assertEqual(point.date, "1404/05/29")
        self.assertEqual(point.gregorian, "2025-08-20")
        self.assertEqual(point.low, 748_000)
        self.assertEqual(point.high, 756_000)
        self.assertEqual(point.close, 754_000)
        self.assertAlmostEqual(point.average, (748_000 + 756_000 + 754_000) / 3)

    def test_usd_symbols_are_not_divided(self):
        points = parse_rows(
            payload([["3,350.5", "3,340.1", "3,360.9", "3,355.2", "5", "0.1", "2025-08-20", "1404/05/29"]]),
            ONS,
        )
        self.assertAlmostEqual(points[0].close, 3355.2)

    def test_html_and_persian_digits(self):
        points = parse_rows(
            payload([["۷,۵۰۰,۰۰۰", "7,480,000", "7,560,000", "<span class='high'>7,540,000</span>",
                      "-", "-", "2025-08-20", "۱۴۰۴/۰۵/۲۹"]]),
            GOLD,
        )
        self.assertEqual(points[0].close, 754_000)

    def test_rows_are_sorted_and_deduplicated(self):
        points = parse_rows(
            payload([
                ["1", "100", "110", "105", "", "", "2025-08-21", "1404/05/30"],
                ["1", "100", "110", "105", "", "", "2025-08-20", "1404/05/29"],
                ["1", "200", "220", "210", "", "", "2025-08-21", "1404/05/30"],
            ]),
            GOLD,
        )
        self.assertEqual([p.date for p in points], ["1404/05/29", "1404/05/30"])
        self.assertEqual(points[-1].close, 21)  # the later duplicate wins

    def test_swapped_low_and_high_are_corrected(self):
        points = parse_rows(
            payload([["100", "9,999,999", "1,000,000", "1,200,000", "", "", "2025-08-20", "1404/05/29"]]),
            GOLD,
        )
        self.assertLessEqual(points[0].low, points[0].high)

    def test_invalid_dates_are_skipped(self):
        with self.assertRaises(TgjuError):
            parse_rows(payload([["1", "2", "3", "4", "", "", "", "1404/13/40"]]), GOLD)

    def test_dict_rows(self):
        points = parse_rows(
            payload([{"open": "1", "low": "100", "high": "110", "close": "105",
                      "change": "", "percent": "", "date_gregorian": "2025-08-20", "date": "1404/05/29"}]),
            GOLD,
        )
        self.assertEqual(points[0].close, 10.5)

    def test_empty_payload_raises_persian_error(self):
        with self.assertRaises(TgjuError) as ctx:
            parse_rows(payload([]), GOLD)
        self.assertIn("geram18", str(ctx.exception))

    def test_missing_close_is_recovered_from_low_and_high(self):
        points = parse_rows(
            payload([["-", "1,000,000", "1,200,000", "-", "", "", "2025-08-20", "1404/05/29"]]),
            GOLD,
        )
        self.assertEqual(points[0].close, 110_000)

    def test_custom_symbol_defaults_to_rial(self):
        symbol = custom_symbol("some_key", "نماد آزمایشی")
        points = parse_rows(payload([["1", "10", "20", "30", "", "", "2025-08-20", "1404/05/29"]]), symbol)
        self.assertEqual(points[0].close, 3.0)


if __name__ == "__main__":
    unittest.main()
