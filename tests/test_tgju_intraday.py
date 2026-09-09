import io
import json
import os
import tempfile
import unittest
import urllib.error

os.environ.setdefault("PRICECEAWLER_DATA_DIR", tempfile.mkdtemp(prefix="pc-tgjuintra-"))

from priceceawler import tgju_intraday as ti  # noqa: E402
from priceceawler.symbols import CATALOG, custom_symbol  # noqa: E402
from priceceawler.tgju import TgjuError  # noqa: E402

GOLD = CATALOG["geram18"]          # IRR -> divided by 10 for Toman
OUNCE = CATALOG["ons"]             # USD -> no division


def udf(times, closes=None, *, status="ok", **columns):
    payload = {"s": status, "t": list(times)}
    if closes is not None:
        payload["c"] = list(closes)
    payload.update(columns)
    return payload


class FakeResponse(io.BytesIO):
    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()
        return False


def opener_for(payloads, *, calls=None):
    """A urlopen stand-in: maps a substring of the URL to a payload.

    A payload may be an Exception, which is raised - that is how a candidate
    endpoint that does not exist on this host behaves.
    """
    def fake(request, timeout=None):
        url = request.full_url
        if calls is not None:
            calls.append(url)
        for needle, payload in payloads.items():
            if needle in url:
                if isinstance(payload, Exception):
                    raise payload
                return FakeResponse(json.dumps(payload).encode("utf-8"))
        raise urllib.error.HTTPError(url, 404, "Not Found", {}, None)
    return fake


class TestParseUdf(unittest.TestCase):
    def test_ok_payload_becomes_candles(self):
        payload = udf([1700000000, 1700000600], [70_000_000, 70_100_000],
                      o=[69_900_000, 70_000_000], h=[70_200_000, 70_300_000],
                      l=[69_800_000, 69_950_000])
        candles = ti.parse_udf(payload, GOLD)
        self.assertEqual(len(candles), 2)
        # Rial -> Toman: every price divided by 10.
        self.assertEqual(candles[0].close, 7_000_000)
        self.assertEqual(candles[0].open, 6_990_000)
        self.assertEqual(candles[0].high, 7_020_000)
        self.assertEqual(candles[0].low, 6_980_000)
        self.assertEqual(candles[1].ts, 1700000600)

    def test_dollar_symbols_are_not_divided(self):
        candles = ti.parse_udf(udf([1700000000], [3301.25]), OUNCE)
        self.assertEqual(candles[0].close, 3301.25)

    def test_no_data_is_an_empty_answer_not_an_error(self):
        self.assertEqual(ti.parse_udf(udf([], status="no_data"), GOLD), [])

    def test_error_status_raises_with_the_servers_message(self):
        with self.assertRaises(TgjuError) as caught:
            ti.parse_udf({"s": "error", "errmsg": "unknown_symbol"}, GOLD)
        self.assertIn("unknown_symbol", str(caught.exception))

    def test_an_unknown_status_is_not_silently_accepted(self):
        with self.assertRaises(TgjuError):
            ti.parse_udf({"s": "weird", "t": [1]}, GOLD)

    def test_a_missing_time_column_is_an_error(self):
        with self.assertRaises(TgjuError):
            ti.parse_udf({"s": "ok", "c": [1, 2]}, GOLD)

    def test_millisecond_timestamps_are_normalised_to_seconds(self):
        candles = ti.parse_udf(udf([1700000000000], [70_000_000]), GOLD)
        self.assertEqual(candles[0].ts, 1700000000)

    def test_bars_with_no_price_at_all_are_dropped(self):
        candles = ti.parse_udf(udf([1, 2, 3], [70_000_000, None, 0]), GOLD)
        self.assertEqual([c.ts for c in candles], [1])

    def test_candles_come_back_in_time_order(self):
        candles = ti.parse_udf(udf([300, 100, 200], [3, 1, 2]), OUNCE)
        self.assertEqual([c.ts for c in candles], [100, 200, 300])

    def test_short_price_columns_do_not_raise(self):
        """A feed that sends fewer highs than times must not crash."""
        candles = ti.parse_udf(udf([1, 2], [10.0, 20.0], h=[11.0]), OUNCE)
        self.assertEqual(candles[0].high, 11.0)
        self.assertIsNone(candles[1].high)

    def test_a_non_dict_payload_is_rejected(self):
        with self.assertRaises(TgjuError):
            ti.parse_udf("<html>error</html>", GOLD)


