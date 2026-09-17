#!/bin/bash
set -euo pipefail

if (( $# < 1 || $# > 2 )); then
  echo "Usage: $0 <package.txz> [repo_root]" >&2
  exit 1
fi

PKG_PATH="$1"
ROOT_DIR="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
SRC_DIR="$ROOT_DIR/source"

if [[ ! -f "$PKG_PATH" ]]; then
  echo "Missing package: $PKG_PATH" >&2
  exit 1
fi

if [[ ! -d "$SRC_DIR" ]]; then
  echo "Missing source directory: $SRC_DIR" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

SRC_LIST="$TMP_DIR/source.list"
PKG_LIST="$TMP_DIR/package.list"
MISSING_LIST="$TMP_DIR/missing.list"
EXTRA_LIST="$TMP_DIR/extra.list"

find "$SRC_DIR" -mindepth 1 \( -type f -o -type l \) -print \
  | sed "s#^$SRC_DIR/##" \
  | LC_ALL=C sort > "$SRC_LIST"

tar -tJf "$PKG_PATH" \
  | sed 's#^\./##' \
  | sed '/\/$/d' \
  | sed '/^$/d' \
  | LC_ALL=C sort > "$PKG_LIST"

comm -23 "$SRC_LIST" "$PKG_LIST" > "$MISSING_LIST" || true
comm -13 "$SRC_LIST" "$PKG_LIST" > "$EXTRA_LIST" || true

if [[ -s "$MISSING_LIST" ]]; then
  echo "Release package is missing source files:" >&2
  cat "$MISSING_LIST" >&2
  exit 1
fi

if [[ -s "$EXTRA_LIST" ]]; then
  echo "Release package contains unexpected files:" >&2
  cat "$EXTRA_LIST" >&2
  exit 1
fi

if tar -tJf "$PKG_PATH" | grep -E '(^|/)(\._[^/]*|\.DS_Store|\.AppleDouble)(/|$)' >/dev/null 2>&1; then
  echo "Release package contains macOS metadata files." >&2
  exit 1
fi

# A standalone release must never install or operate through the original identity.
if grep -E 'zfs[._-]autosnapshot|ZFSAutoSnapshot' "$PKG_LIST" >/dev/null; then
  echo "Release package contains original-plugin installation paths." >&2
  exit 1
fi
if grep -RIE 'zfs[._-]autosnapshot|ZFSAutoSnapshot' "$SRC_DIR" >/dev/null; then
  echo "Source still references original-plugin runtime or installation identity." >&2
  exit 1
fi
TEMPLATE="$ROOT_DIR/zfs.snapsync.plg.in"
if [[ ! -f "$TEMPLATE" ]] || ! grep -F 'name="zfs.snapsync"' "$TEMPLATE" >/dev/null \
  || ! grep -F 'pluginURL="__BASE_URL__/zfs.snapsync.plg"' "$TEMPLATE" >/dev/null; then
  echo "Standalone manifest identity and update URL must match ZFS SnapSync." >&2
  exit 1
fi

echo "Verified release package contents against source tree: $PKG_PATH"
