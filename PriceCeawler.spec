# -*- mode: python ; coding: utf-8 -*-
"""PyInstaller recipe for the portable single-file Windows build.

Build with:  pyinstaller --clean --noconfirm PriceCeawler.spec
"""

import os
import sys

from PyInstaller.utils.hooks import collect_submodules

APP_NAME = "PriceCeawler"
ICON = os.path.join("assets", "icon.ico") if sys.platform == "win32" else None
VERSION_FILE = os.path.join("build", "file_version_info.txt")

# The whole web/ tree (HTML, CSS, JS, bundled Vazirmatn) ships inside the binary,
# so the app renders correctly with no internet connection.
datas = [("priceceawler/web", "priceceawler/web")]

hiddenimports = [
    # openpyxl loads this lazily; PyInstaller does not always follow it.
    "openpyxl.cell._writer",
    *collect_submodules("openpyxl.worksheet"),
]

# Nothing here needs scientific or GUI toolkits - dropping them roughly halves
# the size of the executable.
excludes = [
    "tkinter", "unittest", "pydoc", "doctest", "pdb", "test",
    "numpy", "pandas", "matplotlib", "scipy", "PIL", "PyQt5", "PySide6",
    "IPython", "notebook", "setuptools", "pip", "wheel",
]

a = Analysis(
    ["run.py"],
    pathex=[],
    binaries=[],
    datas=datas,
    hiddenimports=hiddenimports,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=excludes,
    noarchive=False,
)

pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name=APP_NAME,
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=True,          # the console prints the local URL and hosts `crawl` output
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=ICON,
    version=VERSION_FILE if os.path.exists(VERSION_FILE) else None,
)
