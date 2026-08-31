"""WSGI entry point for shared hosting (cPanel/DirectAdmin "Setup Python App",
which runs on Phusion Passenger and expects a module-level ``application``).

This is a second, independent transport for the same application core used by
``priceceawler/server.py`` (which runs its own raw socket server for the
portable desktop build). It exists because Passenger speaks WSGI, not raw
HTTP, and because a public multi-worker deployment needs two things the
desktop server does not:

- Every route is resolved from WSGI's ``PATH_INFO`` (the request path with the
  app's mount prefix, e.g. ``/GoldCrawler``, already stripped by the web
  server) rather than the raw request line, so the exact same route strings
  ("/", "/api/meta", "/assets/...") work whether the app is mounted at the
  domain root or a subfolder.
- The access token is persisted to disk (see ``storage.get_or_create_secret``)
  instead of freshly randomised per process, because Passenger runs several
  worker *processes*: a token embedded in HTML by one worker must still be
  accepted by whichever worker handles the follow-up API call.

Deploy by pointing your host's Python App "Startup File" at this file, then
``pip install -r requirements.txt`` inside the app's virtualenv. See the
"استقرار روی هاست اشتراکی" section of README.md for the full walkthrough.
"""

from __future__ import annotations

import json
import mimetypes
import re
import secrets
import sys
import traceback
import urllib.parse
from http import HTTPStatus
from pathlib import Path
from typing import Any, Callable, Iterable

sys.path.insert(0, str(Path(__file__).resolve().parent))

from priceceawler.crawler import Crawler  # noqa: E402
from priceceawler.fonts import font_css  # noqa: E402
from priceceawler.jalali import JalaliDate  # noqa: E402
from priceceawler.report import to_csv, to_json, to_xlsx  # noqa: E402
from priceceawler.server import (  # noqa: E402
    WEB_DIR,
    ApiError,
    _parse_range,
    _symbol_keys,
    range_presets,
)
from priceceawler.storage import Settings, data_dir, get_or_create_secret  # noqa: E402
from priceceawler.version import APP_NAME, APP_TITLE_FA, __version__  # noqa: E402

MAX_BODY_BYTES = 1 << 20
_SAFE_ASSET_PATH = re.compile(r"^[A-Za-z0-9._/-]+$")

# One process-wide instance per worker; all workers share the same on-disk
# settings/cache/archive under data_dir(), so this is safe under Passenger's
# multi-process model (see get_or_create_secret's docstring for why the
# token specifically needs to be shared rather than per-instance).
_settings = Settings()
_crawler = Crawler(_settings)
_TOKEN = get_or_create_secret("wsgi_access_token")

Response = tuple[str, list[tuple[str, str]], bytes]


def _html(status: HTTPStatus, body: str) -> Response:
    payload = body.encode("utf-8")
    return f"{status.value} {status.phrase}", [("Content-Type", "text/html; charset=utf-8")], payload


def _bytes(status: HTTPStatus, body: bytes, content_type: str, extra: dict[str, str] | None = None) -> Response:
    headers = [("Content-Type", content_type), ("Content-Length", str(len(body))), ("Cache-Control", "no-store")]
    headers.extend((extra or {}).items())
    return f"{status.value} {status.phrase}", headers, body


def _json(payload: Any, status: HTTPStatus = HTTPStatus.OK) -> Response:
    return _bytes(status, json.dumps(payload, ensure_ascii=False).encode("utf-8"), "application/json; charset=utf-8")


def _error(message: str, status: HTTPStatus = HTTPStatus.BAD_REQUEST) -> Response:
    return _json({"ok": False, "error": message}, status)


def _redirect(location: str) -> Response:
    return "302 Found", [("Location", location), ("Content-Length", "0")], b""


def _read_body(environ: dict) -> dict:
    try:
        length = int(environ.get("CONTENT_LENGTH") or 0)
    except ValueError:
        raise ApiError("طول درخواست نامعتبر است.")
    if length > MAX_BODY_BYTES:
        raise ApiError("حجم درخواست بیش از حد مجاز است.", HTTPStatus.REQUEST_ENTITY_TOO_LARGE)
    if length <= 0:
        return {}
    try:
        payload = json.loads(environ["wsgi.input"].read(length).decode("utf-8"))
    except (json.JSONDecodeError, UnicodeDecodeError) as exc:
        raise ApiError("بدنه درخواست JSON معتبر نیست.") from exc
    if not isinstance(payload, dict):
        raise ApiError("بدنه درخواست باید یک شیء JSON باشد.")
    return payload


