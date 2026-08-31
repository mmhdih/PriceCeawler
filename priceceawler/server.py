"""A tiny local HTTP server that backs the Persian web UI."""

from __future__ import annotations

import json
import mimetypes
import re
import secrets
import threading
import traceback
import urllib.parse
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Callable

from .crawler import Crawler
from .fonts import font_css
from .jalali import JalaliDate
from .report import to_csv, to_json, to_xlsx
from .storage import Settings, data_dir
from .symbols import CATALOG
from .version import APP_NAME, APP_TITLE_FA, __version__

__all__ = ["AppServer", "create_server"]

WEB_DIR = Path(__file__).resolve().parent / "web"
MAX_BODY_BYTES = 1 << 20  # 1 MiB is far more than any request here needs
_SAFE_PATH = re.compile(r"^[A-Za-z0-9._/-]+$")


class ApiError(Exception):
    """An error with a Persian message meant for the user."""

    def __init__(self, message: str, status: int = HTTPStatus.BAD_REQUEST) -> None:
        super().__init__(message)
        self.message = message
        self.status = status


def _parse_range(payload: dict) -> tuple[JalaliDate, JalaliDate]:
    try:
        start = JalaliDate.parse(payload.get("start", ""))
        end = JalaliDate.parse(payload.get("end", ""))
    except ValueError as exc:
        raise ApiError(str(exc)) from exc
    if start > end:
        raise ApiError("تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.")
    if (end - start) > 3660:
        raise ApiError("بازه انتخابی بیش از ۱۰ سال است؛ آن را کوتاه‌تر کنید.")
    return start, end


def range_presets(today: JalaliDate | None = None) -> list[dict[str, str]]:
    """Quick date ranges offered in the sidebar."""
    today = today or JalaliDate.today()
    presets = [
        {"id": "7", "label": "۷ روز", "start": str(today.add_days(-6))},
        {"id": "30", "label": "۱ ماه", "start": str(today.add_days(-29))},
        {"id": "90", "label": "۳ ماه", "start": str(today.add_days(-89))},
        {"id": "180", "label": "۶ ماه", "start": str(today.add_days(-179))},
        {"id": "365", "label": "۱ سال", "start": str(today.add_days(-364))},
        {"id": "month", "label": "این ماه", "start": str(today.replace(day=1))},
        {"id": "year", "label": "از ابتدای سال", "start": str(today.replace(month=1, day=1))},
    ]
    for preset in presets:
        preset["end"] = str(today)
    return presets


def _symbol_keys(payload: dict) -> list[str]:
    keys = [str(k).strip() for k in payload.get("symbols", []) if str(k).strip()]
    if not keys:
        raise ApiError("حداقل یک نماد را انتخاب کنید.")
    if len(keys) > 20:
        raise ApiError("حداکثر ۲۰ نماد در هر گزارش پشتیبانی می‌شود.")
    return keys


class AppServer(ThreadingHTTPServer):
    """Holds the shared crawler/settings so handlers stay stateless."""

    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, address: tuple[str, int], handler, *, open_browser_token: str) -> None:
        super().__init__(address, handler)
        self.settings = Settings()
        self.crawler = Crawler(self.settings)
        self.token = open_browser_token
        self.should_stop = threading.Event()

    @property
    def url(self) -> str:
        host, port = self.server_address[0], self.server_address[1]
        return f"http://{host}:{port}/"


