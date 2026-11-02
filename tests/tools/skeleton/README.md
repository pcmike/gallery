# Trying the delete on a stand-in of a real gallery

`Settings → Files this gallery has made → Delete…` removes every file the gallery made. It must never touch anything else, so before it is released it is tried on a copy of the *shape* of a real gallery.

## 1. On the server that holds the gallery (read-only)

```sh
sh make-skeleton.sh /path/to/gallery /path/to/mapped/folder1 /path/to/mapped/folder2 -o /somewhere/else
```

- The first folder is the one `index.php` is in. Then each place elsewhere on the server that **Settings → Server setup → Folders from elsewhere on the server** shows (the script prints what the settings file says, so you can check you gave them all).
- It only **reads** them. It writes one tarball, `gallery-skeleton-<date>.tar.gz`, to the folder after `-o` (default `/tmp`; it refuses a folder inside the gallery).
- The tarball holds: every file and folder as a line of type, size, date, mode and **name** (no contents of photos, videos or notes), and a copy of the gallery's own files (`.gallery-*`, and the data file in each `.gallery-cache`).
- Because the copy includes the password hashes, the install secret, share-link addresses and visitor IP addresses, **keep it private**; delete it when the test is done.

Works with `sh` (dash, busybox/Alpine) and needs `find`, `stat` (GNU or busybox), `tar`, `gzip`, `cp`, `mktemp`.

## 2. On the test machine

```sh
python3 -I tests/tools/skeleton/build_fixture.py gallery-skeleton-<date>.tar.gz
```

It rebuilds the same folders and file names with small stand-in files (a tiny valid JPEG for pictures), puts the gallery's own files back, drops this repository's `index.php` in, and points the mapped folders at the rebuilt places. Then it:

1. takes a checksum of everything;
2. builds the plan with the gallery's own code, makes the ZIP from it (thumbnails included) and runs the delete;
3. checks that every file that is not on the plan is byte for byte the same, that only planned files went, and that no link was followed;
4. unzips the copy over the result and checks the whole tree is byte for byte what it was.

It prints `RESULT: PASSED` or says what differed. `--keep` leaves the scratch folder for a look.

It stands in for what the page does after the gates (password, the typed word, the list check); those are tested in `tests/run.php` (`[files made]`).