def _serve_index(environ: dict) -> Response:
    try:
        html = (WEB_DIR / "index.html").read_text(encoding="utf-8")
    except OSError:
        return _error("فایل رابط کاربری پیدا نشد.", HTTPStatus.INTERNAL_SERVER_ERROR)
    html = html.replace("__APP_TOKEN__", _TOKEN).replace("__APP_VERSION__", __version__)
    return _html(HTTPStatus.OK, html)


def _serve_static(relative: str) -> Response:
    if not relative or ".." in relative or not _SAFE_ASSET_PATH.match(relative):
        return _error("مسیر نامعتبر است.", HTTPStatus.NOT_FOUND)
    target = (WEB_DIR / "assets" / relative).resolve()
    assets_root = (WEB_DIR / "assets").resolve()
    if not target.is_file() or assets_root not in target.parents:
        return _error("فایل پیدا نشد.", HTTPStatus.NOT_FOUND)
    content_type = mimetypes.guess_type(target.name)[0] or "application/octet-stream"
    if content_type.startswith("text/") or content_type == "application/javascript":
        content_type += "; charset=utf-8"
    return _bytes(HTTPStatus.OK, target.read_bytes(), content_type)


def _api_meta() -> Response:
    today = JalaliDate.today()
    return _json(
        {
            "ok": True,
            "app": APP_NAME,
            "titleFa": APP_TITLE_FA,
            "version": __version__,
            "today": str(today),
            "todayLong": f"{today.weekday_name} {today.day} {today.month_name} {today.year}",
            "dataDir": str(data_dir()),
            "symbols": [s.to_dict() for s in _crawler.known_symbols()],
            "settings": _settings.as_dict(),
            "archive": _crawler.archive.summary(),
            "presets": range_presets(today),
        }
    )


def _api_archive() -> Response:
    return _json({"ok": True, "archive": _crawler.archive.summary()})


def _api_series(environ: dict) -> Response:
    payload = _read_body(environ)
    keys = _symbol_keys(payload)
    start, end = _parse_range(payload)
    result = _crawler.build(
        keys, start, end,
        fill_gaps=bool(payload.get("fillGaps", True)),
        force=bool(payload.get("force", False)),
    )
    if not result.series and result.errors:
        return _json({"ok": False, "error": result.errors[0]["message"], **result.to_dict()})
    return _json({"ok": True, "range": {"start": str(start), "end": str(end)}, **result.to_dict()})


def _api_export(environ: dict) -> Response:
    payload = _read_body(environ)
    keys = _symbol_keys(payload)
    start, end = _parse_range(payload)
    fmt = str(payload.get("format", "xlsx")).lower()
    if fmt not in {"xlsx", "csv", "json"}:
        raise ApiError("قالب خروجی پشتیبانی نمی‌شود.")

    result = _crawler.build(keys, start, end, fill_gaps=bool(payload.get("fillGaps", True)))
    if not result.series:
        message = result.errors[0]["message"] if result.errors else "داده‌ای برای خروجی وجود ندارد."
        raise ApiError(message)

    if fmt == "xlsx":
        body = to_xlsx(result.series, start, end)
        content_type = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    elif fmt == "csv":
        body = to_csv(result.series)
        content_type = "text/csv; charset=utf-8"
    else:
        body = to_json(result.series, start, end)
        content_type = "application/json; charset=utf-8"

    name = f"TGJU-{str(start).replace('/', '-')}_{str(end).replace('/', '-')}.{fmt}"
    return _bytes(
        HTTPStatus.OK, body, content_type,
        {
            "Content-Disposition": f'attachment; filename="{name}"',
            "X-Export-Warnings": urllib.parse.quote(
                json.dumps([e["message"] for e in result.errors], ensure_ascii=False)
            ),
        },
    )


def _api_settings(environ: dict) -> Response:
    payload = _read_body(environ)
    return _json({"ok": True, "settings": _settings.update(payload)})


