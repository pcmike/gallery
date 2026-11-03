# Private items, share links and sharing

Hiding photos, temporary share links, downloads, and how links preview when shared.

← [Guide index](README.md) · [Project README](../../README.md)

## Private items

For photos, videos or folders that shouldn't be on the public link but that trusted people may see in the same gallery. There are two ways in: a **[share link](#share-links)**, a temporary address for a person that you can limit, watch and switch off, and a **viewer password** for people who should always have access. Both are described below; share links come first in practice.

1. Make an **item** private, or a whole **folder** (covering everything beneath it, with its **Private folder** switch). An item can be switched back to *public* inside a private folder, or *private* inside a public one, or set to simply follow its folder.
2. Set `PRIVATE_VIEW_PASSWORD`. Give people the address `?reveal` (or `/reveal`): a one-field form accepting either password; the private-view one signs that browser in (for 3 days from its last visit, [setting](admin.md#staying-signed-in-and-which-browsers-are-signed-in)) and returns you to the plain link. There is no lock icon in the gallery to press, and nothing on the page tells a visitor that a sign-in exists. Visiting `?reveal` signed in again, or clearing the browser's cookies, locks private items again in that browser. It is a **password, not a secret link**: links pasted into chats get crawled and can't be revoked.

A private item is removed outright for anyone without the password: absent from the grid, notes, viewer, counts, badges, map and link previews, and `?img=`, `?photo=`, `?folder=` and the video endpoint all answer "not found". A group whose members are all private disappears. The same HTTPS requirement and throttle as the admin apply. The cookie is specific to each copy of the gallery, so several galleries on one site never interfere.

### Going through a private folder first

A folder you have made private hides everything in it, and nothing is stored on the photos for that: they just follow the folder. That is the way to prepare a folder before anyone sees it.

1. Switch **Private folder** on in the folder's settings (or leave it on from the start).
2. Go through the photos on the gallery. Each tile shows what it is: a dashed **Folder** lock (private only because the folder is), a solid red **Private** (kept private on its own: it stays private when the folder opens) or an open lock (public). Press the lock, or use **Select** and its *Private*, *Public* and *Folder* buttons, to set the ones that must stay private. A banner at the top of the folder's page counts what opening it would do ("3 photos will stay private · 39 photos become public when you open it") and follows every change you make.
3. Switch **Private folder** off in the folder's settings, or just press the red banner on the folder's page. Either first asks, with the same counts ("3 photos stay private, 39 photos become public"), and only then opens the folder: the photos that followed it become public, those you marked stay private.

A photo that sits inside another private folder stays private too, and is counted with the ones that stay. The counts include everything beneath the folder.

**The main page shows you which folders are private** (you only; a visitor sees none of it, nor the folders). A folder you made private has a red **Private** pill on its card (press it to be asked whether to make the folder public, with the counts), a folder hidden only because one above it is private has the dashed **Folder** pill, and in the row of folder buttons at the top of a folder's page each private folder has a small lock. Where a folder holds photos that are private on their own, its count line says so at the end: "253 photos · 7 videos (2 private ›)", or "36 photos · 6 videos (3 stay private ›)" on a private folder (the same number as its banner). **Press that bracket** and the folder opens with those photos first, in the order it already had; the sort button's *Private items first* shows as on, and the choice lasts for that visit only. In **Private items first**, "private" means photos marked private on their own (in a private folder everything is hidden, which would set none of them apart).

## Share links

A share link lets someone see private things for a while, without a password and without being able to see anything else. It is an address (or a QR code) for one person or group: it can open a limited number of times, start and stop on dates you choose, and cover everything private, only some folders or only some photos. Every link keeps a log of who looked at what. Turn on **Allow share links** in Settings (`ENABLE_SHARE_LINKS`, off by default); a link you made is then listed in the **Share links** tab.

### Making a link
Wherever you are looking at something private, you can share it:
- **A private folder:** the **Share** button in the icon strip of its page, the link icon on its card on the main page, or **Share…** on its row of the *Private folders* list on the Edited tab.
- **A photo:** the viewer's link button on a private photo asks *Share just this photo…* or *Copy link* (a plain link only opens for people who already have access).
- **Several photos:** **Select**, pick them, **Share…**.
- **Anything, with every option:** **+ New link** in the Shares tab.

The first four open a short form: **Who is it for?** (a name only you see), **Use the name as the address** (see below) and, for photos, **Show only these photos**. There are no browsers or Stops working rows: a link made here lets in as many browsers as the setting *New links can be opened by (browsers)* says (**2** to start with, 0 is unlimited) and never stops, and the window says so in words (*Up to 2 browsers can open it (their phone and their computer, say)…*) with a note on where to raise it for a group; change it in the full form, or with *Extend…* later. It then shows the address with **Copy**, **Share…** (the phone's own share sheet) and **QR code**. *More options…* carries what you typed into the full form. The address starts as the name for a folder, but **random for one private photo or a group that are all private** (a one-off share); unticking the box gives a random address right there and never opens the full form, and **Type my own instead…** is the way to the full form with the address box open.

**The full form** (*+ New link* on the Shares tab, or *More options…*) starts with the **name** and the **address** (see *An address of your own*), then one sentence that says what the link will do with the settings it has now: what is the default is plain grey, what is not is darker and bolder. Under it are four folded rows, each with its value on one line: **What they can see**, **How long and how often**, **Downloads and locations**, and **The opening page** (title, preview photo with *Choose…*, note and heads-up). A row that differs from the defaults has a *Changed* tag and *Back to the default*, and *Reset all* clears them. The defaults are: just the public gallery, unlimited opens, never ending, starting immediately, downloads and ZIP off, locations shown only where set, the gallery's title with an automatic preview photo and no note. **Edit…** on an opened link (it ends the paragraph that tells the link's settings in the same plain words) opens the same form filled in.

### What a link can see
A new link starts on **Just the public gallery** (nothing private); pick another choice in the form if the person should see more.

- **Everything private**, including what you add later.
- **Only some folders**, each with everything inside it.
- **Only some photos**, wherever they are (**+ Add photos** in the full form, or from Select).
- **Some folders and some photos**, in one link: pick folders (each with everything inside it) and single photos from anywhere. A person may see an item if it is inside a picked folder or is a picked photo, and nothing else private. "Show only what they can see here" starts ticked, so the link shows just what you picked. There is no way yet to leave a photo out of a picked folder. The link's front page shows the picked photos as plain tiles and a picked folder as a folder card; a link to photos only is just a grid of those photos.
- **Just the public gallery**: nothing private at all. The person sees exactly what any visitor sees, and the link's log shows who looked at what. Use it to find out who opens an address you post or send. It grants nothing, so there are no download choices and no *show only* box, and it starts with unlimited opens, no end date and no heads-up line (you can still set any of them). It can open in a public folder but not a private one.

**Public things are visible too**, as to anyone: a link to one private photo in a public folder opens that folder with the photo in the viewer, the public thumbnails are underneath, and closing the viewer lets the person scroll the public photos. If you would rather they see only what you picked, tick **Show only these photos** (on to start with for a link made from one photo or from Select, off for a folder link; in the full form it shows under *What they can see*, and you can change it later with *Edit*). The pages they see then list nothing else, not even the public photos, and the folder grid shows only your picks. **It is a tidy view, not a lock:** a public photo can still be opened by anyone who knows its address. A person using a link is told nothing about other private folders: no counts, no locks, no hints.

A link remembers a folder by a **hidden id** kept in the folder's own `.gallery-items.php`, not by its name. Renaming or moving the folder keeps the link working; a new folder with the same name is not covered; a copied folder (the same id twice) is ambiguous and shows nothing; and a folder the web server can't write to can't be used for a link (the form says so). If a folder is deleted the link shows **Folder missing**, with *Take it out of this link*. Anything that can't be matched shows nothing: it fails closed.

### Options
- **Download originals** and **ZIP of a folder**, each: *Off*, *Everything* (every photo and video the link shows, public or private), *Everything but private* (the public ones only) or *Only some folders*. The link's own answer decides for the people using it; the gallery-wide *Let visitors download originals* is for people who came in without a link, and is hidden while the gallery is closed. For a link to just the public gallery *Everything but private* is the same as *Everything*, so it isn't offered.
- **Opens in:** the page a person lands on: the front page, or a folder the link covers. It is a menu with the folders as a tree, like the Media tab's.
- **Photo locations:** *Shown only* (the default: the places you have set to show, with a folder's *Show photo locations to visitors* switch and a photo's own Show or Hide) or *All* saved locations. A location that is hidden is removed from every download and ZIP the link hands out ([how](#downloads)).
- **Starts**, **Opens** and **Stops working**, in your browser's time zone. Opens are counted per browser, not per page: once someone is in they can keep looking until the link stops.
- **Heads-up:** a thin line at the top of the gallery, "The link being used to access this media expires on …", amber in the last day and dismissable. `SHARE_HEADS_UP` sets the default; each link can turn it on or off; a link with no end date never shows it.
- **Opening page title and note:** the gallery's title, a plain neutral title (one of a few such as "A shared gallery" or "Some photos for you", the same one every time for the link), or your own text; an optional note. Never a folder name.
- **Preview photo:** the **Show a preview photo** box puts a picture in the link's message preview (what Messages, Discord or Reddit show when the address is pasted) and above the title on the opening page (the whole photo at its own proportions, never cropped, up to 60% of the screen's height; a chat app cuts the *message preview* to its own card shape). It is on to begin with when the title is the gallery's own and off with a neutral title or your own text, and you can change it on any link. The form shows the picture the link will use as soon as the box is ticked, and you can accept it or press **Choose…** to pick another: a folder, then one of its **public** photos (a private photo can't be picked, and a link limited to some folders and/or photos lists only the ones it covers; and the choice is checked again every time the picture is used, so a photo that has since gone private or been deleted is quietly replaced by the automatic one); **Automatic** goes back. The automatic picture of a link limited to some folders and/or photos comes only from the **public photos that link covers** (never from elsewhere in the gallery, because the opening page and a chat's preview show it to anyone before the link is opened); if what it covers holds no public photo it shows the gallery's icon instead. The automatic picture is the front page's own cover photo if it has one and it is public, else the cover of one of the **public** folders (a public photo from the front page if there are no folders), the same one every time for a given link. It is chosen as a signed-out visitor would see the gallery, so a private photo is never used; if nothing is public there is no picture and the icon stays. The link's **Settings** shows the picture it will use (*Preview photo*). Anyone who sees the pasted message sees the picture, so leave it off for a link whose gallery shouldn't be recognisable.

### What the person sees
The address opens a page with one button, **Open the gallery**. **Pressing the button is what uses the link**, so a message app's link preview can't use it up. The same address opening again within 15 minutes (an app's own browser, then Safari) is not a second use. Every link that doesn't work (expired, used up, paused, revoked, mistyped, switched off) shows the same page, *This link isn't valid*, with nothing to press and no reason given. The page is not indexed, not stored and sends no referrer.

Once in, they see the gallery as the link allows: no owner tools, no lock for signing in, no view counts for what they look at, and no link button on private things (a plain link would be useless to them).

### The address, and the server rule
A link looks like `https://example.com/trip/k7QpR2mXv9LwTb4e`: the gallery's own address and 16 letters and digits, or **a name of your own** (see *An address of your own*, below). For that tidy form the server must hand the path to the script; **Settings → Server setup** writes the rule for your gallery's address and checks it (the Health check says when it is missing; it is optional). Until it is in, the long form works as it is: `https://example.com/trip/?link=k7QpR2mXv9LwTb4e`. For a gallery at `/kb/`:

```nginx
location ~ "^/kb/[A-Za-z0-9][A-Za-z0-9-]{2,38}[A-Za-z0-9]$" {
    rewrite "^/kb/([A-Za-z0-9][A-Za-z0-9-]{2,38}[A-Za-z0-9])$" /kb/index.php?link=$1 last;
}
```

(The quotes matter: nginx reads an unquoted `{` as the start of a block and refuses the file.)

and in `.htaccess` for Apache (`mod_rewrite` and `AllowOverride FileInfo`):

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([A-Za-z0-9][A-Za-z0-9-]{2,38}[A-Za-z0-9])$ index.php?link=$1 [L,QSA]
```

The rule matches one word of 4 to 40 letters, digits and hyphens, with no dot and no closing slash. A photo (it has a dot) and a folder's own address (it ends in a slash) are never touched. It **replaces** the older rule that matched exactly 16 letters and digits (a link made before still works): remove the old one. The rule also has no "is it a real folder?" check, on purpose: a link and a folder can then share a name (`/trip/carol` is the link, `/trip/carol/` the folder), and a folder typed without its closing slash is sent on to the folder (by the gallery itself on nginx; on Apache it first takes Apache's own slash redirect, one extra step with the same result, and a link of that name still wins).

### An address of your own
The link form has **Use the name as the address**, ticked to start with: the link's name ("John Doe") becomes `…/johndoe` (letters and digits, no hyphen) and a number goes on the end (`johndoe2`) if that is taken or is a word the gallery uses. A link that opens private photos still starts on the name, with a yellow note that a name is easy to guess and that unticking the box gives a random one. Unticking the box for a public link opens the address field with the cursor in it; for a link that opens private photos it gives a random address (**Type my own instead** opens the field). **Random** gives a code that can't be guessed. Or type any name of 4 to 40 letters, digits and hyphens (shown in lower case; it opens in any case). It is checked when you save: it can't be the name of a folder or file in the gallery's top level, a word the gallery uses itself (`admin`, `index`, `reveal`, ...), or an address another link has or had. A short or ordinary name can be guessed by someone who tries (the same short lockout that holds back wrong tokens applies), so keep the random one for anything sensitive. Changing the address signs out anyone signed in through the old one, and the old address either keeps working (see below, and the default) or is paused. Emptying the field gives the link a new random address.

**Keep the old address working.** When you change an address that already exists (a random one to a name of your own, say), the form offers **Keep the old address working too**, ticked to start with (untick it to pause the old address instead). Ticked, the old address is not paused: anyone who opens it is sent on (a temporary redirect) to the link's present address, so the address bar shows the new one if they copy it, and the link's limits, dates and log are the same. A link has up to ten addresses that work at once (its own and nine kept old ones); each is listed under the link's *Settings* as **Also works at**, with how many visits it sent and when the last was, and **Pause** and **Kill** buttons. *Replace address…* is the "it leaked" button and pauses the kept addresses too. A kept or paused address counts as taken: no other link can use it.

An old address has three states. **Working** (under *Also works at*) sends people on to the present address. **Paused** (under **Paused addresses**, listed by first and last four characters) shows the "not valid" page, stays reserved to the link and keeps its visit totals; **Resume** puts it back to work (up to nine can work at once), and the list says how many times it was tried while paused and from how many IP addresses. **Killed** is for good: **Kill** (asks first) forgets the address, drops its totals and frees the name, so another link, or a folder, can use it; a one-line summary stays under **Killed addresses** (when, visits, IP addresses, tries; never the address itself) until you press **Clear**. A custom name that is killed can be taken by another of your links, and anyone still holding the old address would land on whatever uses the name next. A link remembers every paused address, however many. **Address**, the first line of a link's Settings, shows the address (a name whole; a random code by its last four characters).

When a link has more than one address, the Visitors tab says which one each visitor **came in on** (`via: carol-and-sam`, or `via: k7Q2…Tb4e` for a random code): a column on a wide screen and a line under the IP on a phone; people who came before the second address existed show a dash. The CSV export has the same `came_in_on` column, and each kept address in Settings says how many different IP addresses used it. In the list of every IP address, the link names are buttons: pressing one opens that link in the Shares tab.

A folder added later (over FTP) with the same name does not take the address: with the rule above the link keeps `/trip/name` and the folder keeps `/trip/name/`. The Shares tab says when a folder shares a link's name. This needs the newer rule above; with the older one only random addresses work, and the admin's check ("tidy share addresses") says so.

### Closing the public gallery
**Settings → Share links → Close the public gallery** (`CLOSE_PUBLIC_GALLERY`, off) shuts the plain address. Then only these get in: you (signed in), anyone with the private-view password, and anyone who came in through a share link. Everyone else, for every address of the gallery, gets the page of a link that is not valid ("This link is not valid. Please contact the person who gave it to you."): the same page and status as a mistyped link, so nothing says there is a gallery. These stay open to everyone: a share link's own address and its opening page, the sign-in, and the preview photo of a live link (a chat app asks for it without a cookie).
- **Signing in:** `https://example.com/trip/admin` (or `…/reveal`) goes to the one sign-in (the admin password or the private-view password), as does `…/admin/` and `…/?reveal`. It needs the rule above in place; `…/?admin` always works.
- **Needs a way in:** it is refused while share links are off or no admin password is set, and share links can't be switched off while it is on (open the gallery first). If it is on in the settings file but one of those is missing, it does nothing and the setup notice says so.
- **The public address log** has nothing to log while the gallery is closed; its earlier lines stay. The Shares tab shows its *public address* section only when there is a log to look at, or logging is on and the gallery is open (a closed gallery with nothing logged has nothing to say there).
- **Not a lock on the files:** the gallery's pages are closed, but the web server can still hand out a photo file to anyone who knows its exact address unless the rule that makes a bare photo address answer 403 is in place (see the Health check). A folder typed without its slash is not sent on for a stranger, so a closed gallery doesn't say which folders exist.

### Sending a link
**Copy** puts the address on the clipboard. **Share…** opens the phone's share sheet. **QR code** opens the code large at once (white, with the gallery's icon in the middle) to hold up to a camera, with **Copy** (the picture, to paste into a message), **Save** (the picture) and **Done**.

### Statuses and changes
**How many browsers can open it.** A link counts *browsers*, not page views: each new browser that presses *Open the gallery* is one (the same address within 15 minutes counts once, so an app's own browser followed by Safari is one). Once the limit is reached the link shows **Used up**: the next new browser is turned away (the Shares list shows it as an amber *blocked try*), and everyone already in keeps looking until the link ends. That makes the limit the way to keep a link for one person from being passed around: a new link starts at **2** (a phone and a computer; *Just one* is stricter), and a link for a group is made *Unlimited*. When someone you meant gets a new phone, *Extend…* lets one more browser in. Setting a limit on a link that has already been opened by more browsers stops *new* ones; those already in stay in, so to cut them off give the link a new address (*Edit…*).

Each link is **Active**, **Paused**, **Used up**, **Expired** or **Revoked**. *Pause* and *Resume*, *Extend…* (a used-up link is asked how many more times it can be opened, with *Unlimited* taking the limit off; an expired one is asked when it should stop working; one that is both is asked both; the same button is on the row for either), , *Replace address…* (a new address; the old one stops at once and anyone who is in is signed out; name, settings and log stay. Its dialog has **Cancel**, **Random**, which gives a random code, and **Custom…**, which opens Edit on the Address so you can choose a name of your own), *Revoke* (final: it stays listed with its log) and *Delete…* (which also deletes its log). The sign-in a link gives is checked against the link on every request, so pausing, revoking, replacing or letting it expire cuts off people who are already in.

### Preview as this link
**Preview as this link** opens a new tab showing the gallery exactly as the link does, so you can check a folder-limited link before you send it. A strip at the top says whose view it is, lists what the link includes and what it doesn't (**What's included**) and has **Exit preview**. **Nothing you do in a preview is counted or logged.** A browser has one set of cookies for all its tabs, so while a preview is on, *every gallery page in that browser* shows the link's view (each carries the strip, so you can't mistake it for your own); the admin pages stay yours and the Shares tab says a preview is open, with **End preview**. A preview ends by itself after four hours.

### The log
Every link keeps a log, and **it is kept until you delete the link**: revoking, pausing or letting it expire never loses it. It records each visitor (an address with a browser, named plainly: "Safari on iPhone"), and for each what they **saw** (on their screen, even as a thumbnail), **opened** (large, in the viewer, or played, for a video) and **downloaded** (an original or a ZIP), and which folders they opened. Tries that were turned away are logged with the reason (paused, replaced…).

Each link's log keeps up to 5,000 timeline lines, 2,000 visitors, 500 turned-away tries and 50,000 records of what was looked at (each report rewrites the file, so there is a ceiling; the log says when it has been reached). Nothing is removed unless you ask: **Trim the log…** (in the *IP addresses* view beside the CSV export, and in each link's *Export log* menu) takes out what is older than 7, 30 or 90 days or a date, for one link or all of them; it says what would go before it does anything, keeps the totals (opens, views and the number of visitors) and offers the CSV downloads first. Opening a link in the Shares tab shows its address with Copy, *Share…* and *QR code*, then its log; the link's settings (who can see, downloads, dates, the cover photo…) are folded under **Settings**, closed until you press it, and **Edit…** is in there too. A link's log, and one address's log, have **the same tabs**: a link shows **Visitors** (one line per address), **Folders**, **Media** and **Timeline**; press an address (or its *views* number) and you are looking at that one address, across every link it used, with **Folders**, **Media** and **Timeline** (a *which link* menu appears when it used more than one). **Folders** has a line per folder: *Opens* (how many times its page was opened), *Media seen* (photos and videos that were on their screen), *Opened* (shown large or played, each file once), *Downloaded*, and when; the last three numbers are buttons that take you to **Media** for that folder and that kind. **Media** is a grid of what was looked at, filtered by three menus on one line that narrow the same photos and videos together and answer as you tick: **All IPs** (tick any number of addresses; *All IPs* at the top of the list shows everyone again), **All folders** (tick any number; the folders are listed as a tree, the front page first and each folder's folders indented under it, and ticking a folder includes the folders inside it, while the numbers on the Folders tab show that folder's own files, "this folder only") and one choice: *Most viewed*, *Most recent*, *Photos*, *Videos*, *Private*, *Opened* or *Downloaded*. **Timeline** lists what happened in order, with a small picture on each line; every picture opens the folder at that photo. **IP addresses** lists every address across all links. **New activity** since you last looked shows on the link and as a count in the sidebar. **Export log** gives CSV: visitors, what they looked at, everything in order, or one address. The timeline keeps the latest 300 lines and 200 visitors for each link (older visitors are folded into a count; the tallies stay exact).

The address is the connection's. **Behind Cloudflare** or another proxy every visitor would look the same, so set `TRUSTED_PROXY` (Settings → Share links): `cloudflare` reads Cloudflare's own header, but only when the request really comes from Cloudflare's ranges (built in), and `proxy` reads `X-Forwarded-For` only from the ranges you list in `TRUSTED_PROXY_RANGES`. This also corrects the sign-in throttle.

### Logging the public address
A link to just the public gallery tells you who used *that* link. To log **everyone** who opens the gallery's plain address, switch on **Log visitors to the public address** (Settings → Share links, `LOG_PUBLIC_VISITS`, off by default, needs share links on). The Shares tab then shows **The public address** above the links, with the same log pages, export and trimming as a link, and the address shows in the *IP addresses* list as a green **Public address** beside any links the same address used.
- **Who is logged:** anyone who opens the gallery in a browser that runs scripts. Not you, not people signed in with the private-view password, not people using a share link (their link has its own log), and not link-preview bots. A visit is a visitor arriving, or back after half an hour. It records the address, browser and country and the public folders and photos looked at; a private one is never recorded.
- **How long:** `LOG_PUBLIC_DAYS` (30 to start with; 0 keeps everything until you trim it). Once a day the next visitor's arrival removes the lines older than that. The log also keeps only the latest 2,000 visitors and 5,000 timeline lines.
- **Turning it off** stops the logging; what was logged stays in the tab until you **Trim** or **Delete the log**.
- **It stores every visitor's IP address.** Check what your local privacy rules ask of you (a notice on the page, a retention limit) and keep it only as long as you need it. Behind Cloudflare or another proxy, set `TRUSTED_PROXY` first or every visitor will look the same.

### What a link is, and isn't
A link is a secret address: **anyone who has it can use it**, up to its limits, so keep each as narrow and short-lived as you can and revoke one that went further than you meant. An address is not a person: two people on one network, or one person on two phones, can look alike in the log. Wrong addresses are throttled like wrong passwords.

## Downloads

**Original.** The viewer's caption bar has a download button that saves the file exactly as it sits on the server (a HEIC stays HEIC). On a phone it opens the share sheet, so **Save Image** / **Save Video** puts it straight into Photos (anywhere that isn't possible it is a normal download).