class TestParseRows(unittest.TestCase):
    def test_list_rows_are_read_as_ts_ohlc(self):
        payload = {"data": [[1700000000, 69_900_000, 70_200_000, 69_800_000, 70_000_000]]}
        candle = ti.parse_rows(payload, GOLD)[0]
        self.assertEqual(candle.open, 6_990_000)
        self.assertEqual(candle.high, 7_020_000)
        self.assertEqual(candle.low, 6_980_000)
        self.assertEqual(candle.close, 7_000_000)

    def test_dict_rows_are_read_by_name(self):
        payload = {"data": [{"time": 1700000000, "open": 1.0, "high": 3.0,
                             "low": 0.5, "close": 2.0}]}
        candle = ti.parse_rows(payload, OUNCE)[0]
        self.assertEqual((candle.open, candle.high, candle.low, candle.close),
                         (1.0, 3.0, 0.5, 2.0))

    def test_rows_with_only_a_close_still_parse(self):
        candle = ti.parse_rows({"data": [[1700000000, 5.0]]}, OUNCE)[0]
        self.assertEqual(candle.open, 5.0)
        self.assertIsNone(candle.close)

    def test_junk_rows_are_skipped_not_fatal(self):
        payload = {"data": [None, "x", [], [1700000000, 5.0]]}
        self.assertEqual(len(ti.parse_rows(payload, OUNCE)), 1)


class TestParsePage(unittest.TestCase):
    """The site embeds its intraday series in the profile page HTML.

    Its Network panel stays empty while the intraday chart draws, so there is
    no XHR to call - the series has to be read out of the markup.
    """

    # A Tehran-morning timestamp in milliseconds, as Highcharts uses.
    BASE_MS = 1757041200000
    BASE = 1757041200

    def series(self, count=6, step_ms=600_000, start_ms=None, price=235_188_000):
        start = self.BASE_MS if start_ms is None else start_ms
        return [[start + i * step_ms, price + i * 1000] for i in range(count)]

    def page(self, *arrays, extra=""):
        blocks = "".join(
            f'<script>var s{i} = {{"name":"امروز","data":{json.dumps(a)}}};</script>'
            for i, a in enumerate(arrays)
        )
        return f"<html><head><title>x</title></head><body>{blocks}{extra}</body></html>"

    def test_an_embedded_series_is_extracted(self):
        candles = ti.parse_page(self.page(self.series()), GOLD)
        self.assertEqual(len(candles), 6)
        # Milliseconds become seconds, and rial becomes toman.
        self.assertEqual(candles[0].ts, self.BASE)
        self.assertEqual(candles[0].close, 23_518_800)

    def test_a_page_point_has_no_real_ohlc_so_all_four_match(self):
        candle = ti.parse_page(self.page(self.series()), GOLD)[0]
        self.assertEqual(
            (candle.open, candle.high, candle.low, candle.close),
            (candle.close,) * 4,
        )

    def test_the_multi_year_daily_series_on_the_same_page_never_wins(self):
        """The profile page also embeds a 13-year daily candlestick series."""
        daily = [[(1757030400 + i * 86400) * 1000, 100_000_000 + i] for i in range(400)]
        intraday = self.series(8)
        candles = ti.parse_page(self.page(daily, intraday), GOLD)
        # The long daily array must lose to the short intraday one.
        self.assertEqual(len(candles), 8)
        self.assertTrue(ti.is_intraday_spacing(candles))

    def test_the_longest_intraday_series_wins(self):
        short = self.series(4)
        long = self.series(40, start_ms=self.BASE_MS + 60_000)
        candles = ti.parse_page(self.page(short, long), GOLD)
        self.assertEqual(len(candles), 40)

    def test_second_level_timestamps_are_accepted_too(self):
        seconds = [[self.BASE + i * 600, 235_188_000] for i in range(5)]
        candles = ti.parse_page(self.page(seconds), GOLD)
        self.assertEqual(candles[0].ts, self.BASE)

    def test_dollar_symbols_are_not_divided(self):
        page = self.page([[self.BASE_MS + i * 600_000, 3301] for i in range(5)])
        self.assertEqual(ti.parse_page(page, OUNCE)[0].close, 3301)

    def test_a_udf_object_embedded_in_the_page_is_a_fallback(self):
        times = [self.BASE + i * 600 for i in range(5)]
        blob = json.dumps({"s": "ok", "t": times, "c": [235_188_000] * 5})
        page = f"<html><body><script>window.chart = {blob};</script></body></html>"
        candles = ti.parse_page(page, GOLD)
        self.assertEqual(len(candles), 5)
        self.assertEqual(candles[0].close, 23_518_800)

    def test_a_page_without_any_series_says_the_layout_changed(self):
        with self.assertRaises(TgjuError) as caught:
            ti.parse_page("<html><body><p>قیمت طلا</p></body></html>", GOLD)
        self.assertIn("ساختار", str(caught.exception))

    def test_an_empty_body_is_rejected(self):
        with self.assertRaises(TgjuError):
            ti.parse_page("", GOLD)

    def test_junk_pairs_inside_a_series_are_skipped(self):
        page = self.page([[self.BASE_MS, 0], [self.BASE_MS + 600_000, 235_188_000],
                          [self.BASE_MS + 1_200_000, 235_200_000]])
        candles = ti.parse_page(page, GOLD)
        # The zero price is not an observation.
        self.assertEqual(len(candles), 2)

    def test_the_page_source_is_fetched_once_not_per_resolution(self):
        calls: list[str] = []
        page = self.page(self.series(20))

        def opener(request, timeout=None):
            calls.append(request.full_url)
            return FakeResponse(page.encode("utf-8"))

        page_only = tuple(e for e in ti.CANDIDATES if e.name == "tgju-page")
        candles, endpoint, _ = ti.fetch_candles(
            GOLD, self.BASE - 3600, self.BASE + 4 * 3600,
            endpoints=page_only, opener=opener,
        )
        self.assertEqual(endpoint.name, "tgju-page")
        self.assertEqual(len(calls), 1, "one page fetch, not one per resolution")
        self.assertTrue(candles)

    def test_the_page_url_carries_the_symbol_key(self):
        endpoint = next(e for e in ti.CANDIDATES if e.name == "tgju-page")
        url = ti._build_url(endpoint, GOLD, "1", 100, 200)
        self.assertTrue(url.endswith("/profile/geram18"))
        self.assertNotIn("{", url)


