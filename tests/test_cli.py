import io
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

os.environ.setdefault("PRICECEAWLER_DATA_DIR", tempfile.mkdtemp(prefix="pc-cli-"))

from priceceawler.__main__ import build_parser, configure_console, main  # noqa: E402


class LegacyConsole(io.TextIOWrapper):
    """Mimics a Windows console bound to cp1252, which cannot encode Persian."""

    def __init__(self):
        super().__init__(io.BytesIO(), encoding="cp1252", errors="strict")


class TestConsoleEncoding(unittest.TestCase):
    def test_persian_output_would_fail_on_a_legacy_console(self):
        console = LegacyConsole()
        with self.assertRaises(UnicodeEncodeError):
            console.write("کراولر قیمت")
            console.flush()

    def test_configure_console_makes_persian_output_safe(self):
        original = sys.stdout
        sys.stdout = LegacyConsole()
        try:
            configure_console()
            sys.stdout.write("کراولر قیمت — گزارش روزانه ✔")
            sys.stdout.flush()
        finally:
            sys.stdout = original

    def test_doctor_runs_on_a_legacy_console(self):
        """Regression: `doctor` crashed with UnicodeEncodeError on Windows."""
        original = sys.stdout
        sys.stdout = LegacyConsole()
        try:
            exit_code = main(["doctor", "--offline"])
        finally:
            sys.stdout = original
        self.assertEqual(exit_code, 0)


class TestBuildScripts(unittest.TestCase):
    """The release workflow runs these on a Windows runner using cp1252."""

    def test_version_info_script_runs_on_a_legacy_console(self):
        root = pathlib.Path(__file__).resolve().parent.parent
        result = subprocess.run(
            [sys.executable, str(root / "scripts" / "version_info.py")],
            cwd=root,
            env={**os.environ, "PYTHONIOENCODING": "cp1252"},
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, msg=result.stderr)
        self.assertTrue((root / "build" / "file_version_info.txt").is_file())


class TestParser(unittest.TestCase):
    def test_defaults(self):
        args = build_parser().parse_args([])
        self.assertEqual(args.host, "127.0.0.1")
        self.assertEqual(args.port, 8770)
        self.assertIsNone(args.command)

    def test_subcommands(self):
        parser = build_parser()
        self.assertEqual(parser.parse_args(["crawl", "--symbols", "geram18"]).symbols, ["geram18"])
        export = parser.parse_args(["export", "--format", "csv", "--start", "1404/01/01"])
        self.assertEqual(export.format, "csv")
        self.assertEqual(export.start, "1404/01/01")
        self.assertTrue(parser.parse_args(["doctor", "--offline"]).offline)

    def test_export_rejects_an_unknown_format(self):
        with self.assertRaises(SystemExit):
            build_parser().parse_args(["export", "--format", "pdf"])

    def test_export_reports_an_invalid_date(self):
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            code = main(["export", "--symbols", "geram18", "--start", "not-a-date"])
        self.assertEqual(code, 2)
        self.assertIn("تاریخ", buffer.getvalue())


if __name__ == "__main__":
    unittest.main()
