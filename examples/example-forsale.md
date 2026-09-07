{gallery}
# Example listing — replace this with your own

(1) Widget Model A — brand new, all original accessories included.
**Price: $100 USD** {color: #5865f2}

(2) Widget Model B — lightly used, works perfectly.
**Price: $75 USD** {reserved}

(3) Widget Model C — already gone.
**Price: $50 USD** {sold}

Shipping available anywhere, buyer covers insurance.

[Contact me on Discord](https://discord.com/users/000000000000000000)

---

### How this maps to your photo filenames

Two ways to group multiple photos under one item — pick whichever fits your files.

**Filename-based (what this example uses):** turn on `AUTO_GROUP_BY_FILENAME` in `index.php`, then name your photos so each item's filename prefix matches a word used in its paragraph:

```
widgeta_01.jpg, widgeta_02.jpg   -> matches "Widget Model A" via the word "widgeta"
widgetb_01.jpg                   -> matches "Widget Model B" via "widgetb"
widgetc_01.jpg, widgetc_02.jpg   -> matches "Widget Model C" via "widgetc"
```

The match is a plain, case-insensitive, whole-word search — no special tagging needed, just make sure the filename prefix appears somewhere in that item's paragraph text. `AUTO_GROUP_BY_FILENAME` is off by default, since it can falsely merge unrelated photos in a folder with no naming convention (see the README) — it's worth turning on specifically because this example relies on it.

**Explicit (works with any filenames, no convention needed):** add `{group: Name}` and `{photos: a.jpg, b.jpg, ...}` directives instead, e.g. replacing item (1)'s line with:

```
(1) Widget Model A — brand new, all original accessories included.
**Price: $100 USD** {color: #5865f2} {group: Widget Model A} {photos: img_0234.jpg, img_0891.jpg}
```

This works no matter what your photos happen to be named. See the README's "Directive syntax" section for the full behavior — including how to attach photos to a paragraph without boxing them into a named group at all.
