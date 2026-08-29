import os
import tempfile
import unittest
from pathlib import Path

from priceceawler.storage import Archive, Settings, read_json, write_json


class TestSettings(unittest.TestCase):
    def setUp(self):
        self.dir = Path(tempfile.mkdtemp(prefix="pc-settings-"))
        self.path = self.dir / "settings.json"

    def test_defaults_when_file_is_missing(self):
        settings = Settings(self.path)
        self.assertTrue(settings.get("auto_crawl"))
        self.assertIn("geram18", settings.get("symbols"))

    def test_updates_persist_and_unknown_keys_are_dropped(self):
        Settings(self.path).update({"theme": "dark", "junk": True})
        reloaded = Settings(self.path)
        self.assertEqual(reloaded.get("theme"), "dark")
        self.assertIsNone(reloaded.get("junk"))

    def test_corrupt_file_falls_back_to_defaults(self):
        self.path.write_text("{ not json", encoding="utf-8")
        self.assertEqual(Settings(self.path).get("theme"), "light")

    def test_write_json_is_atomic(self):
        target = self.dir / "nested" / "data.json"
        write_json(target, {"a": "ب"})
        self.assertEqual(read_json(target), {"a": "ب"})
        self.assertFalse(list(target.parent.glob("*.tmp")))


class TestArchive(unittest.TestCase):
    def setUp(self):
        self.archive = Archive(Path(tempfile.mkdtemp(prefix="pc-archive-")))

    def test_merge_counts_only_new_days(self):
        rows = [{"date": "1404/01/01", "close": 1}, {"date": "1404/01/02", "close": 2}]
        self.assertEqual(self.archive.merge("geram18", rows), 2)
        self.assertEqual(self.archive.merge("geram18", rows), 0)
        self.assertEqual(self.archive.merge("geram18", [{"date": "1404/01/03", "close": 3}]), 1)

    def test_merge_overwrites_an_existing_day(self):
        self.archive.merge("geram18", [{"date": "1404/01/01", "close": 1}])
        self.archive.merge("geram18", [{"date": "1404/01/01", "close": 9}])
        self.assertEqual(self.archive.load("geram18")["1404/01/01"]["close"], 9)

    def test_unsafe_keys_do_not_escape_the_archive_directory(self):
        self.archive.merge("../../evil", [{"date": "1404/01/01", "close": 1}])
        files = list(self.archive.dir.glob("*.json"))
        self.assertEqual(len(files), 1)
        self.assertNotIn("/", files[0].stem)

    def test_summary_reports_the_range(self):
        self.archive.merge("sekee", [{"date": "1404/01/02", "close": 2}, {"date": "1404/01/01", "close": 1}])
        summary = self.archive.summary()
        self.assertEqual(summary[0]["first"], "1404/01/01")
        self.assertEqual(summary[0]["last"], "1404/01/02")
        self.assertEqual(summary[0]["days"], 2)

    def test_rows_without_a_date_are_ignored(self):
        self.assertEqual(self.archive.merge("x", [{"close": 1}]), 0)


if __name__ == "__main__":
    unittest.main()