class TestEndpointSelection(unittest.TestCase):
    def test_no_pin_tries_every_candidate(self):
        self.assertEqual(ti.endpoints_from_setting(""), ti.CANDIDATES)
        self.assertEqual(ti.endpoints_from_setting("   "), ti.CANDIDATES)

    def test_a_known_name_pins_exactly_that_endpoint(self):
        picked = ti.endpoints_from_setting("api-tvdata")
        self.assertEqual([e.name for e in picked], ["api-tvdata"])

    def test_an_unknown_name_falls_back_to_probing_everything(self):
        self.assertEqual(ti.endpoints_from_setting("nope"), ti.CANDIDATES)

    def test_a_url_template_becomes_a_custom_endpoint(self):
        template = "https://x.example/history?symbol={symbol}&resolution={resolution}&from={from}&to={to}"
        picked = ti.endpoints_from_setting(template)
        self.assertEqual(len(picked), 1)
        self.assertEqual(picked[0].name, "custom")
        self.assertEqual(picked[0].url, template)

    def test_symbol_spelling_styles(self):
        symbol = custom_symbol("crypto-bitcoin", "بیت‌کوین", "USD", None, 2)
        self.assertEqual(ti._symbol_for(symbol, "key"), "crypto-bitcoin")
        self.assertEqual(ti._symbol_for(symbol, "upper"), "CRYPTO-BITCOIN")
        self.assertEqual(ti._symbol_for(symbol, "upper_underscore"), "CRYPTO_BITCOIN")

    def test_urls_are_built_with_every_placeholder_filled(self):
        url = ti._build_url(ti.CANDIDATES[0], GOLD, "10", 100, 200)
        self.assertIn("symbol=geram18", url)
        self.assertIn("resolution=10", url)
        self.assertIn("from=100", url)
        self.assertIn("to=200", url)


