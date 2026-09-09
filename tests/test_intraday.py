import os
import tempfile
import unittest
from datetime import datetime

os.environ.setdefault("PRICECEAWLER_DATA_DIR", tempfile.mkdtemp(prefix="pc-intraday-"))

from priceceawler import intraday  # noqa: E402
from priceceawler.jalali import JalaliDate  # noqa: E402
from priceceawler.symbols import CATALOG, custom_symbol  # noqa: E402

GOLD = CATALOG["geram18"]
OUNCE = custom_symbol("ounce_test", "انس آزمایشی", "USD", None, 2)


def tehran(year, month, day, hour=0, minute=0, second=0):
    """A Tehran-local wall clock as a unix timestamp."""
    return int(datetime(year, month, day, hour, minute, second, tzinfo=intraday.TEHRAN).timestamp())


class TestResolutions(unittest.TestCase):
    def test_only_the_three_intraday_ids_are_intraday(self):
        self.assertTrue(intraday.is_intraday("10m"))
        self.assertTrue(intraday.is_intraday("1h"))
        self.assertTrue(intraday.is_intraday("tick"))
        self.assertFalse(intraday.is_intraday("daily"))
        self.assertFalse(intraday.is_intraday(None))

    def test_unknown_resolution_falls_back_to_daily(self):
        for value in ("", "  ", "5m", "junk", None, 7, {"id": "10m"}):
            self.assertEqual(intraday.normalise_resolution(value), "daily")

    def test_whitespace_is_tolerated(self):
        self.assertEqual(intraday.normalise_resolution("  1h  "), "1h")

    def test_catalogue_exposes_every_resolution_with_a_label(self):
        ids = [entry["id"] for entry in intraday.RESOLUTIONS]
        self.assertEqual(ids, ["daily", "10m", "1h", "tick"])
        for entry in intraday.RESOLUTIONS:
            self.assertTrue(entry["label"])
            self.assertEqual(entry["intraday"], entry["id"] != "daily")


class TestTehranClock(unittest.TestCase):
    def test_buckets_align_on_the_local_clock_not_utc(self):
        """Tehran is +03:30, so a UTC-aligned hour would start at :30 local."""
        ts = tehran(2025, 8, 19, 14, 47, 12)
        start = intraday.bucket_start(ts, "1h")
        self.assertEqual(intraday.local_time(start), "14:00")

    def test_ten_minute_buckets_floor_to_the_minute_mark(self):
        ts = tehran(2025, 8, 19, 9, 38, 59)
        self.assertEqual(intraday.local_time(intraday.bucket_start(ts, "10m")), "09:30")

    def test_samples_in_one_bucket_share_a_start(self):
        first = intraday.bucket_start(tehran(2025, 8, 19, 9, 30, 0), "10m")
        last = intraday.bucket_start(tehran(2025, 8, 19, 9, 39, 59), "10m")
        self.assertEqual(first, last)
        self.assertNotEqual(last, intraday.bucket_start(tehran(2025, 8, 19, 9, 40, 0), "10m"))

    def test_daily_resolution_has_no_bucket_width(self):
        ts = tehran(2025, 8, 19, 9, 38, 59)
        self.assertEqual(intraday.bucket_start(ts, "daily"), ts)

    def test_range_bounds_cover_the_whole_local_days(self):
        start = JalaliDate(1404, 5, 28)
        from_ts, to_ts = intraday.range_bounds(start, start)
        self.assertEqual(intraday.local_time(from_ts), "00:00")
        self.assertEqual(intraday.local_time(to_ts), "23:59")
        self.assertEqual(intraday.local_jalali(from_ts), start)
        self.assertEqual(intraday.local_jalali(to_ts), start)


