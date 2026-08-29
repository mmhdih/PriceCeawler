"""Entry point: ``python -m priceceawler`` and the packaged executable."""

from __future__ import annotations

import argparse
import json
import socket
import sys
import threading
import webbrowser

from .crawler import Crawler
from .jalali import JalaliDate
from .report import build_series, to_csv, to_json, to_xlsx
from .server import create_server, range_presets
from .storage import Settings, data_dir
from .symbols import CATALOG
from .tgju import TgjuError, fetch_history
from .version import APP_NAME, APP_TITLE_FA, __version__

BANNER = f"""
  {APP_NAME} v{__version__} — {APP_TITLE_FA}
  گزارش روزانه قیمت طلا، سکه، ارز و رمزارز از TGJU
  ───────────────────────────────────────────────
"""


def configure_console() -> None:
    """Make Persian output survive a legacy Windows console.

    A Windows console starts on a legacy code page (cp1252/cp437), and Python
    binds stdout to it, so printing any Persian text raises UnicodeEncodeError
    and kills the app. Switch the console to UTF-8 and re-wrap the streams.
    """
    if sys.platform == "win32":
        try:
            import ctypes

            ctypes.windll.kernel32.SetConsoleOutputCP(65001)
            ctypes.windll.kernel32.SetConsoleCP(65001)
        except Exception:  # pragma: no cover - console may be redirected
            pass

    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError, OSError):  # pragma: no cover
            pass  # already UTF-8, detached, or not a text stream


def _port_is_free(host: str, port: int) -> bool:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
        probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            probe.bind((host, port))
            return True
        except OSError:
            return False


def run_gui(host: str, port: int, open_browser: bool) -> int:
    """Start the local web UI and block until the window/console is closed."""
    if port and not _port_is_free(host, port):
        print(f"⚠ پورت {port} اشغال است؛ یک پورت آزاد انتخاب می‌شود.")
        port = 0

    try:
        server = create_server(host, port)
    except OSError as exc:
        print(f"✖ اجرای سرور محلی ممکن نشد: {exc}")
        return 1

    url = server.url
    print(BANNER)
    print(f"  آدرس برنامه : {url}")
    print(f"  محل داده‌ها : {data_dir()}")
    print("  برای بستن برنامه، این پنجره را ببندید یا Ctrl+C بزنید.\n", flush=True)

    # کراول خودکار روزانه، بدون بلاک‌کردن باز شدن رابط کاربری
    threading.Thread(target=_auto_crawl, args=(server.crawler,), daemon=True).start()

    if open_browser:
        threading.Timer(0.4, lambda: webbrowser.open(url)).start()

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nخداحافظ 👋")
    finally:
        server.server_close()
    return 0


def _auto_crawl(crawler: Crawler) -> None:
    try:
        result = crawler.maybe_daily_crawl()
    except Exception as exc:  # pragma: no cover - background best effort
        print(f"⚠ کراول خودکار انجام نشد: {exc}")
        return
    if result:
        added = sum(result["added"].values())
        print(f"✔ کراول خودکار {result['date']} انجام شد ({added} روز تازه).")


def run_crawl(symbols: list[str] | None) -> int:
    """Headless daily crawl - meant for Windows Task Scheduler / cron."""
    crawler = Crawler()
    result = crawler.daily_crawl(symbols)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 1 if result["errors"] and not result["added"] else 0


def run_export(args: argparse.Namespace) -> int:
    """Build a report from the command line without opening the UI."""
    settings = Settings()
    crawler = Crawler(settings)
    keys = args.symbols or settings.get("symbols") or []
    if not keys:
        print("✖ هیچ نمادی مشخص نشده است. از --symbols استفاده کنید.")
        return 2

    today = JalaliDate.today()
    default = next(p for p in range_presets(today) if p["id"] == "30")
    try:
        start = JalaliDate.parse(args.start or default["start"])
        end = JalaliDate.parse(args.end or default["end"])
    except ValueError as exc:
        print(f"✖ {exc}")
        return 2
    if start > end:
        print("✖ تاریخ شروع بعد از تاریخ پایان است.")
        return 2

    try:
        result = crawler.build(keys, start, end, fill_gaps=not args.no_fill)
    except TgjuError as exc:
        print(f"✖ {exc}")
        return 1

    for error in result.errors:
        print(f"⚠ {error['message']}")
    if not result.series:
        return 1

    fmt = args.format
    if fmt == "xlsx":
        payload = to_xlsx(result.series, start, end)
    elif fmt == "csv":
        payload = to_csv(result.series)
    else:
        payload = to_json(result.series, start, end)

    target = args.output
    if target is None:
        name = f"TGJU-{str(start).replace('/', '-')}_{str(end).replace('/', '-')}.{fmt}"
        target = str(data_dir() / "exports" / name)
    with open(target, "wb") as handle:
        handle.write(payload)
    print(f"✔ خروجی ساخته شد: {target}")
    return 0



