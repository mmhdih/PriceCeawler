"""Tests for the WSGI adapter used by shared-hosting deployments (Passenger).

These simulate what a host like cPanel/DirectAdmin's "Setup Python App" does:
call ``application(environ, start_response)`` directly, with SCRIPT_NAME set
to a subfolder mount point, no real socket involved.
"""

import io
import json
import os
import subprocess
import sys
import tempfile
import unittest

os.environ["PRICECEAWLER_DATA_DIR"] = tempfile.mkdtemp(prefix="pc-wsgi-test-")

import passenger_wsgi as wsgi_app  # noqa: E402


def call(method, path, script_name="/GoldCrawler", body=None, token=None, headers=None):
    data = json.dumps(body).encode("utf-8") if body is not None else b""
    environ = {
        "REQUEST_METHOD": method,
        "SCRIPT_NAME": script_name,
        "PATH_INFO": path,
        "QUERY_STRING": "",
        "CONTENT_LENGTH": str(len(data)),
        "wsgi.input": io.BytesIO(data),
    }
    if token is not None:
        environ["HTTP_X_PC_TOKEN"] = token
    environ.update(headers or {})

    captured = {}

    def start_response(status, response_headers):
        captured["status"] = status
        captured["headers"] = dict(response_headers)

    body_bytes = b"".join(wsgi_app.application(environ, start_response))
    return captured["status"], captured["headers"], body_bytes


class TestMountPrefix(unittest.TestCase):
    """The app must work identically whether mounted at "/" or a subfolder."""

    def test_bare_mount_point_redirects_to_add_a_trailing_slash(self):
        status, headers, _ = call("GET", "", script_name="/GoldCrawler")
        self.assertTrue(status.startswith("302"))
        self.assertEqual(headers["Location"], "/GoldCrawler/")

    def test_root_mount_bare_path_also_redirects(self):
        status, headers, _ = call("GET", "", script_name="")
        self.assertTrue(status.startswith("302"))
        self.assertEqual(headers["Location"], "/")

    def test_index_has_no_leading_slash_asset_or_api_references(self):
        _, _, body = call("GET", "/")
        html = body.decode("utf-8")
        self.assertIn('href="assets/', html)
        self.assertIn('src="assets/', html)
        self.assertNotIn('href="/assets/', html)
        self.assertNotIn('src="/assets/', html)

    def test_index_embeds_the_current_token(self):
        _, _, body = call("GET", "/")
        self.assertIn(wsgi_app._TOKEN, body.decode("utf-8"))

    def test_bundled_font_css_uses_relative_urls(self):
        _, _, body = call("GET", "/assets/fonts/vazirmatn.css")
        css = body.decode("utf-8")
        self.assertIn("url('Vazirmatn-Regular.woff2')", css)
        self.assertNotIn("/assets/", css)

    def test_static_assets_serve_regardless_of_mount_point(self):
        for script_name in ("", "/GoldCrawler", "/a/deeper/mount"):
            status, _, body = call("GET", "/assets/app.js", script_name=script_name)
            self.assertTrue(status.startswith("200"), script_name)
            self.assertIn(b"function api(", body)


class TestApiAuth(unittest.TestCase):
    def test_missing_token_is_rejected(self):
        status, _, body = call("GET", "/api/meta")
        self.assertTrue(status.startswith("403"))
        self.assertFalse(json.loads(body)["ok"])

    def test_wrong_token_is_rejected(self):
        status, _, _ = call("GET", "/api/meta", token="not-the-token")
        self.assertTrue(status.startswith("403"))

    def test_correct_token_is_accepted(self):
        status, _, body = call("GET", "/api/meta", token=wsgi_app._TOKEN)
        self.assertTrue(status.startswith("200"))
        payload = json.loads(body)
        self.assertTrue(payload["ok"])
        self.assertTrue(any(s["key"] == "geram18" for s in payload["symbols"]))
        self.assertTrue(any(p["id"] == "30" for p in payload["presets"]))


class TestRoutes(unittest.TestCase):
    def test_settings_round_trip(self):
        status, _, body = call("POST", "/api/settings", token=wsgi_app._TOKEN, body={"theme": "dark"})
        self.assertTrue(status.startswith("200"))
        self.assertEqual(json.loads(body)["settings"]["theme"], "dark")

    def test_unknown_api_route_is_404(self):
        status, _, _ = call("GET", "/api/nope", token=wsgi_app._TOKEN)
        self.assertTrue(status.startswith("404"))

    def test_shutdown_is_intentionally_not_exposed(self):
        status, _, _ = call("POST", "/api/shutdown", token=wsgi_app._TOKEN)
        self.assertTrue(status.startswith("404"))

    def test_directory_traversal_is_blocked(self):
        status, _, _ = call("GET", "/assets/../../priceceawler/server.py")
        self.assertTrue(status.startswith("404"))

    def test_health_endpoint(self):
        status, _, body = call("GET", "/health")
        self.assertTrue(status.startswith("200"))
        self.assertTrue(json.loads(body)["ok"])


class TestPersistentToken(unittest.TestCase):
    """A token minted by one worker process must be honoured by another."""

    def test_token_survives_across_separate_processes(self):
        data_dir = tempfile.mkdtemp(prefix="pc-wsgi-cross-proc-")
        root = os.path.dirname(os.path.abspath(__file__)) + "/.."
        script = "from priceceawler.storage import get_or_create_secret; print(get_or_create_secret('t'))"
        env = {**os.environ, "PRICECEAWLER_DATA_DIR": data_dir}

        first = subprocess.run([sys.executable, "-c", script], cwd=root, env=env,
                                capture_output=True, text=True, check=True)
        second = subprocess.run([sys.executable, "-c", script], cwd=root, env=env,
                                 capture_output=True, text=True, check=True)
        self.assertEqual(first.stdout.strip(), second.stdout.strip())
        self.assertTrue(first.stdout.strip())


if __name__ == "__main__":
    unittest.main()
