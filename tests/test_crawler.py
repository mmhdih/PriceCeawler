import os
import tempfile
import time
import unittest
from datetime import datetime

os.environ.setdefault("PRICECEAWLER_DATA_DIR", tempfile.mkdtemp(prefix="pc-crawler-"))

from priceceawler import crawler as crawler_module  # noqa: E402
from priceceawler import intraday, tgju_intraday  # noqa: E402
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
    """The recorded-samples path: daily goes to TGJU, intraday to our store.

    Extraction is forced off here so these cases exercise the local store;
    TestIntradaySource covers the extraction path and the choice between them.
    """

    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        self.crawler.settings.update({"intraday_source": "recorded"})
        self.today = JalaliDate.today()
        self.calls = []
        # The intraday store is shared by the whole suite, so each test needs
        # its own symbol key or another test's samples leak in.
        self.key = f"at_{self.id().rsplit('.', 1)[-1]}"

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
        self.crawler.build_at([self.key], self.today, self.today, resolution="10m")
        self.assertEqual(self.calls, [])

    def test_intraday_without_samples_says_to_turn_recording_on(self):
        """The message has to carry a next step, not just "no data"."""
        result = self.crawler.build_at(
            ["never_sampled_key"], self.today, self.today, resolution="1h"
        )
        self.assertEqual(result.series, [])
        self.assertEqual(result.errors[0]["symbol"], "never_sampled_key")
        message = result.errors[0]["message"]
        self.assertIn("ثبت خودکار", message)
        self.assertIn("قابل بازیابی نیست", message)

    def test_the_message_differs_once_recording_is_already_on(self):
        self.crawler.settings.update({"intraday_recording": True})
        result = self.crawler.build_at(
            ["never_sampled_key"], self.today, self.today, resolution="1h"
        )
        self.assertIn("ثبت خودکار روشن است", result.errors[0]["message"])

    def test_a_range_outside_the_recorded_days_names_the_recorded_window(self):
        symbol = self.crawler.resolve([self.key])[0]
        gregorian = self.today.to_gregorian()
        base = int(
            datetime(gregorian.year, gregorian.month, gregorian.day, 10, 0,
                     tzinfo=intraday.TEHRAN).timestamp()
        )
        intraday.record(symbol.key, 7_000_000, base)

        old_day = self.today.add_days(-200)
        result = self.crawler.build_at([self.key], old_day, old_day, resolution="1h")
        message = result.errors[0]["message"]
        persian = str(self.today).translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))
        self.assertIn(persian, message)
        self.assertIn("بیرون از این محدوده", message)

    def test_intraday_builds_rows_from_recorded_samples(self):
        symbol = self.crawler.resolve([self.key])[0]
        gregorian = self.today.to_gregorian()
        base = int(
            datetime(gregorian.year, gregorian.month, gregorian.day, 10, 0,
                     tzinfo=intraday.TEHRAN).timestamp()
        )
        intraday.record(symbol.key, 7_000_000, base)
        intraday.record(symbol.key, 7_150_000, base + 900)

        result = self.crawler.build_at(
            [self.key], self.today, self.today, resolution="10m"
        )
        self.assertEqual(self.calls, [])
        rows = result.series[0].rows
        self.assertEqual([row["time"] for row in rows], ["10:00", "10:10"])
        self.assertEqual(result.series[0].stats["last"], 7_150_000)