class TestWindows(unittest.TestCase):
    def test_a_short_range_is_one_window(self):
        self.assertEqual(list(ti._windows(0, 100, 1000)), [(0, 100)])

    def test_a_long_range_is_split_and_covers_everything(self):
        windows = list(ti._windows(0, 2500, 1000))
        self.assertEqual(windows[0][0], 0)
        self.assertEqual(windows[-1][1], 2500)
        # Contiguous, non-overlapping.
        for (_, end), (start, _) in zip(windows, windows[1:]):
            self.assertEqual(start, end + 1)

    def test_an_inverted_range_yields_nothing(self):
        self.assertEqual(list(ti._windows(500, 100, 1000)), [])


class TestFetchCandles(unittest.TestCase):
    def test_the_first_working_endpoint_and_resolution_are_reported(self):
        opener = opener_for({"platform.tgju.org": udf([1700000000], [70_000_000])})
        candles, endpoint, resolution = ti.fetch_candles(
            GOLD, 1700000000, 1700003600, opener=opener
        )
        self.assertEqual(len(candles), 1)
        self.assertEqual(endpoint.name, "platform-tvdata")
        self.assertEqual(resolution, "1")

    def test_a_dead_endpoint_is_skipped_for_the_next_candidate(self):
        opener = opener_for({
            "platform.tgju.org": urllib.error.URLError("no route"),
            "api.tgju.org/v1/tvdata": udf([1700000000], [70_000_000]),
        })
        _, endpoint, _ = ti.fetch_candles(GOLD, 1700000000, 1700003600, opener=opener)
        self.assertEqual(endpoint.name, "api-tvdata")

    def test_no_data_from_one_resolution_moves_on_to_the_next(self):
        calls: list[str] = []
        seen = {"n": 0}

        def opener(request, timeout=None):
            calls.append(request.full_url)
            seen["n"] += 1
            # The finest resolution is unsupported here; the second answers.
            if "resolution=1&" in request.full_url:
                return FakeResponse(json.dumps(udf([], status="no_data")).encode())
            return FakeResponse(json.dumps(udf([1700000000], [70_000_000])).encode())

        candles, _, resolution = ti.fetch_candles(
            GOLD, 1700000000, 1700003600, opener=opener
        )
        self.assertEqual(resolution, "5")
        self.assertEqual(len(candles), 1)

    def test_when_nothing_answers_the_error_names_the_attempts(self):
        opener = opener_for({})  # every URL 404s
        with self.assertRaises(TgjuError) as caught:
            ti.fetch_candles(GOLD, 1700000000, 1700003600, opener=opener)
        message = str(caught.exception)
        self.assertIn("تلاش‌ها", message)
        self.assertIn("HTTPError", message)

    def test_candles_outside_the_requested_range_are_dropped(self):
        opener = opener_for({
            "platform.tgju.org": udf([1699000000, 1700000000, 1799000000],
                                     [1.0, 2.0, 3.0]),
        })
        candles, _, _ = ti.fetch_candles(
            OUNCE, 1700000000, 1700003600, opener=opener
        )
        self.assertEqual([c.ts for c in candles], [1700000000])

    def test_a_wide_range_is_stitched_from_several_windows(self):
        calls: list[str] = []
        span = ti.MAX_SPAN_SECONDS
        base = 1700000000

        def opener(request, timeout=None):
            calls.append(request.full_url)
            # Each window answers with a run of 1-minute bars inside itself,
            # so the stitched result is genuinely intraday.
            start = int(request.full_url.split("from=")[1].split("&")[0])
            times = [start + 60 * i for i in range(1, 6)]
            return FakeResponse(json.dumps(udf(times, [10.0] * len(times))).encode())

        candles, _, _ = ti.fetch_candles(
            OUNCE, base, base + span * 2 + 100, opener=opener
        )
        self.assertGreaterEqual(len(calls), 3)
        # 5 bars from each full window, plus the single bar that fits inside
        # the 98-second remainder window - anything past `to` is clipped.
        self.assertEqual(len(candles), 11)
        # Bars from the first and last window are both present, in order.
        self.assertEqual(candles[0].ts, base + 60)
        self.assertGreater(candles[-1].ts, base + span * 2)
        self.assertEqual(candles, sorted(candles, key=lambda c: c.ts))

    def test_an_inverted_range_is_rejected_before_any_request(self):
        calls: list[str] = []
        with self.assertRaises(TgjuError):
            ti.fetch_candles(GOLD, 200, 100, opener=opener_for({}, calls=calls))
        self.assertEqual(calls, [])

    def test_a_pinned_endpoint_is_the_only_one_tried(self):
        calls: list[str] = []
        opener = opener_for({"api.tgju.org/v1/tvdata": udf([1700000000], [1.0])},
                            calls=calls)
        ti.fetch_candles(
            OUNCE, 1700000000, 1700003600,
            endpoints=ti.endpoints_from_setting("api-tvdata"), opener=opener,
        )
        self.assertTrue(all("api.tgju.org/v1/tvdata" in url for url in calls))


