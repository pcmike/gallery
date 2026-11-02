# Share links: what to check on a real phone

Everything below was built and tested in a desktop browser (a real headless Chromium) and against a server, but it behaves differently on a real iPhone, in Messages and in an app's own browser. Copy `index.php` to your trip gallery (and, for the tidy address, add the rule the Shares tab shows), then go through this. Tick what works; anything that doesn't, tell me what you saw.

**How to use this list.** The first part, *Start here*, is only what is new in 2.4.0 (about 20 minutes on the phone you actually use). The second part, *Older checks*, belongs to what was already released (2.3.0 to 2.3.2): go through it only if something looks off, or after a move to a new server. **Do not press Delete** under *Files this gallery has made* beyond step 3 (Cancel) on the real gallery.

# Start here: new in 2.4.0

## After the audit fixes (2026-11-01)
- [ ] **Open a map** (the little one on a folder, then full screen). It must still draw: the map code is now loaded with an integrity hash, and a browser refuses a file whose hash differs. If the map is blank, tell me what the browser console says.
- [ ] **Still signed in** after the update (no password asked), and **Log out** really logs you out (open the gallery in a private tab with the same address to be sure).
- [ ] In the admin on a phone or iPad, the **numbers beside Edited, Shares and Settings** are a little brighter than before and still readable on the blue active entry; the **links under *How to read this*** in Notes are light blue.
- [ ] **Settings → Signing in and passwords → Browsers signed in**: your phone is listed as *You (admin)* with *this browser*; sign in to private view from another device (or a private tab) and it appears as *Private view*. **End** it: that device is asked for the password the next time it comes. *Sign out all other browsers* asks you to press twice.
- [ ] **Both lengths say 3 days** (*Stay signed in as the admin*, *Stay in private view*). Your own setting from before is kept if you changed it.
- [ ] **Make a link from the select button** (Select, pick a photo, **Share…**): the window says *Up to 2 browsers can open it (their phone and their computer, say)…* and, under it, a box **Meant for more than one person?**: tick it and the sentence becomes **As many browsers as they like** can open it… (those words in bold); untick it and it goes back to 2. Press **More options…**: on a computer, **inside the Admin shell**, you land on the Shares page with the form open (not a blank frame), the photo picked, and 2 selected.
- [ ] **Shares → a new link → How long and how often**: *How many browsers can open it?* starts on **2**; choosing *Unlimited* marks the sentence as changed; *Back to the default* returns to 2. Settings → Sharing and access → *New links can be opened by (browsers)* changes it (0 = unlimited).
- [ ] **A link you already have**: *Edit…* it to a limit; open it in a second and third browser and watch the third turned away (an amber *blocked try*), with the first two still working. *Extend…* asks *How many more browsers can open it?*.
- [ ] If a folder ever shows nothing to visitors and a notice says its settings file can't be read: that is the new safety. Put the file back from a backup (or delete it) and the folder returns.


## Staying signed in (2.4.0)
- [ ] Add the gallery to the **Home Screen**, open it, sign in as the admin once. Swipe the app away (close it), open it again from the Home Screen and go to the admin (the title, or `…/admin`): **no password asked**.
- [ ] Do that again after a day, then after two days: still no password (every visit renews it for three days); only after about four days away does it ask again.
- [ ] **Settings → Signing in → Stay signed in as the admin for (days)** shows 3. Set it to **0**, close and reopen the app: the password is asked again. Put 3 back.
- [ ] Press **Log out** in the Home Screen app, close it, reopen: the password is asked (and the same in Safari: Safari and the Home Screen app are separate sign-ins).
- [ ] Change the admin password in Safari: the Home Screen app asks for the new password next time.

