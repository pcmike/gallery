#!/bin/sh
# make-skeleton.sh: a "skeleton" of a gallery, for testing the delete safely.
#
# What it does (it only READS your gallery and your mapped folders):
#   - lists every file and folder: type, size, date, mode and NAME (no contents), so the shape can be rebuilt with stand-in files;
#   - copies the files the gallery itself made (.gallery-*, and the one data file in each .gallery-cache folder);
#   - writes ONE tarball, outside your gallery. Nothing inside your gallery or your mapped folders is changed, created or removed.
#
# What it does NOT include: the contents of your photos, videos or .md files (their names, sizes and dates only), or thumbnails
# (names, sizes and dates only).
#
# What is in it, so you can decide who sees it: file NAMES (folder and photo names), and the gallery's own files, which hold the
# admin and private-view password HASHES, the install secret, share-link addresses and visitor IP addresses. Keep it private.
#
# Usage:   sh make-skeleton.sh GALLERY_DIR [MAPPED_FOLDER ...] [-o OUTPUT_DIR]
#   GALLERY_DIR    the folder index.php is in
#   MAPPED_FOLDER  each place elsewhere on the server that Settings > Server setup > "Folders from elsewhere" shows (give them all)
#   -o OUTPUT_DIR  where the tarball goes (default: /tmp); it must not be inside the gallery or a mapped folder
# Needs only a POSIX shell, find, stat (GNU or busybox), tar, gzip, cp, mktemp. Works on Alpine.

set -u
umask 077

die() { echo "make-skeleton: $*" >&2; exit 1; }

out_dir=/tmp
roots=""
while [ $# -gt 0 ]; do
  case "$1" in
    -o) [ $# -ge 2 ] || die "-o needs a folder"; out_dir=$2; shift 2 ;;
    -h|--help) sed -n '2,26p' "$0"; exit 0 ;;
    -*) die "unknown option $1" ;;
    *) roots="$roots
$1"; shift ;;
  esac
done
[ -n "$roots" ] || die "give the gallery folder (the one index.php is in). Try: sh make-skeleton.sh --help"

# Real, absolute paths for every folder given.
n=0; list=""
old_ifs=$IFS; IFS='
'
for r in $roots; do
  [ -n "$r" ] || continue
  [ -d "$r" ] || die "not a folder: $r"
  real=$(cd "$r" && pwd -P) || die "cannot enter $r"
  n=$((n + 1))
  list="$list
$real"
done
IFS=$old_ifs
[ "$n" -ge 1 ] || die "no folder given"

first=$(printf '%s\n' "$list" | sed -n '2p')
[ -f "$first/index.php" ] || echo "note: $first has no index.php; is that the gallery folder?" >&2

[ -d "$out_dir" ] || die "output folder does not exist: $out_dir"
out_real=$(cd "$out_dir" && pwd -P) || die "cannot enter $out_dir"
printf '%s\n' "$list" | sed '/^$/d' | while IFS= read -r r; do
  case "$out_real/" in "$r"/*) echo "make-skeleton: the output folder $out_real is inside $r; choose another with -o" >&2; exit 1 ;; esac
done || exit 1

if [ -f "$first/.gallery-config.php" ]; then
  echo "Your Settings say (check you gave every mapped folder):"
  grep -n 'EXTRA_FOLDERS' "$first/.gallery-config.php" | cut -c1-300 || true
  echo
fi

stamp=$(date +%Y%m%d-%H%M%S)
stage=$(mktemp -d "${TMPDIR:-/tmp}/gallery-skeleton-stage.XXXXXX") || die "cannot make a working folder"
: > "$stage/.skeleton-stage"
cleanup() {
  # only ever removes the working folder this script made itself
  [ -n "$stage" ] && [ -f "$stage/.skeleton-stage" ] && rm -rf -- "$stage"
}
trap cleanup EXIT INT TERM

mkdir "$stage/ours" || die "cannot make $stage/ours"
: > "$stage/roots.txt"
total_ours=0
i=0
printf '%s\n' "$list" | sed '/^$/d' > "$stage/roots.list"
while IFS= read -r root; do
  printf 'root%s\t%s\n' "$i" "$root" >> "$stage/roots.txt"
  # everything: type|size|mtime|mode|path   (names and numbers only)
  find "$root" -exec stat -c '%F|%s|%Y|%a|%n' {} + > "$stage/manifest-root$i.txt" 2>"$stage/errors-root$i.txt"
  find "$root" -type l -exec sh -c 'for f; do printf "%s|%s\n" "$f" "$(readlink "$f")"; done' sh {} + > "$stage/symlinks-root$i.txt" 2>>"$stage/errors-root$i.txt"
  # the gallery's own files: copied with their dates
  find "$root" -type f \( -name '.gallery-*' -o -path '*/.gallery-cache/meta*.php' \) -print > "$stage/ours-root$i.list" 2>>"$stage/errors-root$i.txt"
  while IFS= read -r f; do
    rel=${f#"$root"/}
    d=$(dirname "$rel")
    mkdir -p "$stage/ours/root$i/$d" && cp -p "$f" "$stage/ours/root$i/$rel"
  done < "$stage/ours-root$i.list"
  c=$(wc -l < "$stage/ours-root$i.list" | tr -d ' ')
  total_ours=$((total_ours + c))
  i=$((i + 1))
done < "$stage/roots.list"

{
  echo "made:      $stamp"
  echo "script:    make-skeleton.sh (read-only)"
  echo "system:    $(uname -a 2>/dev/null)"
  echo "folders:   $i"
  echo "our files: $total_ours"
  command -v php >/dev/null 2>&1 && php -v 2>/dev/null | head -1
} > "$stage/INFO.txt"

out="$out_real/gallery-skeleton-$stamp.tar.gz"
tar -czf "$out" -C "$stage" . || die "could not write $out"

echo "Done. Wrote: $out ($(wc -c < "$out" | tr -d ' ') bytes)"
echo "  folders listed: $i"
echo "  gallery files copied: $total_ours"
for e in "$stage"/errors-root*.txt; do
  [ -s "$e" ] && { echo "  some things could not be read (see errors-*.txt inside the tarball):"; head -3 "$e"; }
done
echo "Nothing in your gallery or mapped folders was changed."
echo "It holds password hashes, link addresses and visitor IPs. Keep it private."
exit 0
