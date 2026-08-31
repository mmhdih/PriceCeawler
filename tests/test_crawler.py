import os
import tempfile
import time
import unittest

os.environ.setdefault("PRICECEAWLER_DATA_DIR", tempfile.mkdtemp(prefix="pc-crawler-"))

from priceceawler import crawler as crawler_module  # noqa: E402
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
