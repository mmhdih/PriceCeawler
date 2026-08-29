#!/usr/bin/env python3
"""Launcher used both for local development and as the PyInstaller entry point."""

import multiprocessing
import sys

from priceceawler.__main__ import main

if __name__ == "__main__":
    multiprocessing.freeze_support()  # لازم برای اجرای پکیج‌شده روی ویندوز
    sys.exit(main())
