"""Serve the Vazirmatn webfont.

The build step drops ``.woff2`` files into ``web/assets/fonts`` so the packaged
executable renders correctly with no internet connection. When those files are
missing (a plain ``git clone``), the CSS falls back to the jsDelivr CDN.
"""

from __future__ import annotations

from pathlib import Path

__all__ = ["font_css", "FONT_DIR", "WEIGHTS", "CDN_BASE"]

FONT_DIR = Path(__file__).resolve().parent / "web" / "assets" / "fonts"
CDN_BASE = "https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/fonts/webfonts"

# (css weight, file stem) - Vazirmatn ships one file per weight.
WEIGHTS: tuple[tuple[int, str], ...] = (
    (300, "Vazirmatn-Light"),
    (400, "Vazirmatn-Regular"),
    (500, "Vazirmatn-Medium"),
    (600, "Vazirmatn-SemiBold"),
    (700, "Vazirmatn-Bold"),
)

_FACE = """@font-face {{
  font-family: 'Vazirmatn';
  font-style: normal;
  font-weight: {weight};
  font-display: swap;
  src: url('{src}') format('woff2');
}}"""


def font_css() -> str:
    """Return @font-face rules pointing at bundled files, or at the CDN."""
    bundled = FONT_DIR.is_dir()
    faces = []
    for weight, stem in WEIGHTS:
        local = FONT_DIR / f"{stem}.woff2"
        if bundled and local.is_file():
            src = f"/assets/fonts/{stem}.woff2"
        else:
            src = f"{CDN_BASE}/{stem}.woff2"
        faces.append(_FACE.format(weight=weight, src=src))
    return "/* Vazirmatn - SIL Open Font License 1.1 */\n" + "\n".join(faces) + "\n"