class TestIntradaySource(unittest.TestCase):
    """Extraction from TGJU is primary; recorded samples are the fallback."""

    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        self.today = JalaliDate.today()
        gregorian = self.today.to_gregorian()
        self.base = int(
            datetime(gregorian.year, gregorian.month, gregorian.day, 11, 0,
                     tzinfo=intraday.TEHRAN).timestamp()
        )
        self.fetches = []
        # The intraday store is shared by the whole suite, so each test needs
        # its own symbol key or another test's samples leak in.
        self.key = f"src_{self.id().rsplit('.', 1)[-1]}"
        self.crawler.points_for = lambda symbol, force=False: (points(), False)

    def stub_fetch(self, candles=None, error=None):
        """Replace the network layer; None candles means "nothing found"."""
        def fetch(symbol, from_ts, to_ts, *, endpoints=None, **kwargs):
            tried = list(endpoints or tgju_intraday.CANDIDATES)
            self.fetches.append((symbol.key, from_ts, to_ts, [e.name for e in tried]))
            if error is not None:
                raise error
            # Report the endpoint we were actually given, as the real
            # fetch_candles does - otherwise pinning cannot be tested.
            return (candles or []), tried[0], "1"
        crawler_module.tgju_intraday.fetch_candles = fetch

    def tearDown(self):
        crawler_module.tgju_intraday.fetch_candles = _ORIGINAL_FETCH_CANDLES

    def bars(self):
        Candle = tgju_intraday.Candle
        return [
            Candle(self.base, 7_000_000, 7_050_000, 6_990_000, 7_010_000),
            Candle(self.base + 60, 7_010_000, 7_090_000, 7_000_000, 7_080_000),
            Candle(self.base + 900, 7_080_000, 7_100_000, 7_070_000, 7_095_000),
        ]

    def test_rows_come_from_extraction_without_any_recording(self):
        self.stub_fetch(self.bars())
        result = self.crawler.build_at(
            [self.key], self.today, self.today, resolution="10m"
        )
        self.assertEqual(result.errors, [])
        rows = result.series[0].rows
        self.assertEqual([r["time"] for r in rows], ["11:00", "11:10"])
        # The first bucket merged two bars and kept their true extremes.
        self.assertEqual(rows[0]["samples"], 2)
        self.assertEqual(rows[0]["high"], 7_090_000)
        self.assertEqual(rows[0]["low"], 6_990_000)

    def test_extraction_is_asked_for_the_requested_range(self):
        self.stub_fetch(self.bars())
        self.crawler.build_at([self.key], self.today, self.today, resolution="1h")
        _, from_ts, to_ts, _ = self.fetches[0]
        self.assertEqual(intraday.local_time(from_ts), "00:00")
        self.assertEqual(intraday.local_time(to_ts), "23:59")

    def test_the_working_endpoint_is_pinned_so_later_calls_skip_probing(self):
        self.stub_fetch(self.bars())
        self.crawler.build_at([self.key], self.today, self.today, resolution="10m")
        self.assertEqual(
            self.crawler.settings.get("intraday_endpoint"),
            tgju_intraday.CANDIDATES[0].name,
        )
        # A second call passes only the pinned endpoint down.
        self.crawler.build_at([self.key], self.today, self.today, resolution="10m")
        self.assertEqual(self.fetches[-1][3], [tgju_intraday.CANDIDATES[0].name])

    def test_recorded_samples_are_used_when_extraction_finds_nothing(self):
        intraday.record(self.key, 6_500_000, self.base)
        intraday.record(self.key, 6_600_000, self.base + 120)
        self.stub_fetch([])
        result = self.crawler.build_at(
            [self.key], self.today, self.today, resolution="10m"
        )
        self.assertEqual(result.errors, [])
        self.assertEqual(result.series[0].rows[0]["close"], 6_600_000)

    def test_an_extraction_error_surfaces_when_there_is_no_fallback(self):
        self.stub_fetch(error=TgjuError("سرویس نمودار پاسخ نداد."))
        result = self.crawler.build_at(
            ["never_recorded_sym"], self.today, self.today, resolution="10m"
        )
        self.assertEqual(result.series, [])
        self.assertIn("سرویس نمودار پاسخ نداد", result.errors[0]["message"])

    def test_source_recorded_never_touches_the_network(self):
        intraday.record(self.key, 6_700_000, self.base)
        self.crawler.settings.update({"intraday_source": "recorded"})
        self.stub_fetch(self.bars())
        result = self.crawler.build_at(
            [self.key], self.today, self.today, resolution="10m"
        )
        self.assertEqual(self.fetches, [])
        self.assertEqual(result.series[0].rows[0]["close"], 6_700_000)

    def test_source_tgju_does_not_fall_back_to_recorded(self):
        """Forcing the source makes a failure visible instead of masked."""
        intraday.record(self.key, 6_800_000, self.base)
        self.crawler.settings.update({"intraday_source": "tgju"})
        self.stub_fetch([])
        result = self.crawler.build_at(
            [self.key], self.today, self.today, resolution="10m"
        )
        self.assertEqual(result.series, [])
        self.assertTrue(result.errors)

    def test_a_custom_url_template_is_passed_through_and_not_overwritten(self):
        template = "https://x/history?symbol={symbol}&resolution={resolution}&from={from}&to={to}"
        self.crawler.settings.update({"intraday_endpoint": template})
        self.stub_fetch(self.bars())
        self.crawler.build_at([self.key], self.today, self.today, resolution="10m")
        self.assertEqual(self.fetches[-1][3], ["custom"])
        # A user-supplied URL must survive a successful fetch.
        self.assertEqual(self.crawler.settings.get("intraday_endpoint"), template)

    def test_a_multi_day_range_merges_stored_days_with_todays_extraction(self):
        """The reported bug: a selected range came back holding only today.

        TGJU's page publishes today only, so a report that prefers extraction
        over the store loses every earlier day the sampler already harvested.
        """
        yesterday = self.today.add_days(-1)
        intraday.record(self.key, 6_400_000, self.base - 86_400)
        intraday.record(self.key, 6_450_000, self.base - 86_400 + 120)
        self.stub_fetch(self.bars())

        result = self.crawler.build_at(
            [self.key], yesterday, self.today, resolution="10m"
        )
        rows = result.series[0].rows
        self.assertEqual(
            sorted({row["date"] for row in rows}), [str(yesterday), str(self.today)]
        )
        self.assertEqual(rows[0]["close"], 6_450_000)   # stored yesterday
        self.assertEqual(rows[-1]["close"], 7_095_000)  # extracted today

    def test_live_data_wins_for_a_timestamp_that_is_also_stored(self):
        """A stored sample is a copy of the same source, so never override."""
        intraday.record(self.key, 1_111_111, self.base)
        self.stub_fetch(self.bars()[:1])
        result = self.crawler.build_at(
            [self.key], self.today, self.today, resolution="10m"
        )
        rows = result.series[0].rows
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["samples"], 1)
        self.assertEqual(rows[0]["close"], 7_010_000)

    def test_daily_still_bypasses_the_intraday_path_entirely(self):
        self.stub_fetch(self.bars())
        result = self.crawler.build_at(
            [self.key], self.today, self.today, resolution="daily"
        )
        self.assertEqual(self.fetches, [])
        self.assertEqual(result.resolution, "daily")
        self.assertTrue(result.series)


