# Code that exists only to carry old installs forward

Everything here is there so that an install made with an older version keeps working after an upgrade. None of it is needed by a fresh install. When upgrade support is dropped (a major version, on its own branch), this is the list to work through. **Rule: when you add code like this, add a line here in the same commit.** Line numbers are not given (the code is in `src/php`, `src/js` and `src/css` and moves); search for the name.

Decision still to make (the owner): is the next major version a clean break ("upgrade to the last 2.x first")? If yes, everything below goes. If some of it should stay for a while, tick it off the removal list and say why.

## Old "nothing found" marks and saved copies of file locations (2.2.x and earlier)
Older versions copied each photo's location from its file into the folder data and put a `gpsChecked` mark on photos where the file had none. Since 2.3.0 the files are read once into `.gallery-cache/meta1.php`.
- [ ] `gpsChecked`: `media_file_location()` (skips a photo that has one), `media_folder_summary()` (`legacy` count), `media_tidy_items()`, the editor's "read it now" check in `?admin` item loading, the "Read everything again" part that unsets it (`media_prepare_batch`, search `unset(... 'gpsChecked')`), report text about "old marks" (search `old "nothing found" mark`), and the `legacy` column of `media_report()`.
- [ ] **Tidy up**: `media_tidy_items()`, `media_tidy_run()`, the `media_tidy` admin action, the Tidy box and its script in `render_admin_thumbnails_panel()`, the `.gallery-items.before-tidy-<date>.php` backups it makes (and their cleanup), and the report hints about saved copies (`own`, `fromFile`).
- [ ] Tests: the `[tidy]` section of `tests/run.php` (fixtures `tidy-careful`, `tidy-refresh`, `tidy-nocache`), and the legacy-mark cases in `[prepare media]` and `[locations]`; the Tidy checks in `tests/tools/browser/browser_checks.py`.
- [ ] Docs: the *Tidy up saved locations* text in `docs/guide/notes-and-folders.md`, and the CHANGELOG entries (kept in history).

## Old names
- [ ] `warm_thumbnails`, the older name of the `prepare_media` admin action (search `'warm_thumbnails'`). Only an old open admin page or a script would still send it.
- [ ] `access_code` and `login_password`, the older names of the sign-in field (it is called `lens` since 2.6.5; `access_code` was its name before that, and `login_password` before that), still read by `login_form_password()` "for scripts, old bookmarks and pages already open" (search `login_password`). Check README before removing: someone's script may post one of them.
- [ ] `save_item` still accepts `loc_visibility` and `restriction` for "an older page, a script" (the switches now save themselves through `set_item_flag`).
- [ ] `LOG_PUBLIC_DAYS`, the 2.6.4 name of `LOG_KEEP_DAYS` (Keep visitor records for): the settings reader (`gallery_setting(null, ...)` in `src/php/001-settings-and-config.php`, the `$renamed` list) says in Health that the old line is now the new setting, instead of calling it a typo. The old value is not carried over (it would start deleting records after an upgrade); the line is ignored.

## Old password storage
- [ ] `upgrade_plaintext_passwords()` and its callers (sign-in and the first request), and the "plain text for a file not yet upgraded" branch of the password check (search `not yet upgraded`). Settings files from before passwords were hashed.