def _api_symbols(environ: dict) -> Response:
    payload = _read_body(environ)
    key = str(payload.get("key", "")).strip()
    if not key or not re.match(r"^[A-Za-z0-9_.-]{2,64}$", key):
        raise ApiError("شناسه نماد فقط می‌تواند شامل حروف انگلیسی، عدد، «-»، «_» و «.» باشد.")
    name = str(payload.get("name", "")).strip() or key
    currency = "USD" if str(payload.get("currency", "IRR")).upper() == "USD" else "IRR"

    customs = [c for c in (_settings.get("custom_symbols") or []) if c.get("key") != key]
    customs.append({"key": key, "name": name, "currency": currency})
    _settings.update({"custom_symbols": customs[-50:]})
    return _json({"ok": True, "symbols": [s.to_dict() for s in _crawler.known_symbols()]})


def _api_crawl(environ: dict) -> Response:
    payload = _read_body(environ)
    keys = payload.get("symbols") or _settings.get("symbols")
    return _json({"ok": True, **_crawler.daily_crawl(keys)})


# (method, path) -> handler. Deliberately no "/api/shutdown": under Passenger
# the web server owns the worker process lifecycle, and exposing a shutdown
# endpoint on a publicly reachable site would just be an unnecessary nuisance
# vector for no benefit (Passenger restarts workers on its own schedule anyway).
_ROUTES: dict[tuple[str, str], Callable[..., Response]] = {
    ("GET", "/api/meta"): lambda environ: _api_meta(),
    ("GET", "/api/archive"): lambda environ: _api_archive(),
    ("POST", "/api/series"): _api_series,
    ("POST", "/api/export"): _api_export,
    ("POST", "/api/settings"): _api_settings,
    ("POST", "/api/symbols"): _api_symbols,
    ("POST", "/api/crawl"): _api_crawl,
}


def _dispatch(environ: dict) -> Response:
    method = environ.get("REQUEST_METHOD", "GET")
    path = environ.get("PATH_INFO", "") or ""

    # Passenger strips the mount prefix into SCRIPT_NAME, leaving PATH_INFO
    # exactly "" for a request to the bare mount point (no trailing slash).
    # Redirect to add the slash: every asset/API URL in the page is relative,
    # so it must resolve against a URL that actually ends in "/".
    if path == "":
        location = (environ.get("SCRIPT_NAME", "") or "") + "/"
        if environ.get("QUERY_STRING"):
            location += "?" + environ["QUERY_STRING"]
        return _redirect(location)

    if path in ("/", "/index.html"):
        if method != "GET":
            return _error("این آدرس وجود ندارد.", HTTPStatus.NOT_FOUND)
        return _serve_index(environ)

    if path == "/health":
        return _json({"ok": True, "app": APP_NAME, "version": __version__})

    if path == "/assets/fonts/vazirmatn.css":
        return _bytes(HTTPStatus.OK, font_css().encode("utf-8"), "text/css; charset=utf-8")

    if path.startswith("/assets/"):
        if method != "GET":
            return _error("این آدرس وجود ندارد.", HTTPStatus.NOT_FOUND)
        return _serve_static(path[len("/assets/"):])

    if path.startswith("/api/"):
        if not secrets.compare_digest(environ.get("HTTP_X_PC_TOKEN", ""), _TOKEN):
            return _error("توکن دسترسی نامعتبر است. صفحه را دوباره باز کنید.", HTTPStatus.FORBIDDEN)
        handler = _ROUTES.get((method, path))
        if handler is None:
            return _error("این آدرس وجود ندارد.", HTTPStatus.NOT_FOUND)
        return handler(environ)

    return _error("این آدرس وجود ندارد.", HTTPStatus.NOT_FOUND)


def application(environ: dict, start_response: Callable) -> Iterable[bytes]:
    try:
        status, headers, body = _dispatch(environ)
    except ApiError as exc:
        status, headers, body = _error(exc.message, HTTPStatus(exc.status))
    except Exception:  # pragma: no cover - defensive, mirrors server.py
        traceback.print_exc()
        status, headers, body = _error("خطای داخلی برنامه رخ داد.", HTTPStatus.INTERNAL_SERVER_ERROR)

    start_response(status, headers)
    return [body]