class TestSampleIntraday(unittest.TestCase):
    """The fallback path: recording the single current price."""

    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        # A key per test: two tests recording in the same second would
        # otherwise hit record()'s duplicate-timestamp guard.
        self.key = f"sample_{self.id().rsplit('.', 1)[-1]}"
        # These tests are about the single-price fallback, so switch the chart
        # source off rather than letting them reach the real network.
        self.crawler.settings.update({"intraday_source": "recorded"})

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


class TestHarvest(unittest.TestCase):
    """Sampling stores TGJU's whole published series, not just this instant.

    One request captures every 10-minute point of the day, so a missed run
    leaves no gap and the stored history is TGJU's own data rather than an
    artefact of when we happened to sample.
    """

    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))
        self.key = f"harvest_{self.id().rsplit('.', 1)[-1]}"
        self.base = int(time.time()) - 3600
        self.asked = []
        self.points_asked = []

        def points_for(symbol, force=False):
            self.points_asked.append(symbol.key)
            return [PricePoint(str(JalaliDate.today()), "", None, 1, 2, 9_999_999)], False
        self.crawler.points_for = points_for

    def tearDown(self):
        crawler_module.tgju_intraday.fetch_candles = _ORIGINAL_FETCH_CANDLES

    def stub_fetch(self, candles=None, error=None):
        def fetch(symbol, from_ts, to_ts, *, endpoints=None, **kwargs):
            self.asked.append((symbol.key, from_ts, to_ts))
            if error is not None:
                raise error
            return (candles or []), tgju_intraday.CANDIDATES[0], "1"
        crawler_module.tgju_intraday.fetch_candles = fetch

    def stored(self):
        now = int(time.time())
        return intraday.load_samples(self.key, now - 86_400 * 2, now + 120)

    def test_every_published_point_is_stored_in_one_pass(self):
        Candle = tgju_intraday.Candle
        self.stub_fetch([
            Candle(self.base, 1.0, 1.0, 1.0, 7_000_000),
            Candle(self.base + 600, 1.0, 1.0, 1.0, 7_010_000),
            Candle(self.base + 1200, 1.0, 1.0, 1.0, 7_020_000),
        ])
        result = self.crawler.sample_intraday([self.key])
        self.assertEqual(result["recorded"], [self.key])
        self.assertEqual(
            list(self.stored().values()), [7_000_000.0, 7_010_000.0, 7_020_000.0]
        )
        # A harvest already covers now, so the live price is not asked for.
        self.assertEqual(self.points_asked, [])

    def test_the_harvest_window_reaches_back_past_midnight(self):
        """Asking only for "now" would return a single point, not the day."""
        self.stub_fetch([tgju_intraday.Candle(self.base, 1.0, 1.0, 1.0, 7_000_000)])
        self.crawler.sample_intraday([self.key])
        _key, from_ts, to_ts = self.asked[0]
        self.assertGreaterEqual(to_ts - from_ts, 24 * 3600)

    def test_a_failed_harvest_falls_back_to_the_live_price(self):
        self.stub_fetch(error=TgjuError("سرویس نمودار پاسخ نداد."))
        result = self.crawler.sample_intraday([self.key])
        self.assertEqual(result["recorded"], [self.key])
        self.assertEqual(self.points_asked, [self.key])
        self.assertEqual(list(self.stored().values()), [9_999_999.0])

    def test_an_empty_harvest_falls_back_to_the_live_price(self):
        self.stub_fetch([])
        result = self.crawler.sample_intraday([self.key])
        self.assertEqual(result["recorded"], [self.key])
        self.assertEqual(list(self.stored().values()), [9_999_999.0])

    def test_source_recorded_never_touches_the_chart_source(self):
        self.crawler.settings.update({"intraday_source": "recorded"})
        self.stub_fetch([tgju_intraday.Candle(self.base, 1.0, 1.0, 1.0, 7_000_000)])
        self.crawler.sample_intraday([self.key])
        self.assertEqual(self.asked, [])
        self.assertEqual(list(self.stored().values()), [9_999_999.0])

    def test_re_harvesting_the_same_points_does_not_duplicate_them(self):
        """A scheduler firing twice must not store the same point again."""
        candles = [tgju_intraday.Candle(self.base, 1.0, 1.0, 1.0, 7_000_000)]
        self.stub_fetch(candles)
        self.crawler.sample_intraday([self.key])
        self.crawler.sample_intraday([self.key])
        prices = list(self.stored().values())
        self.assertEqual(prices.count(7_000_000.0), 1)
        # The second harvest added nothing, so that pass recorded the live
        # price instead - which is new information, not a duplicate.
        self.assertEqual(self.points_asked, [self.key])
        self.assertIn(9_999_999.0, prices)


