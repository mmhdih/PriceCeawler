import json
import os
import tempfile
import threading
import unittest
import urllib.error
import urllib.request

_TMP = tempfile.mkdtemp(prefix="priceceawler-test-")
os.environ["PRICECEAWLER_DATA_DIR"] = _TMP

from priceceawler import server as server_module  # noqa: E402
from priceceawler.crawler import Crawler  # noqa: E402
from priceceawler.jalali import JalaliDate  # noqa: E402
from priceceawler.tgju import PricePoint, TgjuError  # noqa: E402


def fake_points(symbol, **_kwargs):
    if symbol.key == "broken":
        raise TgjuError("نماد آزمایشی خراب است.")
    today = JalaliDate.today()
    return [
        PricePoint(str(today.add_days(-offset)), "", None, 100 + offset, 120 + offset, 110 + offset)
        for offset in range(9, -1, -1)
    ]


class ServerTestCase(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls._original = Crawler.points_for
        Crawler.points_for = lambda self, symbol, force=False: (fake_points(symbol), False)

        cls.server = server_module.create_server("127.0.0.1", 0)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = cls.server.url.rstrip("/")
        cls.token = cls.server.token

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        Crawler.points_for = cls._original

    # -- helpers ---------------------------------------------------------
    def call(self, path, payload=None, token=None, raw=False, headers=None):
        request = urllib.request.Request(
            self.base + path,
            data=json.dumps(payload).encode("utf-8") if payload is not None else None,
            headers={
                "X-PC-Token": self.token if token is None else token,
                "Content-Type": "application/json",
                **(headers or {}),
            },
            method="POST" if payload is not None else "GET",
        )
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                body = response.read()
                return response.status, (body if raw else json.loads(body)), dict(response.headers)
        except urllib.error.HTTPError as exc:
            body = exc.read()
            try:
                return exc.code, json.loads(body), dict(exc.headers)
            except json.JSONDecodeError:
                return exc.code, body, dict(exc.headers)

    # -- tests -----------------------------------------------------------
    def test_index_is_served_with_the_token_injected(self):
        with urllib.request.urlopen(self.base + "/", timeout=20) as response:
            html = response.read().decode("utf-8")
        self.assertIn(self.token, html)
        self.assertNotIn("__APP_TOKEN__", html)
        self.assertIn('dir="rtl"', html)

    def test_font_css_is_generated(self):
        with urllib.request.urlopen(self.base + "/assets/fonts/vazirmatn.css", timeout=20) as response:
            css = response.read().decode("utf-8")
        self.assertIn("Vazirmatn", css)
        self.assertIn("@font-face", css)

    def test_api_requires_the_token(self):
        status, payload, _ = self.call("/api/meta", token="wrong")
        self.assertEqual(status, 403)
        self.assertFalse(payload["ok"])

    def test_meta_lists_symbols_and_presets(self):
        status, payload, _ = self.call("/api/meta")
        self.assertEqual(status, 200)
        self.assertTrue(payload["ok"])
        self.assertTrue(any(s["key"] == "geram18" for s in payload["symbols"]))
        self.assertTrue(any(p["id"] == "30" for p in payload["presets"]))
        self.assertIn("settings", payload)

    def test_series_returns_rows_and_stats(self):
        today = JalaliDate.today()
        status, payload, _ = self.call(
            "/api/series",
            {"symbols": ["geram18"], "start": str(today.add_days(-5)), "end": str(today)},
        )
        self.assertEqual(status, 200)
        self.assertTrue(payload["ok"])
        self.assertEqual(len(payload["series"]), 1)
        self.assertEqual(len(payload["series"][0]["rows"]), 6)
        self.assertIsNotNone(payload["series"][0]["stats"]["last"])

    def test_series_reports_per_symbol_errors_but_keeps_the_rest(self):
        today = JalaliDate.today()
        status, payload, _ = self.call(
            "/api/series",
            {"symbols": ["geram18", "broken"], "start": str(today.add_days(-3)), "end": str(today)},
        )
        self.assertEqual(status, 200)
        self.assertEqual(len(payload["series"]), 1)
        self.assertEqual(payload["errors"][0]["symbol"], "broken")

    def test_series_validates_the_date_range(self):
        status, payload, _ = self.call(
            "/api/series", {"symbols": ["geram18"], "start": "1404/05/10", "end": "1404/05/01"}
        )
        self.assertEqual(status, 400)
        self.assertIn("تاریخ شروع", payload["error"])

    def test_series_rejects_an_empty_selection(self):
        status, payload, _ = self.call("/api/series", {"symbols": [], "start": "1404/01/01", "end": "1404/01/02"})
        self.assertEqual(status, 400)
        self.assertIn("نماد", payload["error"])

    def test_export_returns_an_xlsx_attachment(self):
        today = JalaliDate.today()
        status, body, headers = self.call(
            "/api/export",
            {"symbols": ["geram18"], "start": str(today.add_days(-3)), "end": str(today), "format": "xlsx"},
            raw=True,
        )
        self.assertEqual(status, 200)
        self.assertTrue(body.startswith(b"PK"))  # xlsx is a zip archive
        self.assertIn("attachment", headers["Content-Disposition"])

    def test_export_rejects_an_unknown_format(self):
        status, payload, _ = self.call(
            "/api/export",
            {"symbols": ["geram18"], "start": "1404/01/01", "end": "1404/01/02", "format": "pdf"},
        )
        self.assertEqual(status, 400)

    def test_settings_round_trip(self):
        status, payload, _ = self.call("/api/settings", {"theme": "dark", "unknown_key": 1})
        self.assertEqual(status, 200)
        self.assertEqual(payload["settings"]["theme"], "dark")
        self.assertNotIn("unknown_key", payload["settings"])

    def test_custom_symbol_is_validated_and_stored(self):
        status, payload, _ = self.call("/api/symbols", {"key": "bad key!", "name": "x"})
        self.assertEqual(status, 400)

        status, payload, _ = self.call("/api/symbols", {"key": "my_symbol", "name": "نماد من"})
        self.assertEqual(status, 200)
        self.assertTrue(any(s["key"] == "my_symbol" for s in payload["symbols"]))

    def test_directory_traversal_is_blocked(self):
        status, _, _ = self.call("/assets/../../priceceawler/server.py")
        self.assertEqual(status, 404)

    def test_non_loopback_host_header_is_rejected(self):
        status, payload, _ = self.call("/api/meta", headers={"Host": "evil.example.com"})
        self.assertEqual(status, 403)

    def test_unknown_route(self):
        status, _, _ = self.call("/api/nope")
        self.assertEqual(status, 404)


if __name__ == "__main__":
    unittest.main()