def in_window_opener(match):
    """Answer with one candle inside whatever window was requested.

    `probe` asks for "the last N hours", so a fixture with a fixed timestamp
    would be filtered out as out-of-range - exactly as real out-of-range data
    should be.
    """
    def fake(request, timeout=None):
        url = request.full_url
        if match not in url:
            raise urllib.error.HTTPError(url, 404, "Not Found", {}, None)
        start = int(url.split("from=")[1].split("&")[0])
        return FakeResponse(json.dumps(udf([start + 60], [70_000_000])).encode())
    return fake


class TestGranularity(unittest.TestCase):
    """The endpoint answers an unrecognised resolution with DAILY bars.

    Regression cover for a real failure: asking for resolution=1 over a week
    returned one bar per day, stamped 00:00 UTC (03:30 Tehran), and the app
    relabelled them as 10-minute rows. Rows are not evidence of granularity.
    """

    def daily_bars(self, count=5, start=1757030400):
        # 1757030400 is exactly a midnight UTC, like the real response.
        return [ti.Candle(start + i * 86400, 1.0, 1.0, 1.0, 1.0) for i in range(count)]

    def minute_bars(self, count=5, start=1757037600):
        return [ti.Candle(start + i * 60, 1.0, 1.0, 1.0, 1.0) for i in range(count)]

    def test_median_gap_ignores_a_single_long_market_gap(self):
        bars = self.minute_bars(5)
        # One overnight gap must not make four minute-spaced bars look daily.
        bars.append(ti.Candle(bars[-1].ts + 14 * 3600, 1.0, 1.0, 1.0, 1.0))
        self.assertEqual(ti.median_gap(bars), 60)
        self.assertTrue(ti.is_intraday_spacing(bars))

    def test_median_gap_needs_two_bars(self):
        self.assertIsNone(ti.median_gap([]))
        self.assertIsNone(ti.median_gap(self.minute_bars(1)))

    def test_daily_bars_are_not_intraday(self):
        self.assertFalse(ti.is_intraday_spacing(self.daily_bars()))

    def test_minute_bars_are_intraday(self):
        self.assertTrue(ti.is_intraday_spacing(self.minute_bars()))

    def test_hourly_bars_are_intraday(self):
        bars = [ti.Candle(1757037600 + i * 3600, 1.0, 1.0, 1.0, 1.0) for i in range(5)]
        self.assertTrue(ti.is_intraday_spacing(bars))

    def test_a_lone_bar_is_judged_by_whether_it_sits_on_a_day_boundary(self):
        self.assertFalse(ti.is_intraday_spacing([ti.Candle(1757030400, 1.0, 1.0, 1.0, 1.0)]))
        self.assertTrue(ti.is_intraday_spacing([ti.Candle(1757030400 + 600, 1.0, 1.0, 1.0, 1.0)]))

    def test_no_bars_is_not_intraday(self):
        self.assertFalse(ti.is_intraday_spacing([]))

    def test_a_daily_only_feed_raises_instead_of_mislabelling(self):
        """The reported bug: a daily series must never come back as intraday."""
        bars = self.daily_bars()
        payload = udf([b.ts for b in bars], [70_000_000] * len(bars))
        opener = opener_for({"tgju.org": payload})

        with self.assertRaises(TgjuError) as caught:
            ti.fetch_candles(GOLD, bars[0].ts, bars[-1].ts + 86400, opener=opener)
        message = str(caught.exception)
        self.assertIn("فقط داده روزانه", message)
        self.assertIn("داده روزانه", message)

    def test_an_intraday_resolution_later_in_the_list_still_wins(self):
        """A feed where only one spelling is honoured must still be found."""
        def opener(request, timeout=None):
            url = request.full_url
            start = int(url.split("from=")[1].split("&")[0])
            # Only "10m" yields minute bars; everything else answers daily.
            if "resolution=10m&" in url:
                times = [start + 600 * i for i in range(1, 6)]
            else:
                times = [start + 86400 * i for i in range(5)]
            return FakeResponse(json.dumps(udf(times, [70_000_000] * 5)).encode())

        base = 1757030400
        candles, _, resolution = ti.fetch_candles(
            GOLD, base, base + 5 * 86400, opener=opener
        )
        self.assertEqual(resolution, "10m")
        self.assertTrue(ti.is_intraday_spacing(candles))

    def test_the_resolution_list_covers_several_spellings(self):
        """The bug happened because only bare minute counts were tried."""
        self.assertIn("1", ti.NATIVE_RESOLUTIONS)
        self.assertIn("10m", ti.NATIVE_RESOLUTIONS)
        self.assertIn("60min", ti.NATIVE_RESOLUTIONS)
        self.assertEqual(ti.NATIVE_RESOLUTIONS[0], "1", "finest first")