## Old view-count stores
- [ ] `stats_drop_old_counts()` (in `src/php/052-view-stats.php`) and its one call in `stats_connection()`: the one-time reset of 2.6.5. A folder's database made by an older version kept its counts in the table `photo_views_h`, keyed by a plain SHA-256 of the photo's path (which anyone who could fetch the file could test a guessed path against). That table is dropped, the file compacted, and counting starts again under the folder's own key (`.gallery-stats-key.php`). Delete the function, the call and its test (`[view counts] a database made by the version before ...`) once no install can still hold a `photo_views_h` table. The `DELETE ... 'carried:%'` line goes with it: the marks of the earlier-views button, which is gone.
- [ ] `stats_import_legacy()`: folds the plain-JSON store (`.gallery-stats.json`) and the old SQLite table `photo_views` (readable filenames) into the current `photo_views_k` (keyed by the folder's own key since 2.6.5), then deletes the old ones. Also its call in the stats connection code and its tests.

## Old visitor-download switch
- [ ] `ENABLE_PUBLIC_DOWNLOAD` (the on/off switch before `PUBLIC_DOWNLOAD_MODE`, 2.4.0): still read so an old line raises no notice; since 2.6.5 both it and `PUBLIC_DOWNLOAD_MODE: all` are read as off (fail closed; search `PUBLIC_DOWNLOAD_MODE` where it is defined), and `all` stays in the allowed values only so that it is read at all; the Settings page drops the old line when the new setting is saved. Remove the fallback and the constant once nobody has the old line.

## Old addresses
- [ ] `?admin&hidden=1` (redirects to `?admin#hidden`) and the tab key `hidden`, the name the Edited tab keeps from when it was the Hidden and then the Private tab. Links and bookmarks to `#hidden` still work; renaming the key to `edited` would break them.

## The gallery-wide Map switch (2.4.0)
- `ENABLE_MAP`: `gallery_setting('ENABLE_MAP', false);` is kept only so an older settings file's line raises no "isn't a setting" notice; its value is ignored (`ENABLE_MAP` is `ENABLE_PHOTO_LOCATION`), and `site_settings_save()` drops the line (`unset($cfg['ENABLE_MAP'])`). Remove both lines (and the `ENABLE_MAP` constant, replacing its uses with `ENABLE_PHOTO_LOCATION`) once no gallery is likely to have the key.

## Unkeyed cache file names (2.4.0)
- [ ] `gc_image_cache()` renames a made file found under its 2.3.2 name (`md5(path|variant|date)`) to its keyed name (`cache_name()`) instead of deleting it (search `LEGACY (see docs/LEGACY-REMOVAL.md)`). Once every gallery has run 2.4.0 for a while, delete the `$renamed` map and that branch. `cache_name()` itself keeps the plain-md5 fallback for an install with no secret yet (nobody has signed in; or a script folder the server cannot write to); keep it.

## Cookies on the whole domain (2.4.0)
- [ ] `gallery_cookie_expire()` ends every cookie on `/` as well as on the gallery's own path, for browsers that still hold a 2.3.2 cookie made with `path=/`. Once those have expired (the longest lives a year), make it end only `gallery_cookie_path()`.

## The 2.3.2 stay-signed-in token (2.4.0)
- [ ] `legacy_stay_path()`, `legacy_stay_take()`, `legacy_stay_forget()` and the `ADMIN_STAY_COOKIE_NAME` constant (search `LEGACY (see docs/LEGACY-REMOVAL.md): the "stay signed in"`): a browser that still holds the old cookie (`gallery_stay_…`, file `.gallery-stay.php`) is signed in by it once and given a token of the new kind. The longest such token lives 30 days, so a release or two after 2.4.0 the whole group can go; also the `.gallery-stay.php` entry in `owned_top_names()` and `.gitignore`, and the `[browsers]` test of it.

## Setup marker and address shapes, found in the 2.6.5 audit
- [ ] `.gallery-setup` (the empty file that opened first-run setup before 2.4.0): `@unlink(__DIR__ . '/.gallery-setup')` in the setup route (`051`) and its skip in the Files-made list (`032`). Kept only for installs that used the old marker; nothing reads the file.
- [ ] The 16-character random share-link address shape (`019`, the pattern `[A-Za-z0-9]{16}` beside the 8-character one): kept for links made before 2.6.5. New links are 8 characters with a capital.
- [ ] The `mtime` value of `PHOTO_SORT_ORDER` (`001`; the guide says "the older `mtime` still works").

## Not legacy (keep)
- The Health panel's "Upgraded from X on Y" record: a feature, not a compatibility shim.
- Orphan cleanup for deleted or renamed photos: ongoing housekeeping.
- `legacyCopy()` in the share and copy scripts: the always-allowed copy method that Safari needs; the name is only a name.

## How this list was made
By searching `index.php` for `gpsChecked`, `warm_thumbnails`, `login_password`, `legacy`, `older`, `upgrade`, `not yet`. It may miss something; when a removal pass starts, search again and add what turns up.

## Folder style words (2.5.0)
- [ ] `FOLDER_DISPLAY_MODE` values `cards` and `inline` (now `overview` and `flat`, with `inline` meaning Flat with `FOLDER_FLAT_NAMES` on): the allowed list in `001-settings-and-config.php`, the `FOLDER_FLAT_NAMES` default that follows `inline`, `folder_style_flat()`, and the line in the settings page that shows an older value as today's two choices (`036`, search `older words show as today's two choices`).

- **Older visitor logs (2.6.5).** `visitlog_old_leftovers()` and `visitlog_old_leftovers_remove()` (src/php/035), the Health row "Older visitor logs are still on disk" with its **Remove them** button (`data-oldlogs-remove`, `health_remove_old_logs` in 027) and the `oldlogs` kind in `owned_kind_of_name` (032) exist only for installs that ran a version before 2.6.5 (JSON logs in `.gallery-linklog-*.php` and the public address's stand-in record in `.gallery-links.php`). Delete them, and the `.gallery-linklog-*` names in the Files list, once no install still has those files.

- **The visitor log's version-2 to version-3 step (2.6.5).** `visitlog_open()` in src/php/034 adds the `sn` column (times a photo was on screen) to an existing `items` table when `PRAGMA user_version` is 2. Only a development build of 2.6.5 ever made a version-2 log (the log itself is new in 2.6.5), so once 2.6.5 is released and no such log is left, the `$hasSn` check and the `ALTER TABLE` can go, keeping `sn` in `visitlog_schema()`.

- **The plain capture-dates files (2.6.5).** `media_taken_times()` (src/php/025) removes `taken.json`, `taken2.json`, `taken3.json` (made by every release up to 2.6.4: plain JSON holding file names) and `taken4.json` (development branch only) from a `.gallery-cache` of this gallery, and `cache_entry_is_gallery_made()` (src/php/007) still matches `taken[0-9]*.json` so the thumbnail clean-up sweeps them too. Once upgrade support is dropped the `taken4.json` name can go first (no release made it); the rest with the other upgrade code. What those never reach (the cache off with the order by name; a place from elsewhere) is named by the Health row "old capture-date files" and removed by its button: `old_dates_names()`, `old_dates_leftovers()` and `old_dates_remove()` (src/php/008a), the row in `health_rows` (src/php/035), the `health_remove_old_dates` route (src/php/027) and the `data-olddates-remove` handler (src/js/046): all go with the rest. Tests: `[X10]` and `[N11]` in `tests/run.php` and the planted `taken*.json` in `[cache sweep]`.