class Handler(BaseHTTPRequestHandler):
    server: AppServer  # type: ignore[assignment]
    server_version = f"{APP_NAME}/{__version__}"
    protocol_version = "HTTP/1.1"

    # -- plumbing --------------------------------------------------------
    def log_message(self, fmt: str, *args: Any) -> None:  # keep the console clean
        pass

    def _send(self, status: int, body: bytes, content_type: str, extra: dict[str, str] | None = None) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        for key, value in (extra or {}).items():
            self.send_header(key, value)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _send_json(self, payload: Any, status: int = HTTPStatus.OK) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self._send(status, body, "application/json; charset=utf-8")

    def _error(self, message: str, status: int = HTTPStatus.BAD_REQUEST) -> None:
        self._send_json({"ok": False, "error": message}, status)

    def _local_host_ok(self) -> bool:
        """Reject DNS-rebinding: the Host header must be a loopback address."""
        host = (self.headers.get("Host") or "").split(":")[0].strip("[]")
        return host in {"127.0.0.1", "localhost", "::1", ""}

    def _authorised(self) -> bool:
        return secrets.compare_digest(
            self.headers.get("X-PC-Token", ""), self.server.token
        )

    def _body(self) -> dict:
        try:
            length = int(self.headers.get("Content-Length") or 0)
        except ValueError:
            raise ApiError("طول درخواست نامعتبر است.")
        if length > MAX_BODY_BYTES:
            raise ApiError("حجم درخواست بیش از حد مجاز است.", HTTPStatus.REQUEST_ENTITY_TOO_LARGE)
        if length <= 0:
            return {}
        try:
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
        except (json.JSONDecodeError, UnicodeDecodeError) as exc:
            raise ApiError("بدنه درخواست JSON معتبر نیست.") from exc
        if not isinstance(payload, dict):
            raise ApiError("بدنه درخواست باید یک شیء JSON باشد.")
        return payload

    # -- routing ---------------------------------------------------------
    def do_GET(self) -> None:  # noqa: N802 - required by BaseHTTPRequestHandler
        self._dispatch("GET")

    def do_HEAD(self) -> None:  # noqa: N802
        self._dispatch("GET")

    def do_POST(self) -> None:  # noqa: N802
        self._dispatch("POST")

    def _dispatch(self, method: str) -> None:
        if not self._local_host_ok():
            self._error("درخواست فقط از روی همین رایانه پذیرفته می‌شود.", HTTPStatus.FORBIDDEN)
            return

        path = urllib.parse.urlparse(self.path).path
        try:
            if path.startswith("/api/"):
                if not self._authorised():
                    self._error("توکن دسترسی نامعتبر است. صفحه را دوباره باز کنید.", HTTPStatus.FORBIDDEN)
                    return
                handler = self._api_routes().get((method, path))
                if handler is None:
                    self._error("این آدرس وجود ندارد.", HTTPStatus.NOT_FOUND)
                    return
                handler()
                return

            if method != "GET":
                self._error("این آدرس وجود ندارد.", HTTPStatus.NOT_FOUND)
                return
            if path in ("/", "/index.html"):
                self._serve_index()
            elif path == "/health":
                self._send_json({"ok": True, "app": APP_NAME, "version": __version__})
            elif path == "/assets/fonts/vazirmatn.css":
                self._send(HTTPStatus.OK, font_css().encode("utf-8"), "text/css; charset=utf-8")
            elif path.startswith("/assets/"):
                self._serve_static(path[len("/assets/"):])
            else:
                self._error("این آدرس وجود ندارد.", HTTPStatus.NOT_FOUND)
        except ApiError as exc:
            self._error(exc.message, exc.status)
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception:  # pragma: no cover - defensive
            traceback.print_exc()
            self._error("خطای داخلی برنامه رخ داد.", HTTPStatus.INTERNAL_SERVER_ERROR)

    def _api_routes(self) -> dict[tuple[str, str], Callable[[], None]]:
        return {
            ("GET", "/api/meta"): self._api_meta,
            ("GET", "/api/archive"): self._api_archive,
            ("POST", "/api/series"): self._api_series,
            ("POST", "/api/export"): self._api_export,
            ("POST", "/api/settings"): self._api_settings,
            ("POST", "/api/symbols"): self._api_add_symbol,
            ("POST", "/api/crawl"): self._api_crawl,
            ("POST", "/api/shutdown"): self._api_shutdown,
        }

    # -- static ----------------------------------------------------------
    def _serve_index(self) -> None:
        index = WEB_DIR / "index.html"
        try:
            html = index.read_text(encoding="utf-8")
        except OSError:
            self._error("فایل رابط کاربری پیدا نشد.", HTTPStatus.INTERNAL_SERVER_ERROR)
            return
        html = html.replace("__APP_TOKEN__", self.server.token)
        html = html.replace("__APP_VERSION__", __version__)
        self._send(HTTPStatus.OK, html.encode("utf-8"), "text/html; charset=utf-8")

    def _serve_static(self, relative: str) -> None:
        if not relative or ".." in relative or not _SAFE_PATH.match(relative):
            self._error("مسیر نامعتبر است.", HTTPStatus.NOT_FOUND)
            return
        target = (WEB_DIR / "assets" / relative).resolve()
        assets_root = (WEB_DIR / "assets").resolve()
        if not target.is_file() or assets_root not in target.parents:
            self._error("فایل پیدا نشد.", HTTPStatus.NOT_FOUND)
            return
        content_type = mimetypes.guess_type(target.name)[0] or "application/octet-stream"
        if content_type.startswith("text/") or content_type in ("application/javascript",):
            content_type += "; charset=utf-8"
        self._send(HTTPStatus.OK, target.read_bytes(), content_type)

    # -- api -------------------------------------------------------------
    def _api_meta(self) -> None:
        crawler = self.server.crawler
        today = JalaliDate.today()
        self._send_json(
            {
                "ok": True,
                "app": APP_NAME,
                "titleFa": APP_TITLE_FA,
                "version": __version__,
                "today": str(today),
                "todayLong": f"{today.weekday_name} {today.day} {today.month_name} {today.year}",
                "dataDir": str(data_dir()),
                "presets": range_presets(today),
                "symbols": [s.to_dict() for s in crawler.known_symbols()],
                # The full built-in list, unaffected by disabled_symbols - the
                # Settings section needs this to let a disabled symbol be
                # re-enabled again (it no longer appears in "symbols" above).
                "catalog": [s.to_dict() for s in CATALOG.values()],
                "settings": self.server.settings.as_dict(),
                "archive": crawler.archive.summary(),
            }
        )

    def _api_archive(self) -> None:
        self._send_json({"ok": True, "archive": self.server.crawler.archive.summary()})

    def _api_series(self) -> None:
        payload = self._body()
        keys = _symbol_keys(payload)
        start, end = _parse_range(payload)
        result = self.server.crawler.build(
            keys, start, end,
            fill_gaps=bool(payload.get("fillGaps", True)),
            force=bool(payload.get("force", False)),
        )
        if not result.series and result.errors:
            self._send_json({"ok": False, "error": result.errors[0]["message"], **result.to_dict()})
            return
        self._send_json({"ok": True, "range": {"start": str(start), "end": str(end)}, **result.to_dict()})

    def _api_export(self) -> None:
        payload = self._body()
        keys = _symbol_keys(payload)
        start, end = _parse_range(payload)
        fmt = str(payload.get("format", "xlsx")).lower()
        if fmt not in {"xlsx", "csv", "json"}:
            raise ApiError("قالب خروجی پشتیبانی نمی‌شود.")

        result = self.server.crawler.build(
            keys, start, end, fill_gaps=bool(payload.get("fillGaps", True))
        )
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
        self._send(
            HTTPStatus.OK, body, content_type,
            {
                "Content-Disposition": f'attachment; filename="{name}"',
                "X-Export-Warnings": urllib.parse.quote(
                    json.dumps([e["message"] for e in result.errors], ensure_ascii=False)
                ),
            },
        )

    def _api_settings(self) -> None:
        payload = self._body()
        self._send_json({"ok": True, "settings": self.server.settings.update(payload)})

    def _api_add_symbol(self) -> None:
        payload = self._body()
        key = str(payload.get("key", "")).strip()
        if not key or not re.match(r"^[A-Za-z0-9_.-]{2,64}$", key):
            raise ApiError("شناسه نماد فقط می‌تواند شامل حروف انگلیسی، عدد، «-»، «_» و «.» باشد.")
        name = str(payload.get("name", "")).strip() or key
        group = str(payload.get("group", "")).strip() or None
        currency = "USD" if str(payload.get("currency", "IRR")).upper() == "USD" else "IRR"
        try:
            decimals = max(0, min(8, int(payload.get("decimals", 0) or 0)))
        except (TypeError, ValueError):
            decimals = 0

        customs = [c for c in (self.server.settings.get("custom_symbols") or []) if c.get("key") != key]
        customs.append({"key": key, "name": name, "group": group, "currency": currency, "decimals": decimals})
        self.server.settings.update({"custom_symbols": customs[-50:]})
        self._send_json(
            {"ok": True, "symbols": [s.to_dict() for s in self.server.crawler.known_symbols()]}
        )

    def _api_crawl(self) -> None:
        payload = self._body()
        keys = payload.get("symbols") or self.server.settings.get("symbols")
        self._send_json({"ok": True, **self.server.crawler.daily_crawl(keys)})

    def _api_shutdown(self) -> None:
        self._send_json({"ok": True})
        self.server.should_stop.set()
        threading.Thread(target=self.server.shutdown, daemon=True).start()


def create_server(host: str = "127.0.0.1", port: int = 0) -> AppServer:
    """Bind a server on ``port`` (0 picks a free port) with a fresh token."""
    return AppServer((host, port), Handler, open_browser_token=secrets.token_urlsafe(24))