## The new-link form, the list, the QR code and Settings (2.4.0)
- [ ] **+ New link**: the form is short: a name, "Use the name as the address" (ticked) with `…/auntcarol` as the example, one sentence, and four folded rows (What they can see; How long and how often; Downloads and locations; The opening page). Type "John Doe": the preview says `…/johndoe`. Untick the box: the address box opens with the cursor in it, and **Random** is beside it.
- [ ] Pick **Everything private**: the address switches to Random and says why. Tick the box again: it uses the name.
- [ ] The sentence is grey where it is the default and darker and bolder where you changed something; the row you changed says **Changed**, **Back to the default** works, **Reset all** works.
- [ ] Defaults are **Unlimited** opens and **Never** (also in the quick *Share this folder* form).
- [ ] **Downloads** offer Off / Everything / Everything but private / Only some folders; for a link to just the public gallery "Everything but private" is not offered. **Photo locations** say *Shown only* / *All*.
- [ ] An opened link: Share… and QR code, then one plain paragraph that ends with **Edit…** (it opens the same form, filled in). No address box, no long Settings list. A link with old addresses has an **Old addresses** line; **Manage…** opens it.
- [ ] A link's row says `1 open`, `3 of 10 opens`, `Not opened yet`, an end date only when it has one.
- [ ] **QR code** shows the large code at once, with Copy, Save and Done (three buttons of one size on one row). A paused link has a **Copy** button beside Share….
- [ ] **Settings** opens as a short list of closed groups; Prepare media is inside Videos and thumbnails; the sign-in group holds the passwords and Stay signed in.
- [ ] **The quick Share popup** (a private folder's Share button): no Opens / Stops working; the name ticked with a yellow note; type a name and see the address under it; untick: a random address, the full form does not open; *Type my own instead…* opens the full form with the cursor in the address box. For one private photo (viewer → link button → *Share just this photo…*): the box starts unticked, a random address.
- [ ] **The full form**: a link that opens private photos keeps the name ticked with the yellow note; unticking gives a random address.
- [ ] **A used-up link and an expired link** (Ended section): both have *Extend…* on the row; used up asks how many more opens (try *Unlimited*), expired asks when it should stop.
- [ ] **Killed addresses → Clear** asks first; Cancel keeps them.
- [ ] **Settings → Sharing and access → Let visitors download originals**: Off / Everything / Only some folders; with the last, pick a folder and (in a private window) check a photo in it has the download button and one outside it does not; there is no ZIP button for a visitor.
- [ ] **Turn Thumbnails off, then on**: Prepare media goes at once and comes back at once (with its count), no reload; *Prepare the rest* works straight away.
- [ ] **Files this gallery has made → Delete…, step 3** (stop there, press Cancel): the red text says nothing stays private and how to set up again.
- [ ] **Settings → Files this gallery has made**: **Show list** (what, where, how big); **Download ZIP** (asks for the password, saves a file, open it: README first, your captions and settings inside); **Delete…** only up to step 3 on the real gallery, then **Cancel**. Do not run the delete on the real gallery until the owner has run the skeleton test.

## The fifth pass: Show me, Select, the download chooser, the path bar (2.4.0)
- [ ] **Features → Show me** on your gallery (photos all in folders): lock, pencil, star and Select each open a folder with photos, and the blue note has a small tail pointing at the button. Scroll a little and rotate the phone: the note stays next to the button.
- [ ] **Features:** one *Act on many photos at once* row; with Share links off nothing on that row or its pointer mentions sharing.
- [ ] **Select:** pick one public photo (only *Make private*), one private photo (only *Make public*), both (both); nothing picked (both greyed). In a private folder: *Private* and *Public*, plus *Folder* only when a picked photo has a setting of its own.
- [ ] **Settings → Let visitors download originals → Only some folders:** the folders open as a list with ticks (nested folders indented), saves as you tick, stays open, closes when you tap away; no browser checkboxes and no Done button.
- [ ] **A folder with no sibling** (only child): its path bar ends with its own blue pill.
- [ ] **Shares → New link → What they can see → Some folders and some photos:** pick a folder and a photo, create it, open the link in a private window: you see the folder (with everything in it) and the photo, and no other private photo.
- [ ] **The shell on a computer** (the Admin pill on your own gallery page): the path, the Folder settings / Notes icon buttons and the folder tree stay while you move between photos, settings, notes, Features, Edited, Shares and Settings; a folder card clicked inside the frame moves the path and the tree; the browser's Back button steps back through what you opened; reloading the shell comes back to the same place; *Open on its own* (the arrow icon) leaves the shell. On a phone the pill is not there.
- [ ] **A link to just photos:** press its button: you land on the front page with the photos as a plain grid (no folder cards), and a tap opens the viewer on that photo. A link to exactly one photo opens the viewer on it straight away. With a mixed link you see the folder as a card and the loose photos beside it.
- [ ] **A QR code's Save** on the Mac: it downloads the picture (no share sheet); on the phone it still opens the sheet with *Add to Photos*.

## The fourth pass: first run, Features, Settings (2.4.0)
- [ ] **Features** (a fresh gallery opens on it; otherwise the first entry of the sidebar's top group): the rows read well on a phone, every button jumps to the right setting, and each **Show me** opens the gallery with a ring and a label on the lock, the pencil, the star, Select; the label goes at your first tap.
- [ ] **Select mode:** pick photos to share; the red **Private** pill stays on the private ones (pressing it only picks the tile) and the tick sits at the bottom left.
- [ ] **Settings controls:** switches line up on the right edge down the page; **Photo order** is one button that opens a list (4 rows, easy to tap) and the list closes when you choose; no browser dropdown anywhere; *your setting · default: …* appears beside a name only after you change it.
- [ ] **Expand all / Collapse all** at the right of the *Settings* heading: only the one that applies is shown.
- [ ] **Show subfolders** on a gallery with a hidden folder: the switch's line says what turning it on shows ("34 photos in 1 folder: PNGs"), the Health check has a **Heads-up** with a *Show subfolders* button, the sidebar lists the folder greyed.
- [ ] **Setup page** (a gallery with no admin password: open `?admin`): it says to find `gallery-setup-code.php` beside `index.php` and type the code on its second line. Open that file in your file manager or FTP program, type the code (lower case is fine), choose a password: you land signed in on Settings and the file is gone.
- [ ] **Health check on a fresh gallery:** *All good*; the lock row is grey until something is private. The buttons (Check again, Prepare the rest, …) are one size.
- [ ] **Locations:** a folder's *Show photo locations* switch with locations off for the whole gallery has a *Settings* link that jumps to *Allow photo locations*.

## The declutter and the admin layout (2.4.0)
- [ ] **Settings** (on the phone): the groups are *Folders and photos*, *Videos and thumbnails*, *Visitors and logs*, *Locations and the map*, *Sharing and access*, *Signing in and passwords*, *Server setup*, *Files this gallery has made*. **Photo order** is four large buttons in a 2×2 grid; the IP-source and download rows stack under their text.
- [ ] **Turn a switch off and watch what goes:** Count views off hides *Show view counts to visitors*; Allow photo locations off hides *Map* and the pin grouping under it; Show videos off hides the two video rows. "default: …" appears under a row only after you change it from the default.
- [ ] **Health check**: it says *All good*, or *N things to fix* with only those rows; the chevron button says *N optional* and opens the grey rows and what passed; the chevron is big enough to press with a thumb.
- [ ] **Edited tab** with private not locked: one red line, and *Fix this: the rules to add* opens the nginx and Apache rules. From the Health check, *Fix this →* lands on the same rules in Settings → Server setup, opened.
- [ ] **Admin layout**: the top bar is one short row (title and Log out); *Edited / Share / Settings* are one row of buttons; picking a folder shows its heading with *Folder settings / Notes / Export*; on Edited, Share and Settings those folder tabs are gone.
- [ ] **Folders from elsewhere** (Settings → Server setup): the two fields' hints fit ("/full/path/to/the/place", "Name (optional)"), Remove sits beside the path, the explanation is above, in the row's text.
- [ ] **The folder picker on the phone** ("Folders · name" at the top of the admin page): it is there on a folder's Settings and Notes and gone on Features, Edited, Shares and Settings.
- [ ] **Health check → Tidy share addresses** shows an example like `https://yourgallery/trip/k7QpR2mXv9LwTb4e` (no `0000-probe-` in it).
- [ ] **Map pins on the phone**: open the little map on the front page: no two pins or numbered groups sit on top of each other; press the biggest group again and again and it parts into smaller ones until every photo has its own pin, far enough apart to press. Put a finger on the little map and drag up: the page scrolls and "Use two fingers to move the map" appears; two fingers pinch and move the map. The full-screen map moves with one finger. A pin's photo link on the full-screen map (opened with its button) scrolls to the photo with its ring; *← Back to the map* is on one line, sits above the bottom bar, and from a pin on the full-screen map takes you back to the full-screen map.
- [ ] **Pin thumbnails**: on the little map above the grid, press a pin then its thumbnail: the photo is in the middle of the screen with a ring, the map stays open, a *← Back to the map* pill appears and takes you back up. Same from the full-screen map (it closes first; the pill reopens it as you left it). The pill goes by itself after about ten seconds.
- [ ] **`/reveal`** (and `/admin`) goes to the sign-in.
- [ ] **The log tabs**: each has *How to read this*, closed; the timeline's "full" notice (if it is full) stays visible.

# Older checks (2.3.0 to 2.3.2): only if something looks off

## Making and sending a link
- [ ] **Settings → Sharing and access → Allow share links** is on, and the tab appears.
- [ ] On a private folder's page the **Share** button is in the icon strip, on the same row as the others. Press it: the form fits the screen and the keyboard doesn't hide **Create link**.
- [ ] After **Create link**, **Copy** puts the address on the clipboard (paste it into Notes).
- [ ] **Share…** opens the phone's own share sheet with the address (Messages, Mail, AirDrop).
- [ ] **QR code** shows a code with the gallery's icon in the middle. Scan it with the Camera app from another phone or a screen: it opens the link.
- [ ] The **QR code** opens large at once (white, with the gallery's icon in the middle) and stays until **Done**. Hold it up to another phone's camera across a table.
- [ ] **Copy** (in the QR code's window) then paste into a message: a picture of the code arrives. **Save** offers *Save Image* in the share sheet and it lands in Photos.
- [ ] In the viewer, on a **private** photo, the link button asks *Share just this photo…* / *Copy link*; on a **public** photo it just copies.
- [ ] **Select** a few photos, **Share…**: the form says "Share these N photos".

## What the person sees
- [ ] Paste a link with the gallery's title into Messages: the preview shows the title and a preview photo from a public folder (never a private one, never a folder name), the same photo each time you paste it. (If you chose "A neutral title", it says "A shared gallery" and shows no photo unless you ticked *Show a cover photo*.)
- [ ] The opening page of that link shows the same photo above the title; a link with the box unticked shows the gallery's icon instead.
- [ ] Open it from Messages: the page is one button, **Open the gallery**. Press it: you land in the gallery (or the folder it opens in).
- [ ] The link doesn't count an open until the button is pressed: open the link in Messages' preview and check the tab still says *0 of N opens*.
- [ ] Open a link from an app's own browser (Instagram, Facebook, Messages' in-app view), press the button, then choose *Open in Safari*: it still works and used only one open.
- [ ] As the person: no pencils, no locks, no sign-in lock, no Private tab; private photos have no link button; only what the link covers is there, plus what is public (a folder-limited link shows no other private folder; a link made with *Show only these photos* shows nothing else, public photos included).
- [ ] The heads-up line (if on) shows the end time in your own time zone and can be dismissed.
- [ ] A link that is **paused**, **revoked**, expired or mistyped shows the same "This link isn't valid" page.

## Preview and the log
- [ ] **Preview as this link** opens a new tab with the purple strip; **What's included** lists the right things; **Exit preview** returns you to the Shares tab. Opening the gallery normally afterwards shows your owner tools again.
- [ ] After someone has used a link (use your own phone, signed out, or a private tab): the link row shows **new** activity, and its **Visitors**, **Folders** and **Photos** tabs say what they looked at; tapping an address opens its page.
- [ ] The tab is usable at phone width (rows open, the log scrolls sideways inside its table, nothing runs off the screen).

## The server
- [ ] The tab's banner says the tidy address works (after adding the rule), and a link looks like `https://yoursite/trip/<16 letters>`.
- [ ] Behind **Cloudflare**: set *Where visitors' IP addresses come from* to **Cloudflare**; the Visitors tab then shows the visitor's real address, not Cloudflare's. (Cloudflare shouldn't cache `/trip/` pages; it didn't when last checked.)

## The public gallery and the public log (2.3.1)
- [ ] Make a link with **Just the public gallery** (it starts unlimited, no end). Open it on your phone, press the button, look at a few photos: you see only public things. In the Shares tab its log shows your phone, with what you looked at.
- [ ] Switch on **Log visitors to the public address** in Settings → Visitors and logs. Open the plain address in a private tab: **The public address** appears above your links with one visit; your own visits (signed in) are not added.
- [ ] In the **IP addresses** list an address that used a link and the public address shows both chips, the public one in green.
- [ ] On a log's **Media** tab (it was Photos) the three menus (*All IPs*, *All folders*, *Most viewed*) sit on one or two lines and open a list that stays on the screen; the first two take several ticks and apply when you close them, the third is one choice (Most viewed, Most recent, Photos, Videos, Private, Opened, Downloaded).
- [ ] On the **Folders** tab the Media seen, Opened and Downloaded numbers take you to Media for that folder; an address's page has the same tabs (Folders, Media, Timeline) and the *views* button in the Visitors list lands on its Media tab.
- [ ] On the **Timeline** the pictures are easy to tap and open the folder at that photo; a link has its own Timeline tab.
- [ ] An opened link shows its address, Copy, Share… and QR code, then straight on to the activity; **Settings** is closed until you press it, and **Edit…** is inside.
- [ ] The **IP addresses** list on a phone is one card per address (IP address first), not a table you scroll sideways.
- [ ] **The preview photo:** in the link form ticking *Show a preview photo* shows a small picture at once; **Choose…** offers only public photos; **Automatic** goes back; an opened link's **Settings** shows the picture it will use.
- [ ] **Opens in** and the *Add photos* folder list are menus (the folders as a tree) that open inside the screen; on the Media tab the three menus update as you tick and stay open; *All folders* is the first row, not white text.
- [ ] The **Settings** fold, **Edit…** and the back link on an address's page are easy to see (not grey or white).
- [ ] A page with fewer than ten things (folders, photos, videos) is **one per row** on your phone; ten or more are two across; your tablet and a wide window are as before.
- [ ] **No zoom:** touching a field in the admin (a folder's Display name, the link form) no longer zooms the page in.
- [ ] The preview photo dialog opens on a folder with photos in it, not an empty page.
- [ ] The link's **opening page** shows the whole photo (a portrait is not cut off at the top), at its own shape, not taller than about 60% of the screen.

## Closing the gallery, your own addresses, /admin (2.3.1)
- [ ] **Copy the new rule first.** In Settings → Server setup, copy the tidy-address rule for your gallery and use it **instead of** the 16-character one in `gallery.inc` (`sudo nginx -t && sudo systemctl reload nginx` or your container's reload). Then the admin's check says tidy addresses work. Copy the new `index.php` **before** you paste the rule.
- [ ] `https://pcmike.net/trip/admin` and `…/trip/admin/` land on the sign-in; after signing in with the admin password you are in the admin (or the gallery, with the lock open).
- [ ] A **folder typed without its slash** (`/trip/Rome`) goes to `/trip/Rome/`. `/trip/rome` where there is no such folder shows "This link is not valid" (it used to show the site's index).
- [ ] New link → **Use the name** fills the Address from the name ("Carol and Sam" → `carol-and-sam`). Save, copy the link: `https://pcmike.net/trip/carol-and-sam` opens the opening page, in capitals too.
- [ ] Try a name that is a folder (`rome`), `admin`, `abc`: each is refused with a reason.
- [ ] Add a folder named like a link over FTP: the link still opens at `/trip/name`, and the folder at `/trip/name/`; the link's row says a folder has that name.
- [ ] Settings → Sharing and access → **Close the public gallery**: it can't be switched on while share links are off. Switch it on, then from a browser that is not signed in (a private window): the front page, a folder and a photo address all say "This link is not valid. Please contact the person who gave it to you."
- [ ] Still open when closed: a share link opens (and its message preview shows the preview photo in a chat), the sign-in at `/trip/admin` works, you in your normal browser see everything.
- [ ] The Shares tab's **Public address** says *Closed*; the Settings row for logging visitors says nothing is logged while closed.
- [ ] A bare photo address (`/trip/Rome/photo.jpg`) still answers 403 (the server rule for the files), closed or not.
- [ ] **Replace address…** on a link: the dialog has **Cancel, Random, Custom…** on one line on your phone. On a link with a custom address its text names the address and says Random drops it; **Custom…** takes you to that link's Edit with the cursor in Address (no zoom), and nothing is replaced until you save a new name or press Random.
- [ ] **Health check** (Settings): with the gallery closed, *Tidy folder links* is green and says the gallery is closed, not "not set up".

## Keeping an old address working (2.3.2)
- [ ] Edit a link that has a random address, type a name in **Address**: **Keep the old address working too** appears already ticked (and goes away if you put the old address back). Save.
- [ ] Open the **old** address in a private window: you land on the new one and the address bar shows the new one.
- [ ] Under the link's **Settings** there is **Also works at** with the old address, how many visits it sent and from how many IPs, and **Pause** and **Kill**. **Pause** moves it to **Paused addresses** at once (the old address shows "not valid"); **Resume** brings it back with its totals.
- [ ] **Replace address…** on a link with a kept address says the kept one stops too.
- [ ] A link's **Settings** now start with **Address** (the name whole, or "a random code, ending …xxxx").
- [ ] **Paused addresses** appears under Settings for a link whose address you changed before; **Resume** on the random one you sent out makes it open and send people on to the custom name again. Open it while paused: the line says how many times it was tried.
- [ ] **Kill** asks first (a name of your own gets the warning about the name being free), then leaves a line under **Killed addresses**; **Clear** removes the line.
- [ ] Open that old address in a private window, then look at the link's **Visitors** tab: a line under the IP says `via: k7Q2…Tb4e` (a wide screen has a "Came in on" column). Someone who came before shows a dash.
- [ ] **IP addresses** view on your phone: the link names under "Links used" are whole pills (nothing broken up), and pressing one opens that link in the Links view, Settings folded.
- [ ] In the **Public address** row's Settings, **Settings → Visitors and logs → Log visitors to the public address** is one link: pressing it opens the Settings tab at that setting (the group open, the row outlined for a moment).
- [ ] **New share link**: *What they can see* starts on **Just the public gallery**.
- [ ] **The owner's bar on your phone** (signed in as the owner): a gallery page and every admin page have a bar at the bottom, *Gallery · Folder · Notes · Menu*; Gallery goes to the folder's photos, Folder and Notes to its admin page with that tab lit; *Menu* opens a sheet with Features, Edited, Shares, Settings, the folders (the one you are in marked) and Log out, and closes when you choose something or tap outside; picking a folder on an admin page keeps the page (Features stays Features); nothing sits under the bar at the bottom of a long page; while *Select* is up the bar steps aside; a visitor and a share-link holder see no bar; on iPhone Safari the bar stays put as the address bar slides away and clears the home indicator.

- [ ] **Several share links at once:** on the Shares tab press the Select icon beside *New link*: a bar appears at the bottom (above the phone's bottom bar) with *Nothing selected*, *Select all*, *Clear*, *Done*; pressing the Active / Paused pill of a link ticks it (the row doesn't open); *Pause (n)* pauses the ticked active ones and the bar then offers *Resume (n)*; *Revoke…* and *Delete…* name the links, and Delete's button stays off until you tick that the logs go too; *Extend…* asks for the new end and opens; *Done* leaves select mode. The Menu's folders have their names close to their lines.
- [ ] **No lock, no Admin pill:** the gallery's icon strip has no lock; signed in as the owner the title opens the shell on a computer (on a phone it is only a title: the bottom bar is the way in); typing `?admin` on a computer lands in the shell; the Select bar on the Shares tab has icon tiles on a phone and the Links / IP addresses / Select / New link row is one line.

## Sign-in history and the country file (2.4.0)
- [ ] **Settings → Signing in and passwords → Browsers signed in**: each browser has *Addresses*; it opens a table of days and addresses (your own address, full) and *Download all N lines (CSV)* opens in a spreadsheet. The list reads fine on the phone (text wraps, buttons are big enough).
- [ ] Sign in to private view from another device, then use that device on mobile data and on Wi-Fi: its list shows more than one address and *Last address* changes. A red or amber mark appears only if the device or country really looks different. **Looks fine** removes it.
- [ ] **Health check** has an *Address history* row with a size, and a row naming any flagged browser.
- [ ] **Look up the country of each address → Turn on…**: it says what it will do and the size before it does anything; confirming downloads about 4 MB and the row then says the date and *Refresh now* / *Turn off*. Your own browser's addresses then show a country (if you are on a home network it is the real one).
- [ ] Turn it off with *Delete the file*, then on again: it comes back. Leave it however you want it.
- [ ] Optional: switch on *Sign a browser out if it is used from a new country* only if you will travel and want it; the others stay off.
