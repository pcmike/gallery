# Share links: what to check on a real phone

Everything below was built and tested in a desktop browser (a real headless Chromium) and against a server, but it behaves differently on a real iPhone, in Messages and in an app's own browser. Copy `index.php` to your trip gallery (and, for the tidy address, add the rule the Share tab shows), then go through this. Tick what works; anything that doesn't, tell me what you saw.

## Making and sending a link
- [ ] **Settings → Share links → Allow share links** is on, and the tab appears.
- [ ] On a private folder's page the **Share** button is in the icon strip, on the same row as the others. Press it: the form fits the screen and the keyboard doesn't hide **Create link**.
- [ ] After **Create link**, **Copy** puts the address on the clipboard (paste it into Notes).
- [ ] **Share…** opens the phone's own share sheet with the address (Messages, Mail, AirDrop).
- [ ] **QR code** shows a code with the gallery's icon in the middle. Scan it with the Camera app from another phone or a screen: it opens the link.
- [ ] **Show large** fills the screen in white and stays on until **Done**. Hold it up to another phone's camera across a table.
- [ ] **Copy image** then paste into a message: a picture of the code arrives. **Save image** offers *Save Image* in the share sheet and it lands in Photos.
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
- [ ] **Preview as this link** opens a new tab with the purple strip; **What's included** lists the right things; **Exit preview** returns you to the Share tab. Opening the gallery normally afterwards shows your owner tools again.
- [ ] After someone has used a link (use your own phone, signed out, or a private tab): the link row shows **new** activity, and its **Visitors**, **Folders** and **Photos** tabs say what they looked at; tapping an address opens its page.
- [ ] The tab is usable at phone width (rows open, the log scrolls sideways inside its table, nothing runs off the screen).

## The server
- [ ] The tab's banner says the tidy address works (after adding the rule), and a link looks like `https://yoursite/trip/<16 letters>`.
- [ ] Behind **Cloudflare**: set *Where visitors' IP addresses come from* to **Cloudflare**; the Visitors tab then shows the visitor's real address, not Cloudflare's. (Cloudflare shouldn't cache `/trip/` pages; it didn't when last checked.)

## The public gallery and the public log (2.3.1)
- [ ] Make a link with **Just the public gallery** (it starts unlimited, no end). Open it on your phone, press the button, look at a few photos: you see only public things. In the Share tab its log shows your phone, with what you looked at.
- [ ] Switch on **Log visitors to the public address** in Settings. Open the plain address in a private tab: **The public address** appears above your links with one visit; your own visits (signed in) are not added.
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
- [ ] New link → **Use the name** fills the Address from the name ("Dyllen and Brenden" → `dyllen-and-brenden`). Save, copy the link: `https://pcmike.net/trip/dyllen-and-brenden` opens the opening page, in capitals too.
- [ ] Try a name that is a folder (`rome`), `admin`, `abc`: each is refused with a reason.
- [ ] Add a folder named like a link over FTP: the link still opens at `/trip/name`, and the folder at `/trip/name/`; the link's row says a folder has that name.
- [ ] Settings → Share links → **Close the public gallery**: it can't be switched on while share links are off. Switch it on, then from a browser that is not signed in (a private window): the front page, a folder and a photo address all say "This link is not valid. Please contact the person who gave it to you."
- [ ] Still open when closed: a share link opens (and its message preview shows the preview photo in a chat), the sign-in at `/trip/admin` works, you in your normal browser see everything.
- [ ] The Share tab's **Public address** says *Closed*; the Settings row for logging visitors says nothing is logged while closed.
- [ ] A bare photo address (`/trip/Rome/photo.jpg`) still answers 403 (the server rule for the files), closed or not.
- [ ] **Replace address…** on a link: the dialog has **Cancel, Random, Custom…** on one line on your phone. On a link with a custom address its text names the address and says Random drops it; **Custom…** takes you to that link's Edit with the cursor in Address (no zoom), and nothing is replaced until you save a new name or press Random.
- [ ] **Health check** (Settings): with the gallery closed, *Tidy folder links* is green and says the gallery is closed, not "not set up".

