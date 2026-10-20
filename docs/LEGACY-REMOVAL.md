# Code that exists only to carry old installs forward

Everything here is there so that an install made with an older version keeps working after an upgrade. None of it is needed by a fresh install. When upgrade support is dropped (a major version, on its own branch, after 2.3.0 ships), this is the list to work through. **Rule: when you add code like this, add a line here in the same commit.** Line numbers are `index.php` at 2.3.0-dev and will drift; search for the name.

Decision still to make (the owner): is 2.3.0 → 3.0 a clean break ("upgrade to 2.3.x first")? If yes, everything below goes. If some of it should stay for a while, tick it off the removal list and say why.

## Old "nothing found" marks and saved copies of file locations (2.2.x and earlier)
Older versions copied each photo's location from its file into the folder data and put a `gpsChecked` mark on photos where the file had none. Since 2.3.0 the files are read once into `.gallery-cache/meta1.php`.
- [ ] `gpsChecked`: `media_file_location()` (skips a photo that has one), `media_folder_summary()` (`legacy` count), `media_tidy_items()`, the editor's "read it now" check in `?admin` item loading, the "Read everything again" part that unsets it (`media_prepare_batch`, search `unset(... 'gpsChecked')`), report text about "old marks" (search `old "nothing found" mark`), and the `legacy` column of `media_report()`.
- [ ] **Tidy up**: `media_tidy_items()`, `media_tidy_run()`, the `media_tidy` admin action, the Tidy box and its script in `render_admin_thumbnails_panel()`, the `.gallery-items.before-tidy-<date>.php` backups it makes (and their cleanup), and the report hints about saved copies (`own`, `fromFile`).
- [ ] Tests: the `[tidy]` section of `tests/run.php` (fixtures `tidy-careful`, `tidy-refresh`, `tidy-nocache`), and the legacy-mark cases in `[prepare media]` and `[locations]`; the Tidy checks in `tests/tools/browser/browser_checks.py`.
- [ ] Docs: the Tidy up bullets in README, CHANGELOG (kept in history) and `?about`.

## Old names
- [ ] `warm_thumbnails`, the older name of the `prepare_media` admin action (search `'warm_thumbnails'`). Only an old open admin page or a script would still send it.
- [ ] `login_password`, the older name of the sign-in field `access_code` (search `login_password`), "for scripts and older pages". Check README before removing: someone's script may post it.
- [ ] `save_item` still accepts `loc_visibility` and `restriction` for "an older page, a script" (the switches now save themselves through `set_item_flag`).

## Old password storage
- [ ] `upgrade_plaintext_passwords()` and its callers (sign-in and the first request), and the "plain text for a file not yet upgraded" branch of the password check (search `not yet upgraded`). Settings files from before passwords were hashed.

## Old view-count stores
- [ ] `stats_import_legacy()`: folds the plain-JSON store (`.gallery-stats.json`) and the old SQLite table `photo_views` (readable filenames) into the current `photo_views_h`, then deletes the old ones. Also its call in the stats connection code and its tests.

## Old addresses
- [ ] `?admin&hidden=1` (redirects to `?admin#hidden`) and the tab key `hidden`, the name the Edited tab keeps from when it was the Hidden and then the Private tab. Links and bookmarks to `#hidden` still work; renaming the key to `edited` would break them.

## Not legacy (keep)
- The Health panel's "Upgraded from X on Y" record: a feature, not a compatibility shim.
- Orphan cleanup for deleted or renamed photos: ongoing housekeeping.
- `legacyCopy()` in the share and copy scripts: the always-allowed copy method that Safari needs; the name is only a name.

## How this list was made
By searching `index.php` for `gpsChecked`, `warm_thumbnails`, `login_password`, `legacy`, `older`, `upgrade`, `not yet`. It may miss something; when a removal pass starts, search again and add what turns up.
