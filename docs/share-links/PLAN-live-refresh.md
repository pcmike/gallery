# Plan: the admin respects that things change while it is open (2026-11-09)

Owner's principle: "in general everything should be responsive and respect that things may update in the background".
Found by: the red Visitors number stayed red when a lockout happened while the Visitors tab was open.
Status: AUDIT AND DESIGN ONLY. Nothing here is built. Build waits for the owner's go, phase by phase.

## What refreshes today (read from the code, 2026-11-09)

Nothing in the admin uses `setInterval` or `visibilitychange`. The only background refresh is:

- **Desktop shell sidebar** (`042-shell-script.js`): on window `focus`, and 500 ms after a button press or a change in the frame, it asks again for
  the counts (`?admin&shellcounts=1`), the folder tree (`shelltree`) and the titles (`shellmeta`).
- **Phone Menu sheet** (`039`): when opened, asks for the counts again (`?admin&pbcounts=1`) if the last answer is more than 20 s old, and re-reads
  the whole sheet from the page after something was changed on the page. `pbcounts` carries Features, Shares, Settings and Edited only: **not Visitors**.
- **Prepare media**: its own progress loop while a run is going (this browser's run only).
- Everything else is read once, when the page loads.

## What each part shows, and what can change under it

| Part | Read at | Can change in the background | Refreshes today |
|---|---|---|---|
| Sidebar numbers (shell) | load, focus, after a press | lockouts, visitors, links, Health items | yes (focus) |
| Phone Menu numbers | load, open (>20 s) | same | partly (no Visitors) |
| Visitors tab: list, pills, Sources counts, summary | load; the list again on a filter or view change | every visit, lockout, trim from another browser | no |
| Shares tab: link list, states, "N new", opens | load | a link expiring, being used, paused from another browser | no |
| Health check: rows, update notice | load (+ browser probes, update check) | anything on the server | explicit refresh icon (reloads the page) |
| Features list | load | switches changed in another browser | no |
| Settings groups (values) | load | a change from another browser or by hand in the file | no (a save is last-write-wins per key) |
| Folder settings, photo records | load | another browser's edit | stale-edit check on save (`loaded_hash`): a guard, not a refresh |
| Folder's photo grid (admin) | load | **new photos arriving** (the owner syncs with Syncthing), renames, deletes | no |
| Browsers signed in | load | another browser signs in or out | no |
| Collections, Edited tab | load | edits elsewhere | no |
| Files this gallery has made | when opened (asked then) | files made meanwhile | fresh each open |

Out of scope unless asked: the public gallery pages.

## Design

Two kinds of part, two behaviours:

1. **Read-only displays refresh silently** when the window comes back: sidebar and Menu numbers, the Visitors tab, the Shares list, Browsers signed in.
2. **Places where you edit are never swapped under your hands.** They get a quiet notice instead, "Changed elsewhere: Refresh": Settings values,
   Folder settings and the photo grid (new photos from a sync). The existing `loaded_hash` fingerprint is the pattern; a light "has it changed?" request
   answers with a fingerprint (for a folder: file count and newest modification time; for settings: the settings file's modification time).

One shared mechanism (`window.__live`): panels register `{ name, active(), busy(), refresh() }`. One listener for `visibilitychange` (to visible),
`focus` and `pageshow` (persisted) calls each registered panel that is currently shown, at most once per 15 s per panel. `busy()` is true while a menu or
dialog is open, a field has focus or is changed, something is selected, or a save is in flight: the refresh waits and runs when that ends.
A panel keeps its own state across a refresh (filters, open rows, scroll position). Light fragment or JSON endpoints, never a whole-page fetch for
a read-only panel (a page render scans folders and runs the Health check).

Not changed: Health keeps its explicit refresh (its checks take time and network). No polling to begin with; a slow check while the tab is
visible could be added for the few parts where it matters, and only if the focus-driven refresh turns out not to be enough.

Risks to test: a refresh arriving while a menu is open or a field is being typed in; two refreshes at once; iOS Safari backgrounding (visibility
events, bfcache `pageshow`); server load (a few small requests per focus); the shell frame and the plain page behaving the same.

## Order, each phase with tests and the owner's check

1. The shared mechanism, one counts endpoint for the shell sidebar and the phone Menu (Visitors included), and the Visitors tab (list and pills
   refreshed; lockouts marked seen when the tab is the one being looked at). This is the case that started it.
2. The Shares tab link list.
3. "Changed elsewhere" notices: Settings, Folder settings, the photo grid (new photos from a sync).
4. Browsers signed in; Prepare media status from another browser's run.
