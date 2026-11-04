# Roadmap

Ideas that could be built but have no date. Nothing here is promised; it is kept so the reasoning isn't lost. Shipped work is in the [changelog](../CHANGELOG.md).

## Embedding a share link in another website

Let a link be shown inside a frame on someone else's site (a portfolio, say).

- **Today:** not possible. A link's opening page is sent with `frame-ancestors 'none'`, the link's cookie is `SameSite=Lax` (so it isn't sent from inside another site's frame), and Safari and Firefox limit cookies in frames. The admin and its pages must stay unframeable.
- **Sketch:** a per-link "Allow embedding on: example.com" that lets exactly those sites frame that link's pages; a `SameSite=None; Secure; Partitioned` cookie for those links (HTTPS only; Chrome and Edge, mostly Firefox, Safari unreliable, so Safari visitors would be offered "open in a new tab"); and a plain embedded view (no header, an "Open full size" link).
- **Note:** an open gallery's folder page isn't blocked from framing by the script, and needs no cookie.
