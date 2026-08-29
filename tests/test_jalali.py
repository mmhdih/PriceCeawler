import datetime as dt
import unittest

from priceceawler.jalali import (
    JalaliDate,
    date_range,
    days_in_month,
    gregorian_to_jalali,
    is_leap_year,
    jalali_to_gregorian,
    to_english_digits,
)


class TestConversion(unittest.TestCase):
    KNOWN = [
        ((1404, 1, 1), (2025, 3, 21)),
        ((1403, 12, 30), (2025, 3, 20)),   # 1403 is a leap year
        ((1400, 1, 1), (2021, 3, 21)),
        ((1399, 12, 30), (2021, 3, 20)),
        ((1357, 11, 22), (1979, 2, 11)),
        ((1300, 1, 1), (1921, 3, 21)),
        ((1450, 6, 15), (2071, 9, 6)),
    ]

    def test_known_pairs(self):
        for jalali, gregorian in self.KNOWN:
            self.assertEqual(jalali_to_gregorian(*jalali), gregorian, msg=str(jalali))
            self.assertEqual(gregorian_to_jalali(*gregorian), jalali, msg=str(gregorian))

    def test_round_trip_over_60_years(self):
        start = dt.date(1990, 1, 1).toordinal()
        for offset in range(0, 60 * 365, 7):
            date = dt.date.fromordinal(start + offset)
            jalali = gregorian_to_jalali(date.year, date.month, date.day)
            self.assertEqual(jalali_to_gregorian(*jalali), (date.year, date.month, date.day))

    def test_leap_years(self):
        for year in (1399, 1403, 1408, 1412):
            self.assertTrue(is_leap_year(year), year)
            self.assertEqual(days_in_month(year, 12), 30)
        for year in (1400, 1401, 1402, 1404):
            self.assertFalse(is_leap_year(year), year)
            self.assertEqual(days_in_month(year, 12), 29)

    def test_year_length(self):
        for year in (1400, 1403, 1404):
            days = JalaliDate(year + 1, 1, 1) - JalaliDate(year, 1, 1)
            self.assertEqual(days, 366 if is_leap_year(year) else 365)


class TestJalaliDate(unittest.TestCase):
    def test_parse_variants(self):
        for text in ("1404/01/09", "1404-1-9", "۱۴۰۴/۰۱/۰۹", "1404.01.09", "  1404/1/9  "):
            self.assertEqual(str(JalaliDate.parse(text)), "1404/01/09", msg=text)

    def test_parse_rejects_garbage(self):
        for text in ("", "hello", "1404/13/01", "1404/01/32", "1404/1"):
            with self.assertRaises(ValueError, msg=text):
                JalaliDate.parse(text)

    def test_weekday_names(self):
        # 1404/01/01 == 2025-03-21, a Friday
        self.assertEqual(JalaliDate(1404, 1, 1).weekday_name, "جمعه")
        self.assertEqual(JalaliDate(1404, 1, 2).weekday_name, "شنبه")

    def test_arithmetic_and_ordering(self):
        day = JalaliDate(1404, 1, 1)
        self.assertEqual(str(day.add_days(-1)), "1403/12/30")
        self.assertEqual(str(day.add_days(365)), "1405/01/01")
        self.assertTrue(JalaliDate(1404, 1, 1) < JalaliDate(1404, 1, 2))
        self.assertEqual(JalaliDate(1404, 2, 1) - JalaliDate(1404, 1, 1), 31)

    def test_immutable(self):
        with self.assertRaises(AttributeError):
            JalaliDate(1404, 1, 1).year = 1405

    def test_date_range_inclusive(self):
        days = list(date_range(JalaliDate(1404, 1, 1), JalaliDate(1404, 1, 10)))
        self.assertEqual(len(days), 10)
        self.assertEqual(str(days[-1]), "1404/01/10")

    def test_digit_normalisation(self):
        self.assertEqual(to_english_digits("۱۲٬۳۴۵"), "12٬345")


if __name__ == "__main__":
    unittest.main()
