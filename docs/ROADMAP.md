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

## Named collections

Group folders from anywhere in the tree under a name of your own, without moving anything on disk (say, five trip folders as "Bahamas Trip").

- **Today:** the only way to group without moving folders is a share link with *Only some folders*, and a link has no description of its own beyond the opening page's title and note. Putting the folders under a real parent folder on disk gives a name, description and cover, but it means moving files.
- **Sketch:** a collection is a small record: a name, an address (`/photos/bahamas`, checked against folder names and reserved words the way link addresses are), a title, a description, a cover photo, and a list of folders remembered by their hidden id (so renaming or moving a folder doesn't break it). It can be marked to show as a card on the main page. Its page shows the title, description and cover, then the chosen folders as cards.
- **Navigation:** inside a collection the way back up goes to the collection, not the gallery's front page. This is the same rule as a folder marked *Gallery of its own* (see the not-listed plan, `docs/share-links/PLAN-not-listed.md`), so the two should be designed together, with the "top" being either a folder or a collection.
- **Access:** a collection grants nothing. Private and not-listed folders in it show only to people who could already see them; a share link is still how access is given.
- **Cost:** medium. A new guarded data file (also added to the list of files the gallery made, for the Delete feature), an admin screen to create and edit collections, an address route and the collection page.
- **Picking folders:** each picked folder has a small switch, *With everything inside* (the default, as a share link's folder choice works today) or *Only its own photos* (its subfolders are left out). Picking Italy with everything brings Florence and Rome; picking only Rome gives just Rome. Italy's own photos plus Rome, but not Florence, is Italy (own photos only) plus Rome. A folder already covered by a picked ancestor is redundant, so the form says so instead of showing it twice.
- **Photos too:** a collection can hold single photos as well as folders, the way a share link's *Some folders and some photos* does. A photo is remembered by its folder's hidden id plus its file name, as a link remembers it, so renaming or moving the folder is fine, while renaming the photo or moving it to another folder drops it (the form shows it as missing). On the collection's page, picked photos are plain tiles and picked folders are cards.
- **Overlap with share links (probably the better way to build it):** a share link with *Only some folders and photos*, *Public items only* on its folders, *Show only* ticked, no end date, unlimited opens and a name of your own as its address is already a public collection, minus a page of its own. So rather than a second system (new data file, new address route, new admin screen), a collection could be a share link that gains: a **description and cover shown on its own front page** (today only the opening page has a title and note, shown before entering), and optionally **a card on the gallery's main page** (links are never listed today). That reuses scopes, hidden ids, addresses, limits, pausing and the per-link log, and gives each collection its own visitor log for free. The cost of that route is that a collection is then a link in the Shares tab, with cookie and opens-counting behaviour, and it is not reachable when the gallery is closed unless that is deliberate.
