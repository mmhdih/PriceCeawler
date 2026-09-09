import os
import tempfile
import time
import unittest
from datetime import datetime

os.environ.setdefault("PRICECEAWLER_DATA_DIR", tempfile.mkdtemp(prefix="pc-crawler-"))

from priceceawler import crawler as crawler_module  # noqa: E402
from priceceawler import intraday  # noqa: E402
from priceceawler.crawler import Crawler  # noqa: E402
from priceceawler.jalali import JalaliDate  # noqa: E402
from priceceawler.storage import Settings  # noqa: E402
from priceceawler.tgju import PricePoint, TgjuError  # noqa: E402


def points(count=5):
    today = JalaliDate.today()
    return [
        PricePoint(str(today.add_days(-offset)), "", None, 100, 120, 110)
        for offset in range(count - 1, -1, -1)
    ]


class TestResolve(unittest.TestCase):
    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))

    def test_duplicates_and_blanks_are_dropped_without_shifting(self):
        resolved = self.crawler.resolve(["geram18", "geram18", "  ", "sekee", "unknown_key"])
        self.assertEqual([s.key for s in resolved], ["geram18", "sekee", "unknown_key"])
        self.assertEqual(resolved[0].name, "طلای ۱۸ عیار")
        self.assertTrue(resolved[2].custom)

    def test_user_defined_custom_symbol_wins_over_the_bare_fallback(self):
        self.crawler.settings.update(
            {"custom_symbols": [{"key": "my_gold", "name": "طلای من", "currency": "USD"}]}
        )
        resolved = self.crawler.resolve(["my_gold"])
        self.assertEqual(resolved[0].name, "طلای من")
        self.assertEqual(resolved[0].divisor, 1.0)


class TestKnownSymbols(unittest.TestCase):
    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))

    def test_a_disabled_builtin_symbol_is_dropped(self):
        self.crawler.settings.update({"disabled_symbols": ["geram18"]})
        keys = {s.key for s in self.crawler.known_symbols()}
        self.assertNotIn("geram18", keys)
        self.assertIn("sekee", keys)

    def test_a_custom_symbol_keeps_its_group_and_decimals(self):
        self.crawler.settings.update(
            {
                "custom_symbols": [
                    {"key": "my_gold", "name": "طلای من", "group": "گروه تستی", "currency": "USD", "decimals": 2}
                ]
            }
        )
        by_key = {s.key: s for s in self.crawler.known_symbols()}
        self.assertEqual(by_key["my_gold"].group, "گروه تستی")
        self.assertEqual(by_key["my_gold"].decimals, 2)

    def test_disabling_then_re_adding_the_same_key_as_custom_overrides_it(self):
        self.crawler.settings.update(
            {
                "disabled_symbols": ["geram18"],
                "custom_symbols": [{"key": "geram18", "name": "طلای دلخواه من", "currency": "IRR"}],
            }
        )
        by_key = {s.key: s for s in self.crawler.known_symbols()}
        self.assertEqual(by_key["geram18"].name, "طلای دلخواه من")
        self.assertTrue(by_key["geram18"].custom)


class TestBuild(unittest.TestCase):
    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        self.calls = []

    def stub(self, result):
        def points_for(symbol, force=False):
            self.calls.append((symbol.key, force))
            if isinstance(result, Exception):
                raise result
            return result, False
        self.crawler.points_for = points_for

    def test_errors_are_collected_per_symbol(self):
        def points_for(symbol, force=False):
            if symbol.key == "sekee":
                raise TgjuError("خطای آزمایشی")
            return points(), False
        self.crawler.points_for = points_for

        today = JalaliDate.today()
        result = self.crawler.build(["geram18", "sekee"], today.add_days(-2), today)
        self.assertEqual([s.symbol.key for s in result.series], ["geram18"])
        self.assertEqual(result.errors[0]["symbol"], "sekee")

    def test_series_keep_the_requested_order(self):
        self.stub(points())
        today = JalaliDate.today()
        result = self.crawler.build(["price_dollar_rl", "geram18"], today.add_days(-2), today)
        self.assertEqual([s.symbol.key for s in result.series], ["price_dollar_rl", "geram18"])

    def test_build_feeds_the_archive(self):
        self.stub(points())
        today = JalaliDate.today()
        self.crawler.build(["geram18"], today.add_days(-2), today)
        self.assertEqual(len(self.crawler.archive.load("geram18")), 5)


