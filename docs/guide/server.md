# The server: locking down files, troubleshooting, security

Server rules for nginx and Apache, what to check when something is wrong, and the security notes.

← [Guide index](README.md) · [Project README](../../README.md)

## Locking down the files

Everything above governs requests that go through `index.php`. **A photo is still an ordinary static file** that the web server serves to anyone who knows its address (`yoursite.com/kb/IMG_0412.jpg`), and sequential filenames show where something is missing. Only a server rule stops that. The admin's Edited tab (at the top, while private is not locked) has these rules written for your gallery's address, with Copy buttons.

**nginx** (strongest; needs shell access). Add inside the `server { }` block, first location above the second, for a gallery at `/kb/`:

```nginx
# Generated data files: never public (a second layer; the .php ones also answer 404).
location ~ /\.gallery- {
    return 404;
}

# The photo and video files themselves: reachable only through index.php.
location ~* ^/kb/.*\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$ {
    internal;
}
```

Then `sudo nginx -t && sudo systemctl reload nginx` and check: `curl -I https://yoursite.com/kb/IMG_0412.jpg` should answer 404. The rules assume the usual `index index.php;` and a `location ~ \.php$` for php-fpm. Finally set **`NGINX_ACCEL_REDIRECT_PREFIX`** to `/kb`, matching the location: the gallery then answers with `X-Accel-Redirect` and nginx sends the file itself, which is faster and gives videos nginx's own Range handling. Leave it empty and PHP sends files (correct everywhere, just slower). The setting's row in Settings → Server setup says whether it works (a test photo is asked for through it), shows the same location under **Show the rule** with Copy, and says *Doesn't apply here* when every photo comes from folders elsewhere on the server.

nginx can only send files under the gallery's own folder, so photos from [Archive elsewhere](getting-started.md#archive-elsewhere) are always sent by the gallery, with the setting on or off (they have no address of their own on the web server, so there is nothing to lock for them).

**Apache** (blunter; works on FTP-only hosting). Save as `.htaccess` in the gallery's folder:

```apache
# The photo and video files themselves. (?i): camera files are often IMG_0412.JPG.
<FilesMatch "(?i)\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$">
    Require all denied
</FilesMatch>

# Generated data files (a second layer).
<FilesMatch "^\.gallery-">
    Require all denied
</FilesMatch>
```

A bare photo address then answers 403 while the gallery works as before. **It depends on `AllowOverride`**: if the server doesn't read `.htaccess` there, the rules are silently ignored, so check with `curl -I` (expect 403). The data files stay safe regardless (their own guard; the visitor log, a SQLite file, has a random name in place of a guard, and the Health check asks from your browser whether it can be fetched, so a server without the second rule says so). A script that logs in with an `Authorization` header on PHP-FPM or CGI also needs `CGIPassAuth On` (Apache 2.4.13+); the sign-in form needs nothing.

**The admin checks for you.** Each time it opens, your browser (not the server) asks, without signing in, for one photo and one cached thumbnail by their plain addresses (HEAD requests, nothing downloaded) and for a folder by its tidy address. If both pictures are refused (403, 404, 410), the red "Private isn't locked" warning turns into a green note and the rules are tucked away (*Show the rules* brings them back); if one is handed out, the warning stays and names it. If the tidy address opens the folder, Settings says tidy links work; otherwise it says they're not set up (optional). If `internal` is in place but the redirect setting is empty it says so, with a button to set it; if it's set but doesn't work it says that in red. Nothing is hidden when a check can't be made. Photos from elsewhere on the server can't be tested this way, and the checks say "not needed". Each check covers only the address your browser uses. Nothing renames your files.

## Troubleshooting