def run_doctor(offline: bool) -> int:
    """Check that this build can write data, make Excel files and reach TGJU."""
    checks: list[tuple[str, bool, str]] = []

    try:
        directory = data_dir()
        probe = directory / ".doctor"
        probe.write_text("ok", encoding="utf-8")
        probe.unlink()
        checks.append(("پوشه داده‌ها قابل نوشتن است", True, str(directory)))
    except Exception as exc:
        checks.append(("پوشه داده‌ها قابل نوشتن است", False, str(exc)))

    try:
        today = JalaliDate.today()
        series = [build_series(CATALOG["geram18"], [], today, today)]
        size = len(to_xlsx(series, today, today))
        checks.append(("موتور ساخت فایل اکسل", size > 1000, f"{size} بایت آزمایشی"))
    except Exception as exc:
        checks.append(("موتور ساخت فایل اکسل", False, f"{type(exc).__name__}: {exc}"))

    if offline:
        checks.append(("اتصال به TGJU", True, "بررسی نشد (حالت آفلاین)"))
    else:
        try:
            points = fetch_history(CATALOG["geram18"], length=5, retries=1, timeout=20)
            checks.append(("اتصال به TGJU", bool(points), f"آخرین روز: {points[-1].date}"))
        except Exception as exc:
            checks.append(("اتصال به TGJU", False, str(exc)))

    print(BANNER)
    failed = 0
    for title, ok, detail in checks:
        if not ok:
            failed += 1
        print(f"  {'✔' if ok else '✖'} {title} — {detail}")
    print(flush=True)
    return 1 if failed else 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="priceceawler",
        description=f"{APP_NAME} — کراول روزانه قیمت‌ها از TGJU",
    )
    parser.add_argument("--version", action="version", version=f"{APP_NAME} {__version__}")
    parser.add_argument("--host", default="127.0.0.1", help="آدرس شنود سرور محلی")
    parser.add_argument("--port", type=int, default=8770, help="پورت سرور محلی (۰ = پورت آزاد)")
    parser.add_argument("--no-browser", action="store_true", help="مرورگر را باز نکن")

    subparsers = parser.add_subparsers(dest="command")

    crawl = subparsers.add_parser("crawl", help="کراول روزانه بدون رابط کاربری")
    crawl.add_argument("--symbols", nargs="*", help="فهرست شناسه نمادها")

    export = subparsers.add_parser("export", help="ساخت مستقیم فایل گزارش")
    export.add_argument("--symbols", nargs="*", help="فهرست شناسه نمادها")
    export.add_argument("--start", help="تاریخ شروع شمسی، مثلاً 1404/01/01")
    export.add_argument("--end", help="تاریخ پایان شمسی")
    export.add_argument("--format", choices=("xlsx", "csv", "json"), default="xlsx")
    export.add_argument("--output", help="مسیر فایل خروجی")
    export.add_argument("--no-fill", action="store_true", help="روزهای بدون معامله پر نشوند")

    doctor = subparsers.add_parser("doctor", help="بررسی سلامت برنامه و اتصال به TGJU")
    doctor.add_argument("--offline", action="store_true", help="اتصال شبکه بررسی نشود")
    return parser


def main(argv: list[str] | None = None) -> int:
    configure_console()
    args = build_parser().parse_args(argv)
    if args.command == "crawl":
        return run_crawl(args.symbols)
    if args.command == "export":
        return run_export(args)
    if args.command == "doctor":
        return run_doctor(args.offline)
    return run_gui(args.host, args.port, not args.no_browser)


if __name__ == "__main__":
    sys.exit(main())
