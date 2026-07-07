#!/usr/bin/env python3
"""
Extract Laravel translation keys from source and expand `lang/*.json`.
"""

from __future__ import annotations

from pathlib import Path
import json
import re


ROOT = Path(__file__).resolve().parents[1]
LANG_DIR = ROOT / "lang"
SCAN_DIRS = [ROOT / "app", ROOT / "resources", ROOT / "routes"]
EXTENSIONS = {".php", ".blade.php"}

PATTERNS = [
    re.compile(r"__\(\s*['\"]((?:\\.|[^'\"\\])+)['\"]\s*[\),]"),
    re.compile(r"@lang\(\s*['\"]((?:\\.|[^'\"\\])+)['\"]\s*[\),]"),
    re.compile(r"trans\(\s*['\"]((?:\\.|[^'\"\\])+)['\"]\s*[\),]"),
]


def read_json(path: Path) -> dict[str, str]:
    if not path.exists():
        return {}
    with path.open("r", encoding="utf-8-sig") as f:
        data = json.load(f)
    return {str(k): str(v) for k, v in data.items()}


def write_json(path: Path, data: dict[str, str]) -> None:
    ordered = dict(sorted(data.items(), key=lambda item: item[0].lower()))
    with path.open("w", encoding="utf-8", newline="\n") as f:
        json.dump(ordered, f, ensure_ascii=False, indent=2)
        f.write("\n")


def should_scan(path: Path) -> bool:
    name = path.name
    return path.suffix in EXTENSIONS or name.endswith(".blade.php")


# Only unescape the handful of sequences PHP single/double-quoted strings
# actually use inside these calls. `unicode_escape` looks tempting but is
# unsafe here: it treats the string as latin-1-with-escapes, so any real
# UTF-8 multibyte character (em dashes, Arabic, accents...) gets mangled
# byte-by-byte (found 2026-07-07 after em dashes in wrapped strings turned
# into "\x80\x94"-style mojibake in lang/*.json).
_ESCAPES = {
    "\\'": "'",
    '\\"': '"',
    "\\\\": "\\",
    "\\n": "\n",
    "\\t": "\t",
}


def _unescape(match: str) -> str:
    out = []
    i = 0
    while i < len(match):
        two = match[i:i + 2]
        if two in _ESCAPES:
            out.append(_ESCAPES[two])
            i += 2
        else:
            out.append(match[i])
            i += 1
    return "".join(out)


def extract_keys(text: str) -> set[str]:
    # No .strip(): Laravel's __() does an EXACT string lookup, so a harvested
    # key must byte-for-byte match what's inside the quotes — including any
    # deliberate leading/trailing space used to join a rendered number and a
    # unit, e.g. `number_format($x) . __(' tracked keywords')`. Stripping
    # here silently desyncs the harvested key from the runtime lookup key,
    # so the string always falls back to English regardless of locale
    # (found 2026-07-07 on pricing.blade.php's per-plan bullet builders).
    keys: set[str] = set()
    for pattern in PATTERNS:
        for match in pattern.findall(text):
            key = _unescape(match)
            if key.strip():
                keys.add(key)
    return keys


def collect_keys() -> set[str]:
    keys: set[str] = set()
    for base in SCAN_DIRS:
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if not path.is_file() or not should_scan(path):
                continue
            try:
                content = path.read_text(encoding="utf-8")
            except Exception:
                continue
            keys.update(extract_keys(content))
    return keys


def main() -> int:
    LANG_DIR.mkdir(parents=True, exist_ok=True)
    locale_files = sorted(LANG_DIR.glob("*.json"))
    if not locale_files:
        print("No locale JSON files found under lang/.")
        return 1

    keys = collect_keys()
    if not keys:
        print("No translation keys found from source scanning.")
        return 1

    updated_count = 0
    for locale_file in locale_files:
        data = read_json(locale_file)
        before = len(data)
        for key in keys:
            data.setdefault(key, key)
        after = len(data)
        if after != before:
            updated_count += 1
            print(f"Updated {locale_file.name}: +{after - before} keys")
        write_json(locale_file, data)

    print(f"Done. {len(keys)} extracted keys. {updated_count} locale files updated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
