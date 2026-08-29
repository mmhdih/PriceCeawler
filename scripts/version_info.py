#!/usr/bin/env python3
"""Generate the Windows VERSIONINFO resource embedded in the executable.

Run before PyInstaller:  python scripts/version_info.py
"""

from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from priceceawler.__main__ import configure_console  # noqa: E402
from priceceawler.version import APP_NAME, __version__  # noqa: E402

TEMPLATE = """VSVersionInfo(
  ffi=FixedFileInfo(
    filevers=({major}, {minor}, {patch}, 0),
    prodvers=({major}, {minor}, {patch}, 0),
    mask=0x3f, flags=0x0, OS=0x40004, fileType=0x1, subtype=0x0,
    date=(0, 0)
  ),
  kids=[
    StringFileInfo([
      StringTable('040904B0', [
        StringStruct('CompanyName', '{app}'),
        StringStruct('FileDescription', 'PriceCeawler - daily TGJU price crawler'),
        StringStruct('FileVersion', '{version}'),
        StringStruct('InternalName', '{app}'),
        StringStruct('LegalCopyright', 'MIT License'),
        StringStruct('OriginalFilename', '{app}.exe'),
        StringStruct('ProductName', '{app}'),
        StringStruct('ProductVersion', '{version}')
      ])
    ]),
    VarFileInfo([VarStruct('Translation', [1033, 1200])])
  ]
)
"""


def main() -> int:
    configure_console()  # a Windows console cannot encode ✔/✖ on cp1252
    match = re.match(r"^(\d+)\.(\d+)\.(\d+)", __version__)
    if not match:
        print(f"✖ نسخه نامعتبر است: {__version__}", file=sys.stderr)
        return 1
    major, minor, patch = (int(part) for part in match.groups())

    target = ROOT / "build" / "file_version_info.txt"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(
        TEMPLATE.format(major=major, minor=minor, patch=patch, version=__version__, app=APP_NAME),
        encoding="utf-8",
    )
    print(f"✔ {target} ({APP_NAME} {__version__})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
