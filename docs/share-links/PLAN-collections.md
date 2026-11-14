# Plan: Collections, a virtual folder (to be folded into 2.6.5)

Started 2026-11-07 with the owner, revised the same day after their answers. NOT BUILT. Kept untracked (like PLAN-not-listed.md and BUILD-PROGRESS.md) so a git rollback never removes it.
Roadmap entry it grows from: docs/ROADMAP.md "Named collections". Decided by the owner: plan it properly and fold it into 2.6.5.

## 1. The model (the owner's words: "a collection is a virtual folder")
A **collection** is a folder that exists only in the gallery: it has no place on disk. It lives **inside a parent**: the gallery's front, or any folder at any depth (`/photos`, `/photos/France`, ...). Inside it are **members**: real folders and single photos from anywhere. Nothing moves on disk, and members stay where they are, in their own parents, always. A collection is another way to show things; it never takes anything away.

Example: a "Best of Paris" collection inside `France`, holding `Eurotrip/paris`, a Sunrises folder and three single photos. It shows as a card among France's folders, and (if ticked) on the front page too.

## 2. Settled with the owner
- **Any folder can hold collections**, at any depth. A collection in a folder other than the front has **Also show on the front page** (the owner's "Show at root?"), off to start with.
- **Members stay in their own places.** The earlier "hide its folders from the front page" switch is dropped. (Parked: a folder that is listed only through a collection would be a separate idea, a flag on the folder; see ROADMAP when written.)
- **Same options as a folder, where they make sense** (see 4; Private only, no Not listed). A collection card sorts among its siblings like folders do (position among siblings; ones without a position after those with, naturally).
- **The address is the collection's name, set by the admin, defaulting to the title.**
- **Gallery of its own works on a collection** (see 6).
- **Share links can include collections** (see 8): in the link's folder picker, collections show as virtual folders at the depth where they live.
- **Where it is managed** (see 9): it reuses the folder settings page; made from the parent's settings; a Collections tab for the overview; italics in the tree. The phone's bottom bar is not touched.

## 3. Who sees what
| Who | What they get |
|---|---|
| Stranger, gallery open | The collection's card in its parent (and on the front page if ticked) and its page, listing only the members they could already see. |
| A collection with nothing a visitor can see | No card, and its address answers as a missing page (same status and body). |
| Private-view password holder | What they could already see (private included), never Not listed. |
| Owner | Everything, with Private / Not listed pills and missing members marked. |
| Someone on a share link | Only collections their link includes (see 8), shown at their depth. |
| Gallery closed | Strangers see nothing, as for everything else. |

A collection **grants nothing and hides nothing**: it never makes a private photo public, and a Private collection hides only the collection's own page and card; its members are still public at their own addresses if they are public. Counts, covers, "last updated" and previews count only what that visitor can see (the Not listed lesson: no leak through a number).

## 4. What a collection has (a folder's options, where they make sense)
- **Applies:** title (display name), description, cover photo, dates (typed by the owner, as for a folder), **Private collection** switch (for the collection's own page and card only; no Not listed), **Gallery of its own**, **position among siblings**, the address, **Also show on the front page**, and the order of its own photos (same "Order of the photos here" as a folder).
- **Does not apply** (each member keeps its own): location switch, downloads, the scrub switch, notes (.md), view counts of the collection (members count their own).
- **Members**: folders and single photos, each remembered by the folder's hidden id (photos: hidden id + file name), as share links do, so renaming or moving a folder keeps it; renaming a photo or moving it to another folder drops it (shown as missing). Mapped places and EXTRA_ROOTS folders work the same.
- **Per member folder**: *With everything inside* (default) or *Only its own photos* (subfolders left out). A folder already covered by a picked ancestor is flagged redundant.
- **Order inside the collection**: folders (cards) first, then photos (tiles), as on a folder page, sorted by the gallery's folder and photo rules; a member's own "position among siblings" is about its real parent, so inside a collection the owner can also set a position for each member (like folders do), default natural.

## 5. Storage
A new guarded data file beside index.php, `.gallery-collections.php` (JSON behind the one-line 404 guard, like `.gallery-links.php`): `{collections: [{id, parent (a folder's hidden id, or '' for the front), name, title, description, cover, dates, privacy, ownGallery, front, position, items: [{fid, mode} | {fid, file}], at}]}`. Added to **Files this gallery has made** (list, Files ZIP, Delete, with the exact-name and guard-line rules) and to the Export. Folder hidden ids are stamped into a folder's own `.gallery-items.php` when it is first picked as a parent or member (as a share link does), so a mapped place must be writable. If the parent folder is gone the collection is listed in the overview as "its folder is missing" and is not shown to visitors.

## 6. Navigation (designed with Gallery of its own)
Today "the top" is a folder path (`nav_top`, cookie `gallery_top = '/' + path`, `Sec-Fetch-Site` for a fresh arrival). It becomes **a folder path or a collection**. What "Gallery of its own" means for a collection:
- Someone who arrives at the collection (typed or pasted address, chat link, bookmark) is offered **no way above it and no siblings beside it**, and the page is built without them.
- Someone who clicks from the collection into a member folder keeps a way back to the collection ("← Best of Paris"), and the folders beside that member are the collection's other members, not its disk neighbours.
- Someone who came in through the collection's parent and clicked in keeps their way back up, as for a folder.
- It is the same tidiness, not a lock; a typed address always works. The mark is kept with the collection record, and the gallery's own `ownGalleries` flag is set while any folder or collection uses it, so a gallery that uses none reads nothing more and sets no cookie.
- A fresh arrival at a member folder's own address gets that folder's normal navigation (or the nearest folder marked Gallery of its own).
- Pages that vary by cookie keep `Cache-Control: private` and `Vary: Cookie, Sec-Fetch-Site`. Admin and owner views are unaffected. Galleries with no collections behave byte-for-byte as before: the existing navigation tests pass unchanged.

## 7. Address
Like a folder's: the parent's path plus the collection's name, so `?folder=France/best-of-paris` (and the tidy `/photos/France/best-of-paris` where the server rule that folder addresses use is in place; the Health check already reports it). **To check before building:** that handing a virtual path to `?folder=` does not disturb code that assumes a folder is on disk (`?archive`, `?img`, the ZIP, the cache); if it does, the fallback is `?collection=<parent>/<name>` and the tidy form maps to it. A name is checked like a link name: shape, reserved words, and refused if it matches a real folder or file in the same parent, a link name, or another collection there (and the editor says so if a real folder with that name appears later: the real folder wins and the collection shows "address taken").

## 8. Share links and collections
- In the link form's folder picker, collections appear **as virtual folders**, with their own icon, **at the depth where they live**, beside the real folders. Picking one makes the link cover **the collection's page and its members** (each member as the collection defines it), with the link's own *Everything* / *Public items only* choice applying to all of them.
- A link visitor sees the collection as a card at its depth, and its page.
- The link's opening page counts every item the link opens, collections' members included, accurately (no under-reporting).
- **Decided (owner, 2026-11-07): dynamic.** A link naming a collection follows the collection as it changes (a member added later is covered, as a link on a folder covers photos added later). A courtesy warning when adding a private member to a collection that links include ("2 share links include this collection; they would gain access to Italy").
- *Everything* links include collections (they are views of what the link already covers); a link that does not name a collection never shows it.

## 9. Where it is managed (settled with the owner 2026-11-07)
A collection **reuses the folder settings page**: the desktop Folder settings tab and the phone's **Folder** button work on a collection exactly as on a folder. No separate editor, no folder-page button (the public folder page has no owner buttons at folder level).
1. **Making one**: in the **parent folder's** Folder settings, a small **Collections** section (last, after Navigation): the collections in this folder (each a link into its own Folder settings), **New collection** and **Delete collection**. New collection asks for the **name only**; everything else starts at its default (Public, no position, not on the front page, address made from the name, with a clear message and a suggested alternative if the address is taken). After creating, the owner lands in the new collection's Folder settings. Delete removes the record only, never anything on disk, and the confirmation says so.
2. **Its settings page** (the folder settings layout, reordered; renders approved by the owner 2026-11-07): Display name with **Address** straight under it, Dates, Position; Description; Folders under this one appear as (Cards/Flat); then three switches with no explanation beside them: **Show on front page**, **Gallery of its own**, **Private collection**; then Order of the photos here; then **Members**. No Privacy/Navigation headings. **Show on front page** is hidden on a collection that already lives on the front page, and greyed (choice kept, tooltip only) while Private is on. **Not listed is NOT offered for collections** (a collection holds no files; Private is enough; a set meant for one person is a share link). No locations, downloads, scrub switch or Notes tab. Header says "Collection" with an italic name; the tab on a collection is named **"Collection settings"** (decided 2026-11-07; a real folder keeps "Folder settings").
3. **Members** section: add and remove **folders and single photos** from anywhere (a tree picker, like the share-link picker, that opens into a folder's photos). Each folder member: *With everything inside* / *Only its own photos*. Each member row shows the member's own Public/Private/Not listed state and flags missing ones.
4. **Select bar**: the gallery's Select bar gets **Add to collection…** beside **Share…**, shown only once at least one collection exists (decided 2026-11-07, like the Collections tab) (src/php/061 ~474). Select bar only, no thumbnail overlay (tiles are crowded; revisit if too slow). Not in the viewer. A selection still stays within one folder; the Members picker handles several folders at once. On a collection's own page the Select bar offers **Remove from collection** and Share, not Make private/public (those marks live in each photo's real folder).
5. **Sidebar and Menu sheet**: a **Collections** tab (desktop sidebar beside Shares; phone: in the Menu sheet, so the bottom bar keeps its four). It appears once at least one collection exists (like Edited). It is the top-down overview: for each collection, where it lives, address, Public/Private/Not listed, on the front page or not, folder and photo counts, missing members, share links that include it, and a link into its settings. The Folders tree (sidebar, and the Menu sheet's folder list) also shows collections under their parent, distinguished by **italic text only** (the tree has no icons today except Root, so none are added); same on the overview and cards.
Real renders on **desktop and phone** of each place are shown to the owner and approved before any of it is built.

## 10. Interactions and edge cases to test
- Not listed or Private members: never in any stranger's card count, cover, list, flat view, preview, strip, `?img`, `?original`, `?archive`, or log (sentinel crawl as for Not listed, four visitor kinds, plus a link that names the collection).
- A member or parent folder deleted, renamed, moved, made private or Not listed; a photo renamed; a collection whose members are all gone; the same folder in two collections; a collection inside a folder marked Gallery of its own; Cards and Flat; the front page with 0, 1 and many; positions; the cover falling back.
- Addresses: collisions both ways, reserved words, tidy and plain forms, the closed gallery, a real folder appearing later with a collection's name.
- Share links: picking a collection in the picker; the opening page count; a link visitor's nav; a link that does not name it never shows it; adding a private member warns.
- Public log: "Came in at" for a collection arrival; stats unaffected.
- Delete / Files ZIP / README in the ZIP list the collections file; the delete refuses on a changed list as before.
- Upgrade: no file, no cookie, nothing different until the first collection is made; an older gallery ignores the file (harmless).

## 11. Build order (each step green, committed, backed up)
1. Data file, records, parent/members by hidden id, address rules and the missing-page behaviour, Files/Delete/Export.
2. The collection page and its card in the parent (and the front page when ticked): counts by what the visitor can see, sorting among siblings.
3. The admin: Collections section in the parent's folder settings, the collection's settings page with Members, Select bar action, Collections tab, italic tree entries (after the renders are approved).
4. Navigation (top = folder or collection) and Gallery of its own.
5. Share links: the picker, the scope, the opening page count, the warning.
6. Sentinel crawl, address tests, browser checks on desktop and phone, docs (guide, changelog, README), then it joins the rest of 2.6.5.

## 12. Still open
- Check that `?folder=` can carry a virtual path safely, else `?collection=` (7).
- Settled 2026-11-07: links follow the collection dynamically; a Private/Not listed collection hides only its own card and page, with three safeguards: a plain line under the switch ("Hides this collection only. Folders and photos in it stay as they are."), each member's own state in the Members list, a warning when marking a collection Private while it has public members.
- Select bar only (no thumbnail overlay) for adding photos; revisit after use.
- Renders on desktop and phone before the admin is built.

## 13. Rollback
Work happens on `v2.6.4-dev` (to be renamed 2.6.5), one commit per step; restore-point tarball before work in `~/backups/gallery-2.3.3-dev/`. A bad step: `git reset --hard <last good commit>`.

## 14. Folder settings reorder (owner, 2026-11-07; applies to real folders too, part of 2.6.5)
New order: Display name / Dates / Position; Description (moved up from last); Folders under this one appear as; then the switches **Gallery of its own, Private folder (contents included), Not listed** (Not listed last, with its note; Allow downloading originals shows under it when on); Order of the photos here; then a **Locations** section (location switch + map, these used to sit under Privacy). The old Privacy and Navigation headings go. Text changes: Private folder loses its paragraph and gets a small grey "(contents included)" (switching it off still opens the confirmation with the counts); Gallery of its own loses its paragraph and the text beside it, and gets a small grey "(limits navigation)" (the same on a collection's switch; a collection's Private switch gets no "(contents included)" because Private does not hide its members); the Not listed note becomes: "Only you see what is in this folder. Visitors and people with the private-view password get nothing, and an "Everything" share link leaves it out. To share it, send a photo's own address, or make a share link that picks this folder." The form must be split or its controls tied to one save, since the switches (separate instant forms) sit between fields of the identity form.

## 15. Not listed folders and share links (owner, 2026-11-07)
Kept as built: a link covers a Not listed folder only when that folder (or something inside it) is picked directly. An "Everything" link leaves it out, and a folder picked above it does not bring it along (020-folder-ids.php ~87 and ~103). **New for 2.6.5:** in the link form, a Not listed folder is marked, and picking it asks: "This folder is Not listed. Everyone with this link will see all of it." A collection that names a Not listed folder as a member follows the same rule: neither the collection nor a link naming the collection shows it (only the owner sees it).
