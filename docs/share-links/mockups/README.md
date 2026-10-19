# Share links mockups

The screens as agreed in the design conversation (2026-10-19). `png/` has one picture per board; the titles below match the design canvas the owner reviewed.

| File | Shows |
|---|---|
| `LinksList` | 1 · The tab: badge for new activity, Paused, share buttons, one link open |
| `LinksActivity` | 2 · What each link looked at: by folder, by photo, by IP |
| `LinksNew` | 3 · New link form; how it changes with the scope |
| `LinksShare` | 4 · Copy, Share and the QR code (small, large, phone) |
| `LinksVisitor` | 5 · What the link holder sees: opening page, invalid page, heads-up |
| `LinksEnded` | 6 · An ended link keeps its log; Delete asks first |
| `LinksPhone` | 7 · Phone |
| `LinksSettings` | 8 · Site settings |
| `LinksIP` / `LinksIPPhone` | 9, 10 · One IP address: Folders / Media / Timeline; all IPs |
| `LinksPreviewAs` | 11 · Preview as this link |
| `LinksReplace` | 12 · Replace the address of a link |
| `LinksShareFolder` | 13 · Share this folder |
| `LinksExport` | 14 · Export the log as CSV |
| `LinksMissing` | 15 · A chosen folder was renamed, moved or deleted |
| `LinksUnfurl` | 16 · What a message preview shows |
| `LinksPhotoShare` | 17 · The link button on private media |
| `LinksPhotoScope` | 18 · Share just this photo; the third scope |
| `LinksPhotoSelect` | 19 · Share a few photos from Select mode; what the holder sees |

`generators/` holds the Python that wrote the boards (`mk_links.py` … `mk_links5.py`, each reusing the previous), a small QR encoder (`qrmini.py`, byte mode, error correction H, versions 1–6, checked by reading a code back) and the gallery's icon used in the QR. They need `S` set to a scratch folder with `mock/project/` and `patch/` and `qr/icon.png` laid out as the scripts expect; the pictures were rendered with headless Chromium. They are a record, not part of the build.

Words in these mockups that changed later in the conversation are already updated (heads-up wording, the title option, one-row popup buttons, "seen" meaning on screen). Anything not matching [DECISIONS.md](../DECISIONS.md) is a mistake in the picture, not a decision.
