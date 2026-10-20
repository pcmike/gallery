# Share links: what to check on a real phone

Everything below was built and tested in a desktop browser (a real headless Chromium) and against a server, but it behaves differently on a real iPhone, in Messages and in an app's own browser. Copy `index.php` to your trip gallery (and, for the tidy address, add the rule the Share links tab shows), then go through this. Tick what works; anything that doesn't, tell me what you saw.

## Making and sending a link
- [ ] **Site settings → Share links → Allow share links** is on, and the tab appears.
- [ ] On a private folder's page the **Share** button is in the icon strip, on the same row as the others. Press it: the form fits the screen and the keyboard doesn't hide **Create link**.
- [ ] After **Create link**, **Copy** puts the address on the clipboard (paste it into Notes).
- [ ] **Share…** opens the phone's own share sheet with the address (Messages, Mail, AirDrop).
- [ ] **QR code** shows a code with the gallery's icon in the middle. Scan it with the Camera app from another phone or a screen: it opens the link.
- [ ] **Show large** fills the screen in white and stays on until **Done**. Hold it up to another phone's camera across a table.
- [ ] **Copy image** then paste into a message: a picture of the code arrives. **Save image** offers *Save Image* in the share sheet and it lands in Photos.
- [ ] In the viewer, on a **private** photo, the link button asks *Share just this photo…* / *Copy link*; on a **public** photo it just copies.
- [ ] **Select** a few photos, **Share…**: the form says "Share these N photos".

## What the person sees
- [ ] Paste the link into Messages: the preview shows the gallery's title and icon, never a photo or a folder name. (If you chose "A neutral title", it says "A shared gallery".)
- [ ] Open it from Messages: the page is one button, **Open the gallery**. Press it: you land in the gallery (or the folder it opens in).
- [ ] The link doesn't count an open until the button is pressed: open the link in Messages' preview and check the tab still says *0 of N opens*.
- [ ] Open a link from an app's own browser (Instagram, Facebook, Messages' in-app view), press the button, then choose *Open in Safari*: it still works and used only one open.
- [ ] As the person: no pencils, no locks, no sign-in lock, no Private tab; private photos have no link button; only what the link covers is there (a folder-limited link shows no other private folder).
- [ ] The heads-up line (if on) shows the end time in your own time zone and can be dismissed.
- [ ] A link that is **paused**, **revoked**, expired or mistyped shows the same "This link isn't valid" page.

## Preview and the log
- [ ] **Preview as this link** opens a new tab with the purple strip; **What's included** lists the right things; **Exit preview** returns you to the Share links tab. Opening the gallery normally afterwards shows your owner tools again.
- [ ] After someone has used a link (use your own phone, signed out, or a private tab): the link row shows **new** activity, and its **Visitors**, **Folders** and **Photos** tabs say what they looked at; tapping an address opens its page.
- [ ] The tab is usable at phone width (rows open, the log scrolls sideways inside its table, nothing runs off the screen).

## The server
- [ ] The tab's banner says the tidy address works (after adding the rule), and a link looks like `https://yoursite/trip/<16 letters>`.
- [ ] Behind **Cloudflare**: set *Where visitors' IP addresses come from* to **Cloudflare**; the Visitors tab then shows the visitor's real address, not Cloudflare's. (Cloudflare shouldn't cache `/trip/` pages; it didn't when last checked.)