class TestStorage(unittest.TestCase):
    def setUp(self):
        # A key of its own per test, so tests never see each other's samples.
        self.key = f"sym_{self.id().rsplit('.', 1)[-1]}"
        self.day = tehran(2025, 8, 19, 10, 0, 0)

    def test_records_and_reads_back_a_sample(self):
        self.assertTrue(intraday.record(self.key, 7_100_000, self.day))
        samples = intraday.load_samples(self.key, self.day - 60, self.day + 60)
        self.assertEqual(samples, {self.day: 7_100_000.0})

    def test_the_same_second_is_never_recorded_twice(self):
        self.assertTrue(intraday.record(self.key, 7_100_000, self.day))
        self.assertFalse(intraday.record(self.key, 7_200_000, self.day))
        self.assertEqual(len(intraday.load_samples(self.key, self.day - 60, self.day + 60)), 1)

    def test_unusable_prices_are_rejected(self):
        for price in (None, 0, -5, "", "abc", float("nan")):
            self.assertFalse(intraday.record(self.key, price, self.day))
        self.assertEqual(intraday.load_samples(self.key, self.day - 60, self.day + 60), {})

    def test_load_samples_spans_day_files_and_clips_the_range(self):
        inside_first = tehran(2025, 8, 19, 23, 50, 0)
        inside_next = tehran(2025, 8, 20, 0, 10, 0)
        outside = tehran(2025, 8, 21, 0, 10, 0)
        for ts, price in ((inside_first, 100), (inside_next, 110), (outside, 120)):
            self.assertTrue(intraday.record(self.key, price, ts))

        samples = intraday.load_samples(self.key, inside_first, inside_next)
        self.assertEqual(sorted(samples), [inside_first, inside_next])

    def test_a_symbol_key_with_path_characters_stays_inside_the_store(self):
        """Path separators are neutralised, so a crafted key cannot write out."""
        self.assertTrue(intraday.record("../../escape", 500, self.day))
        store = intraday.base_dir().resolve()
        for path in store.rglob("*.json"):
            self.assertEqual(path.resolve().parent.parent, store)
        # It still reads back through the same key, just from a contained name.
        self.assertEqual(
            intraday.load_samples("../../escape", self.day - 1, self.day + 1),
            {self.day: 500.0},
        )

    def test_prune_removes_only_files_past_retention(self):
        old = self.day - (intraday.RETENTION_DAYS + 2) * 86400
        intraday.record(self.key, 100, old)
        intraday.record(self.key, 200, self.day)

        removed = intraday.prune(now=self.day)
        self.assertGreaterEqual(removed, 1)
        self.assertEqual(intraday.load_samples(self.key, old - 60, old + 60), {})
        self.assertEqual(len(intraday.load_samples(self.key, self.day - 60, self.day + 60)), 1)

    def test_summary_counts_days_and_samples(self):
        intraday.record(self.key, 100, self.day)
        intraday.record(self.key, 110, self.day + 600)
        intraday.record(self.key, 120, self.day + 86400)

        row = next(r for r in intraday.summary() if r["key"] == self.key)
        self.assertEqual(row["days"], 2)
        self.assertEqual(row["samples"], 3)
        self.assertEqual(row["first"], "2025-08-19")
        self.assertEqual(row["last"], "2025-08-20")


