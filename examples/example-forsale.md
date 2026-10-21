{gallery}
# Example listing — replace this with your own

(1) Widget **Alpha** — brand new, all original accessories included.
**Price: $100 USD** {color: #5865f2}

(2) Widget **Bravo** — lightly used, works perfectly.
**Price: $75 USD** {reserved}

(3) Widget **Charlie** — already gone.
**Price: $50 USD** {sold}

Shipping available anywhere, buyer covers insurance.

[Contact me on Discord](https://discord.com/users/000000000000000000)

---

### How this maps to your photo filenames (delete this section from your own file)

Two ways to put several photos under one item — pick whichever fits your files.

**Filename-based (what this example uses):** turn on **Group photos by filename** (Settings in the admin, or `AUTO_GROUP_BY_FILENAME` in the settings file), then name your photos so each item's filename prefix is a word that appears in that item's paragraph:

```
alpha_01.jpg, alpha_02.jpg    -> attach to (1), because "Alpha" is in its text
bravo_01.jpg                  -> attaches to (2), because "Bravo" is in its text
charlie_01.jpg, charlie_02.jpg -> attach to (3), because "Charlie" is in its text
```

The match is a plain, case-insensitive, whole-word search — no special tagging needed. The word has to be the *whole* filename prefix, exactly: a paragraph that says "Widget Model A" would **not** match `widgeta_01.jpg`; it would need the word `widgeta` in it. That setting is off by default, since it can falsely merge unrelated photos in a folder with no naming convention (see the README) — it's worth turning on specifically because this example relies on it.

**Explicit (works with any filenames, no convention needed):** add `{group: Name}` and `{photos: a.jpg, b.jpg, ...}` directives instead, e.g. replacing item (1)'s price line with:

```
**Price: $100 USD** {color: #5865f2} {group: Widget Alpha} {photos: img_0234.jpg, img_0891.jpg}
```

This works no matter what your photos happen to be named. (Directives written inside `inline code` or a code block, like the ones in this section, are shown as text and never acted on.) See the README's "Directive syntax" section for the full behavior — including how to attach photos to a paragraph without boxing them into a named group at all.