| Visitor | Button | File |
|---|---|---|
| The owner | always | as it is on the server |
| Private-view password | always | as it is on the server |
| Someone using a [share link](#share-links) | as that link allows (off, everywhere it covers, or some folders) | location blanked unless the link shows all saved locations |
| Anyone else | only with `PUBLIC_DOWNLOAD_MODE` (off; everything or chosen folders) | location blanked if the gallery hides it |

The same rule covers the full-size picture in the viewer and a video as it plays (including ranged requests): a visitor who isn't signed in never receives the coordinates of a photo or video whose location is hidden. Private items stay a 404.

**How the location is removed.** Nothing is re-encoded and the size doesn't change: the bytes holding the location are overwritten in the copy sent, and your file is never touched. Blanked: EXIF GPS (JPEG, HEIC, PNG, WebP), GPS and place names in XMP, city/sub-location/state/country in IPTC, and a video's location atom. Date, camera and caption stay. Thumbnails and share previews are re-encoded and never carry a location. **Not covered:** maker notes, GIF and BMP, and text you wrote yourself. A damaged file whose metadata can't be read safely isn't sent as an original at all (422; the viewer shows the re-encoded copy; a video isn't played). Only hidden-location files for signed-out visitors go through PHP, and those responses stay out of shared caches.

**Everything as one ZIP.** Signed in as the owner or with the private-view password, an **archive** button beside the gallery icon saves every original (or, on a folder's page, that folder and below) as one ZIP. It says how many files and how big and **always asks first**. It's a 404 for anyone else. Files are exactly as on the server, locations included.
- It is streamed as it's built (1 MB at a time), so nothing is stored and memory stays flat; files are stored, not compressed, so the size is known up front and a cut-off download shows as incomplete. Over 4 GB or 65,535 files it uses ZIP64.
- Each download holds a PHP worker for as long as it takes, so **only two run at once** (a third gets a 503), there is no resume, and more than 100,000 files is refused. Your host must allow a request that long (shared hosts, proxies and `request_terminate_timeout` may cut it off; with nginx the script sends `X-Accel-Buffering: no`).

## Sharing and previews

Every link you'd paste gets a correct Open Graph preview: `?photo=`, `?folder=` (display name, description or notes, and cover photo) and `?map`. The gallery's own link shows the front page's own cover photo if it has one, else the cover of one of its folders (the cover you chose for that folder, else its first photo; never a video or a private folder, and the same all day); a gallery without folders uses one of its photos. Private items are nonexistent to a crawler, so pasting one never previews it. A shared video previews as picture and title, not a playable embed.

`?folder=Europe/France/Paris` needs no server setup and is what the gallery itself shares. To also type `/kb/Europe/France/Paris/`, map it onto the script. The admin's Settings checks whether this works.

```nginx
location ~ ^/kb/.+/$ {
    rewrite ^/kb/(.+)/$ /kb/index.php?folder=$1 last;
}
```

It matches only paths ending in `/` (so it can't catch `index.php` or a photo), captures the name inside the `rewrite` (which makes nginx escape spaces), and needs php-fpm to pass the original `REQUEST_URI` (the standard `fastcgi_param` does). On Apache, in `.htaccess` (needs `mod_rewrite` and `AllowOverride FileInfo`):

```apache
RewriteEngine On
RewriteRule ^(.+)/$ index.php?folder=$1 [B,L,QSA]
```

Keep the `B` flag: it escapes spaces, `&`, `+` and `#`.