class TestAggregate(unittest.TestCase):
    def setUp(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        # 09:00 → 7,000,000 then rising by 1,000 every minute for 25 minutes.
        self.samples = {base + minute * 60: 7_000_000 + minute * 1_000 for minute in range(26)}

    def test_no_samples_gives_no_rows(self):
        self.assertEqual(intraday.aggregate({}, "10m"), [])

    def test_a_daily_resolution_is_not_aggregated_here(self):
        self.assertEqual(intraday.aggregate(self.samples, "daily"), [])

    def test_ten_minute_buckets_carry_ohlc_and_a_sample_count(self):
        rows = intraday.aggregate(self.samples, "10m")
        self.assertEqual([row["time"] for row in rows], ["09:00", "09:10", "09:20"])

        first = rows[0]
        self.assertEqual(first["open"], 7_000_000)
        self.assertEqual(first["low"], 7_000_000)
        self.assertEqual(first["high"], 7_009_000)
        self.assertEqual(first["close"], 7_009_000)
        self.assertEqual(first["samples"], 10)
        # The mean of the ten observations, not (low+high+close)/3.
        self.assertEqual(first["average"], 7_004_500)
        self.assertEqual(rows[-1]["samples"], 6)  # 09:20..09:25

    def test_hourly_collapses_everything_into_one_row(self):
        rows = intraday.aggregate(self.samples, "1h")
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["time"], "09:00")
        self.assertEqual(rows[0]["samples"], 26)
        self.assertEqual(rows[0]["open"], 7_000_000)
        self.assertEqual(rows[0]["close"], 7_025_000)

    def test_empty_buckets_are_skipped_rather_than_carried_forward(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        rows = intraday.aggregate({base: 100, base + 3600: 120}, "10m")
        self.assertEqual([row["time"] for row in rows], ["09:00", "10:00"])

    def test_rows_carry_a_jalali_date_and_weekday(self):
        row = intraday.aggregate(self.samples, "1h")[0]
        self.assertEqual(row["date"], str(intraday.local_jalali(row["ts"])))
        self.assertTrue(row["weekday"])
        self.assertTrue(row["live"])

    def test_tick_keeps_only_the_samples_where_the_price_moved(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        samples = {
            base: 7_000_000,
            base + 60: 7_000_000,   # unchanged → dropped
            base + 120: 7_050_000,
            base + 180: 7_050_000,  # unchanged → dropped
            base + 240: 7_010_000,
        }
        rows = intraday.aggregate(samples, "tick")
        self.assertEqual([row["close"] for row in rows], [7_000_000, 7_050_000, 7_010_000])
        self.assertEqual([row["change"] for row in rows], [None, 50_000, -40_000])
        self.assertTrue(all(row["samples"] == 1 for row in rows))

    def test_tick_compares_prices_after_rounding_to_the_symbols_precision(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        samples = {base: 3300.001, base + 60: 3300.02, base + 120: 3300.9}
        # At 0 decimals the first two round to the same rial and collapse.
        self.assertEqual(len(intraday.aggregate(samples, "tick", 0)), 2)
        # At 2 decimals they are distinct observations.
        self.assertEqual(len(intraday.aggregate(samples, "tick", 2)), 3)

    def test_decimals_are_honoured_for_dollar_symbols(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        rows = intraday.aggregate({base: 3301.114, base + 60: 3302.226}, "10m", 2)
        self.assertEqual(rows[0]["open"], 3301.11)
        self.assertEqual(rows[0]["close"], 3302.23)
        self.assertEqual(rows[0]["average"], 3301.67)


class Bar:
    """Minimal stand-in for tgju_intraday.Candle."""

    def __init__(self, ts, open_, high, low, close):
        self.ts, self.open, self.high, self.low, self.close = ts, open_, high, low, close


class TestAggregateCandles(unittest.TestCase):
    """Bars carry their own extremes, so a bucket must not use closes alone."""

    def setUp(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        # Three 1-minute bars inside one 10-minute bucket. The high and low
        # happen *inside* bars, never at a close.
        self.bars = [
            Bar(base, 100, 140, 95, 110),
            Bar(base + 60, 110, 180, 90, 120),
            Bar(base + 120, 120, 130, 105, 115),
        ]

    def test_no_bars_gives_no_rows(self):
        self.assertEqual(intraday.aggregate_candles([], "10m"), [])

    def test_a_bucket_takes_the_extremes_of_the_bars_not_of_the_closes(self):
        row = intraday.aggregate_candles(self.bars, "10m")[0]
        self.assertEqual(row["open"], 100)    # first bar's open
        self.assertEqual(row["high"], 180)    # max of highs, not max of closes
        self.assertEqual(row["low"], 90)      # min of lows, not min of closes
        self.assertEqual(row["close"], 115)   # last bar's close
        self.assertEqual(row["samples"], 3)   # three source bars

    def test_the_average_is_the_mean_of_the_bar_closes(self):
        row = intraday.aggregate_candles(self.bars, "10m")[0]
        self.assertEqual(row["average"], round((110 + 120 + 115) / 3))

    def test_bars_are_bucketed_on_the_tehran_clock(self):
        base = tehran(2025, 8, 19, 9, 9, 0)
        rows = intraday.aggregate_candles(
            [Bar(base, 1, 1, 1, 1), Bar(base + 120, 2, 2, 2, 2)], "10m"
        )
        self.assertEqual([r["time"] for r in rows], ["09:00", "09:10"])

    def test_hourly_merges_every_bucket_of_the_hour(self):
        rows = intraday.aggregate_candles(self.bars, "1h")
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["time"], "09:00")
        self.assertEqual(rows[0]["samples"], 3)

    def test_out_of_order_bars_are_sorted_first(self):
        rows = intraday.aggregate_candles(list(reversed(self.bars)), "10m")
        self.assertEqual(rows[0]["open"], 100)
        self.assertEqual(rows[0]["close"], 115)

    def test_bars_missing_high_and_low_fall_back_to_their_close(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        row = intraday.aggregate_candles(
            [Bar(base, None, None, None, 250)], "10m"
        )[0]
        self.assertEqual((row["open"], row["high"], row["low"], row["close"]),
                         (250, 250, 250, 250))

    def test_a_bar_with_no_price_at_all_is_skipped(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        self.assertEqual(intraday.aggregate_candles([Bar(base, None, 1, 1, None)], "10m"), [])

    def test_tick_keeps_one_row_per_changed_close(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        bars = [
            Bar(base, 100, 100, 100, 100),
            Bar(base + 60, 100, 100, 100, 100),   # unchanged -> dropped
            Bar(base + 120, 100, 100, 100, 130),
        ]
        rows = intraday.aggregate_candles(bars, "tick")
        self.assertEqual([r["close"] for r in rows], [100, 130])
        self.assertEqual([r["change"] for r in rows], [None, 30])

    def test_an_unknown_resolution_yields_nothing(self):
        self.assertEqual(intraday.aggregate_candles(self.bars, "daily"), [])

    def test_decimals_are_respected(self):
        base = tehran(2025, 8, 19, 9, 0, 0)
        row = intraday.aggregate_candles(
            [Bar(base, 3301.111, 3302.888, 3300.222, 3301.555)], "10m", 2
        )[0]
        self.assertEqual(row["high"], 3302.89)
        self.assertEqual(row["low"], 3300.22)


class TestBuildRowsAndStats(unittest.TestCase):
    def setUp(self):
        self.symbol = custom_symbol("build_rows_test", "نماد آزمایشی", "IRR", None, 0)
        self.day = JalaliDate(1404, 5, 28)
        gregorian = self.day.to_gregorian()
        self.base = tehran(gregorian.year, gregorian.month, gregorian.day, 9, 0, 0)

    def test_no_recorded_samples_gives_no_rows(self):
        empty = custom_symbol("never_recorded", "خالی", "IRR", None, 0)
        self.assertEqual(intraday.build_rows(empty, self.day, self.day, "10m"), [])

    def test_build_rows_reads_the_recorded_day(self):
        intraday.record(self.symbol.key, 7_000_000, self.base)
        intraday.record(self.symbol.key, 7_100_000, self.base + 900)

        rows = intraday.build_rows(self.symbol, self.day, self.day, "10m")
        self.assertEqual([row["time"] for row in rows], ["09:00", "09:10"])
        self.assertEqual(rows[0]["date"], str(self.day))

    def test_samples_outside_the_requested_range_are_excluded(self):
        intraday.record(self.symbol.key, 7_000_000, self.base)
        yesterday = JalaliDate(1404, 5, 27)
        self.assertEqual(intraday.build_rows(self.symbol, yesterday, yesterday, "10m"), [])

    def test_stats_match_the_shape_build_series_produces(self):
        intraday.record(self.symbol.key, 7_000_000, self.base)
        intraday.record(self.symbol.key, 7_200_000, self.base + 1800)
        rows = intraday.build_rows(self.symbol, self.day, self.day, "10m")

        stats = intraday.stats(rows, self.symbol)
        self.assertEqual(stats["days"], len(rows))
        self.assertEqual(stats["trading_days"], len(rows))
        self.assertEqual(stats["first"], 7_000_000)
        self.assertEqual(stats["last"], 7_200_000)
        self.assertEqual(stats["change"], 200_000)
        self.assertAlmostEqual(stats["change_pct"], 2.86, places=2)
        self.assertEqual(stats["unit"], self.symbol.unit_label)
        self.assertEqual(
            set(stats),
            {"days", "trading_days", "first", "last", "min", "max", "mean",
             "change", "change_pct", "unit"},
        )

    def test_stats_of_no_rows_are_all_empty(self):
        stats = intraday.stats([], OUNCE)
        self.assertEqual(stats["days"], 0)
        for key in ("first", "last", "min", "max", "mean", "change", "change_pct"):
            self.assertIsNone(stats[key])


if __name__ == "__main__":
    unittest.main()
