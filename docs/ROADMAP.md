# Roadmap

Ideas that could be built but have no date. Nothing here is promised; it is kept so the reasoning isn't lost. Shipped work is in the [changelog](../CHANGELOG.md).

## Embedding a share link in another website

Let a link be shown inside a frame on someone else's site (a portfolio, say).

- **Today:** not possible. A link's opening page is sent with `frame-ancestors 'none'`, the link's cookie is `SameSite=Lax` (so it isn't sent from inside another site's frame), and Safari and Firefox limit cookies in frames. The admin and its pages must stay unframeable.
- **Sketch:** a per-link "Allow embedding on: example.com" that lets exactly those sites frame that link's pages; a `SameSite=None; Secure; Partitioned` cookie for those links (HTTPS only; Chrome and Edge, mostly Firefox, Safari unreliable, so Safari visitors would be offered "open in a new tab"); and a plain embedded view (no header, an "Open full size" link).
- **Note:** an open gallery's folder page isn't blocked from framing by the script, and needs no cookie.

## Cards or Flat per share link

Let each share link choose how its folders are shown, instead of always following the gallery's own Cards or Flat setting.

- **Today:** a link shows its folders the way the gallery does. A link limited to some folders and photos already acts as a hybrid, so the most common need is covered.
- **Sketch:** a per-link "Show folders as: Cards / Flat / Same as the gallery" in the link form, with the choice kept on the link and applied to its pages and its preview.
- **Note:** held back for now because the link form is already long and few people would change it. Worth building if people ask for one link to look different from the gallery.


## The backup noticing changed records, not only removed ones

Make the backup's "this part lost a lot" check look inside records, so a bulk edit is caught as well as a bulk delete.

- **Today:** the weekly backup keeps the earlier copy of a list (share links, collections, a folder's captions file) when at least 3 records, and at least a quarter of what the backup held, have gone from the live file. It looks only at which records are still there. A caption emptied or a private mark flipped changes a record without removing it, so it is not caught, and the changed copy replaces the old one.
- **Sketch:** compare the records field by field (for captions, the caption, the location and the private mark), and hold the part when many of them changed the same way, with the same Health row and one-click confirm as for removed records.
- **Note:** held back because it needs a rule for what counts as "many" for each field, and an ordinary bulk edit (Tidy up, a long editing session) would trip it often. Worth revisiting once the removed-records check has been in use for a while and the owner knows how often it speaks up.

## Showing a photo's own information (its metadata) in the viewer

Let a visitor open an "info" panel in the viewer with what the photo file itself says, not only the owner's caption: the camera or phone (make and model), the lens, the camera settings (focal length, aperture, shutter speed, ISO, flash, exposure mode, white balance), when it was taken (with the time zone a phone records), the picture's size, and perhaps the place and the editing software.

- **Today:** the gallery reads only a little from a photo's EXIF: the taken time (to order by date and to suggest a folder's Dates), the GPS position (to offer a map pin) and, as far as I checked, little else. It shows the owner's caption, link and place and nothing else from the file.
- **Sketch:** a small panel in the viewer, per photo, drawn from the photo's EXIF (and a video's container tags), with the owner choosing per folder, or for the whole gallery, which groups are shown (for example "camera and settings" on, "software" off). The position stays under the existing location switches, and the file's own position is never shown unless they allow it. Things that identify a person or a device are never shown: the camera's serial number, the owner or artist name, the copyright text, the unique image id and the maker's notes. Stripped copies the gallery sends (Keep the location in files visitors get) would keep following the same rule.
- **Note:** not built. The owner wants it kept in mind while the taken-time reading is fixed (group 14), so that fix keeps the time zone offset and does not throw EXIF away: ordering uses the clock time the camera wrote, and the reader stays general enough to hand the other tags on later.