class TestProbe(unittest.TestCase):
    def test_probe_stops_at_the_first_success_and_reports_it(self):
        report = ti.probe(GOLD, opener=in_window_opener("platform.tgju.org"))
        self.assertTrue(report[-1]["ok"])
        self.assertEqual(report[-1]["endpoint"], "platform-tvdata")
        self.assertIn("url", report[-1])

    def test_probe_reports_daily_data_as_not_working(self):
        """Daily bars are a failure for an intraday probe, and say why."""
        def opener(request, timeout=None):
            # Midnight-UTC boundaries *inside* the probed window, which is
            # exactly the shape the real endpoint returned.
            url = request.full_url
            start = int(url.split("from=")[1].split("&")[0])
            stop = int(url.split("to=")[1].split("&")[0])
            first = -(-start // 86400) * 86400  # round up to a day boundary
            times = list(range(first, stop + 1, 86400))
            return FakeResponse(
                json.dumps(udf(times, [70_000_000] * len(times))).encode()
            )

        report = ti.probe(GOLD, opener=opener)
        self.assertTrue(all(not row["ok"] for row in report))
        daily_rows = [r for r in report if r.get("candles")]
        self.assertTrue(daily_rows)
        self.assertEqual(daily_rows[0]["spacing_seconds"], 86400)
        self.assertFalse(daily_rows[0]["intraday"])
        self.assertIn("روزانه", daily_rows[0]["error"])

    def test_probe_reports_the_spacing_it_measured(self):
        report = ti.probe(GOLD, opener=in_window_opener("platform.tgju.org"))
        working = report[-1]
        self.assertTrue(working["ok"])
        self.assertTrue(working["intraday"])

    def test_dump_writes_each_raw_body_to_a_file(self):
        """The raw body is the ground truth for writing a correct parser."""
        import pathlib
        import tempfile

        out = tempfile.mkdtemp(prefix="pc-dump-")
        report = ti.probe(GOLD, opener=in_window_opener("platform.tgju.org"),
                          dump_dir=out)
        dumps = [row["dump"] for row in report if row.get("dump")]
        self.assertTrue(dumps)
        written = [d for d in dumps if not d.startswith("ذخیره نشد")]
        self.assertTrue(written, f"no body written: {dumps}")
        self.assertTrue(pathlib.Path(written[0]).is_file())
        self.assertIn('"s"', pathlib.Path(written[0]).read_text(encoding="utf-8"))

    def test_a_dump_failure_never_breaks_the_probe(self):
        report = ti.probe(GOLD, opener=opener_for({}), dump_dir="/proc/nope/nope")
        self.assertTrue(report)
        self.assertTrue(all(row["dump"].startswith("ذخیره نشد") for row in report))

    def test_probe_reports_failures_instead_of_raising(self):
        opener = opener_for({})
        report = ti.probe(GOLD, opener=opener)
        self.assertTrue(report)
        self.assertTrue(all(not row["ok"] for row in report))
        self.assertTrue(all("error" in row for row in report))
        # Every candidate is accounted for; a source that ignores
        # {resolution} is asked once instead of once per code.
        expected = sum(
            len(ti.NATIVE_RESOLUTIONS) if endpoint.resolution_aware else 1
            for endpoint in ti.CANDIDATES
        )
        self.assertEqual(len(report), expected)


if __name__ == "__main__":
    unittest.main()
