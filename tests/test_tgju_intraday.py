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
            # Each window answers with one candle inside itself.
            start = int(request.full_url.split("from=")[1].split("&")[0])
            return FakeResponse(json.dumps(udf([start + 60], [10.0])).encode())

        candles, _, _ = ti.fetch_candles(
            OUNCE, base, base + span * 2 + 100, opener=opener
        )
        self.assertGreaterEqual(len(calls), 3)
        self.assertEqual(len(candles), 3)

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


class TestProbe(unittest.TestCase):
    def test_probe_stops_at_the_first_success_and_reports_it(self):
        report = ti.probe(GOLD, opener=in_window_opener("platform.tgju.org"))
        self.assertTrue(report[-1]["ok"])
        self.assertEqual(report[-1]["endpoint"], "platform-tvdata")
        self.assertIn("url", report[-1])

    def test_probe_reports_failures_instead_of_raising(self):
        opener = opener_for({})
        report = ti.probe(GOLD, opener=opener)
        self.assertTrue(report)
        self.assertTrue(all(not row["ok"] for row in report))
        self.assertTrue(all("error" in row for row in report))
        # Every candidate/resolution pair is accounted for.
        self.assertEqual(len(report), len(ti.CANDIDATES) * len(ti.NATIVE_RESOLUTIONS))


if __name__ == "__main__":
    unittest.main()