class TestScheduledSymbols(unittest.TestCase):
    """What the background sampler records is its own setting."""

    def setUp(self):
        self.crawler = Crawler(Settings(tempfile.mktemp(suffix=".json")))

    def test_an_empty_list_falls_back_to_the_watched_symbols(self):
        self.crawler.settings.update({"symbols": ["geram18", "sekee"], "sampler_symbols": []})
        self.assertEqual(self.crawler.scheduled_symbols(), ["geram18", "sekee"])

    def test_a_chosen_list_wins_over_the_watched_symbols(self):
        self.crawler.settings.update(
            {"symbols": ["price_dollar_rl"], "sampler_symbols": ["geram18", "nim"]}
        )
        self.assertEqual(self.crawler.scheduled_symbols(), ["geram18", "nim"])

    def test_blank_and_whitespace_entries_are_dropped(self):
        # A whitespace-only key would make the sampler request a nameless URL.
        self.crawler.settings.update({"sampler_symbols": ["geram18", "", "   ", " sekee "]})
        self.assertEqual(self.crawler.scheduled_symbols(), ["geram18", "sekee"])

    def test_sampling_uses_the_scheduled_list_when_no_keys_are_given(self):
        self.crawler.settings.update(
            {"symbols": ["price_dollar_rl"], "sampler_symbols": ["geram18"],
             "intraday_source": "recorded"}
        )
        asked = []

        def points_for(symbol, force=False):
            asked.append(symbol.key)
            return [PricePoint(str(JalaliDate.today()), "", None, 1, 2, 7_000_000)], False
        self.crawler.points_for = points_for

        self.crawler.sample_intraday()
        self.assertEqual(asked, ["geram18"])


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
_ORIGINAL_FETCH_CANDLES = crawler_module.tgju_intraday.fetch_candles

if __name__ == "__main__":
    unittest.main()