- **Something in a notes file didn't work** (a misspelt file in `{photos:}`, a duplicate claim, a missing `{gallery}`, a directive cut off by a blank line): it is excluded rather than erroring, and a collapsed "⚠ N setup notices for the gallery owner" banner says what (also as a comment in the page source). Once an admin password exists only the signed-in owner gets it.
- **A folder from elsewhere shows nothing:** the setup notices say when its path is missing or unreadable, and the Health check names it.
- **The password form keeps appearing after the right password:** the browser isn't keeping the login cookie. Check cookies aren't blocked and you're on HTTPS (the cookie is `Secure`).
- **"Nothing was saved: a settings file in this folder can't be read"** means a folder's `.gallery-items.php` was cut short or damaged (a full disk, a half-finished copy, a typo in a hand edit). The gallery **hides that whole folder from visitors** until you fix it (you still see everything, with a notice naming the folder), and saves nothing over it, so the file can still be repaired or restored from a backup. Put a good copy back, or delete the file to start that folder fresh (its private flags are then gone, so mark them again). The Health check lists such folders.
- **"Nothing was saved: this folder isn't writable"** means PHP can't write there, the most common hosting snag. Three things write to folders: saved captions and settings, view counts and the thumbnail cache. The gallery still works without them (the cache falls back to originals). Give the web server's user write permission on the folder, including any [extra folder](getting-started.md#archive-elsewhere) you edit. It never says "Saved" for something it couldn't write.
- **Not sure what's wrong with an install:** open the [Health check](admin.md#health-check).

## Security notes

**What it does**
- Everything it writes is private (guarded data files, [above](admin.md#data-files-are-guarded)). Output is HTML-escaped everywhere, and a request can only *select* among files the script found itself, never name a path (`..`, symlinks, wrong-case spellings and NUL bytes are refused).
- **Logins:** a form with a constant-time compare and a fresh session id; HTTPS-only admin and private view (except `localhost`); a throttle (five wrong access codes, or setup codes, in 15 minutes pause that address for a minute; the page says so, counts the seconds down and returns to the sign-in by itself; kept small: addresses with no lockout and no recent failure are forgotten, and an IPv6 visitor counts by their /64); CSRF tokens on every write; a stale-edit check; an `HttpOnly`, `SameSite=Strict`, uniquely named session cookie. **Every cookie is kept to the gallery's own folder** (`/trip` for a gallery in `/trip/`), so other applications on the same domain are never sent them.
- **Saves are whole-file.** A data file is replaced by a complete new one, so a visitor reading while you save never sees it empty or half-written; a file that cannot be read is treated as private, never as "nothing set", and is never overwritten (see Troubleshooting). Thumbnail and clip file names are a keyed hash, so they can't be worked out from a photo's name and date. The map code (Leaflet, from cdnjs) is loaded with an integrity hash. PHP's version is not sent and errors are logged, not printed.
- Admin and private-view pages are `no-store`, not frameable, and can't post forms elsewhere; every response carries `nosniff`.
- **Share links** are 8 random letters and digits (16 before 2.6.5; both work), compared in constant time; the sign-in they give is bound to the link and checked against its record on every request; wrong addresses are throttled; every link that doesn't work looks the same; and a person using one can't see, or be told about, what it doesn't cover. Hidden locations are removed from what a link hands out.
- Private items stay out of shared caches (`private, no-store`); ordinary photos stay cacheable, and a replaced photo gets a new `&v=` tag so browsers fetch it.
- The Host header is used in absolute URLs only if it looks like a real host name.

**Limits**
- A web server hands out any file it can see: private needs the [server rules](#locking-down-the-files).
- Original photos keep their GPS except in copies sent to signed-out visitors (and people using a share link) where you hide the location.
- **A share link is a bearer secret:** whoever has the address can use it until it ends. Behind Cloudflare or another proxy, set `TRUSTED_PROXY` so a link's log and the throttle see the real visitor.
- A short password can be guessed from its hash.
- **Behind a reverse proxy the script believes what the server tells PHP** (`REMOTE_ADDR`, `HTTPS`, port 443, `X-Forwarded-Proto`). With php-fpm that is right. With `proxy_pass` to PHP on the same machine every visitor looks like `127.0.0.1`, so the HTTPS requirement protects nothing and the throttle is shared: pass the real address and protocol, or keep PHP behind FastCGI.
- Images and Markdown are processed from folders **you** control; it isn't built to accept uploads from strangers.

Found a security problem? Open a GitHub issue, or contact the author privately if it shouldn't be public yet.