class TestBuildAt(unittest.TestCase):
    """Daily goes to TGJU; the intraday resolutions read our own samples."""

    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        self.today = JalaliDate.today()
        self.calls = []

        def points_for(symbol, force=False):
            self.calls.append(symbol.key)
            return points(), False
        self.crawler.points_for = points_for

    def test_daily_resolution_delegates_to_the_tgju_build(self):
        result = self.crawler.build_at(
            ["geram18"], self.today.add_days(-2), self.today, resolution="daily"
        )
        self.assertEqual(self.calls, ["geram18"])
        self.assertEqual([s.symbol.key for s in result.series], ["geram18"])

    def test_an_unknown_resolution_is_treated_as_daily(self):
        self.crawler.build_at(["geram18"], self.today, self.today, resolution="7m")
        self.assertEqual(self.calls, ["geram18"])

    def test_intraday_never_touches_tgju(self):
        self.crawler.build_at(["geram18"], self.today, self.today, resolution="10m")
        self.assertEqual(self.calls, [])

    def test_intraday_without_samples_reports_a_clear_error(self):
        result = self.crawler.build_at(
            ["never_sampled_key"], self.today, self.today, resolution="1h"
        )
        self.assertEqual(result.series, [])
        self.assertEqual(result.errors[0]["symbol"], "never_sampled_key")
        self.assertIn("ثبت نشده", result.errors[0]["message"])

    def test_intraday_builds_rows_from_recorded_samples(self):
        symbol = self.crawler.resolve(["geram18"])[0]
        gregorian = self.today.to_gregorian()
        base = int(
            datetime(gregorian.year, gregorian.month, gregorian.day, 10, 0,
                     tzinfo=intraday.TEHRAN).timestamp()
        )
        intraday.record(symbol.key, 7_000_000, base)
        intraday.record(symbol.key, 7_150_000, base + 900)

        result = self.crawler.build_at(
            ["geram18"], self.today, self.today, resolution="10m"
        )
        self.assertEqual(self.calls, [])
        rows = result.series[0].rows
        self.assertEqual([row["time"] for row in rows], ["10:00", "10:10"])
        self.assertEqual(result.series[0].stats["last"], 7_150_000)


class TestSampleIntraday(unittest.TestCase):
    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        # A key per test: two tests recording in the same second would
        # otherwise hit record()'s duplicate-timestamp guard.
        self.key = f"sample_{self.id().rsplit('.', 1)[-1]}"

    def stub_points(self, *, from_cache=False, error=None, close=7_300_000):
        def points_for(symbol, force=False):
            if error is not None:
                raise error
            return [PricePoint(str(JalaliDate.today()), "", None, 1, 2, close)], from_cache
        self.crawler.points_for = points_for

    def latest(self):
        now = int(time.time())
        return intraday.load_samples(self.key, now - 120, now + 120)

    def test_a_fresh_price_is_recorded(self):
        self.stub_points()
        result = self.crawler.sample_intraday([self.key])
        self.assertEqual(result["recorded"], [self.key])
        self.assertEqual(list(self.latest().values()), [7_300_000.0])

    def test_a_cached_price_is_never_recorded_as_a_fresh_observation(self):
        """points_for() falls back to stale data when the network is down;
        storing it under a fresh timestamp would invent an observation."""
        self.stub_points(from_cache=True)
        result = self.crawler.sample_intraday([self.key])
        self.assertEqual(result["recorded"], [])
        self.assertEqual(self.latest(), {})
        self.assertIn("ذخیره نشد", result["errors"][0]["message"])

    def test_a_fetch_error_is_reported_and_records_nothing(self):
        self.stub_points(error=TgjuError("شبکه در دسترس نیست."))
        result = self.crawler.sample_intraday([self.key])
        self.assertEqual(result["recorded"], [])
        self.assertEqual(result["errors"][0]["symbol"], self.key)
        self.assertEqual(self.latest(), {})

    def test_sampling_stamps_the_time_and_returns_a_summary(self):
        self.stub_points()
        before = int(time.time())
        result = self.crawler.sample_intraday([self.key])
        self.assertGreaterEqual(self.crawler.settings.get("last_sample"), before)
        self.assertIn("intraday", result)
        self.assertIn("pruned", result)

    def test_an_empty_key_list_falls_back_to_the_watched_symbols(self):
        self.crawler.settings.update({"symbols": [self.key]})
        self.stub_points()
        self.assertEqual(self.crawler.sample_intraday()["recorded"], [self.key])


class TestCaching(unittest.TestCase):
    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        self.symbol = self.crawler.resolve(["geram18"])[0]
        self.fetches = 0
        # The disk cache is shared between Crawler instances - start each test clean.
        for stale in self.crawler.cache_dir.glob("*.json"):
            stale.unlink()

    def patch_fetch(self, failing=False):
        def fake_fetch(symbol, **kwargs):
            self.fetches += 1
            if failing:
                raise TgjuError("شبکه در دسترس نیست.")
            return points()
        crawler_module.fetch_history = fake_fetch

    def tearDown(self):
        crawler_module.fetch_history = _ORIGINAL_FETCH

    def test_second_call_is_served_from_cache(self):
        self.patch_fetch()
        first, cached_first = self.crawler.points_for(self.symbol)
        second, cached_second = self.crawler.points_for(self.symbol)
        self.assertEqual(self.fetches, 1)
        self.assertFalse(cached_first)
        self.assertTrue(cached_second)
        self.assertEqual(len(first), len(second))

    def test_force_bypasses_the_cache(self):
        self.patch_fetch()
        self.crawler.points_for(self.symbol)
        self.crawler.points_for(self.symbol, force=True)
        self.assertEqual(self.fetches, 2)

    def test_stale_cache_is_used_when_the_network_fails(self):
        self.patch_fetch()
        self.crawler.points_for(self.symbol)
        self.crawler._memory[self.symbol.key] = (time.time() - 10_000, self.crawler._memory[self.symbol.key][1])

        self.patch_fetch(failing=True)
        stale, cached = self.crawler.points_for(self.symbol)
        self.assertTrue(cached)
        self.assertTrue(stale)

    def test_failure_without_a_cache_propagates(self):
        self.patch_fetch(failing=True)
        with self.assertRaises(TgjuError):
            self.crawler.points_for(self.crawler.resolve(["never_fetched_symbol"])[0])


_ORIGINAL_FETCH = crawler_module.fetch_history

if __name__ == "__main__":
    unittest.main()
