/**
 * Minimal Markdown -> HTML converter covering the formatting people
 * actually use in a Discord message: headers, bold, italic, underline,
 * strikethrough, inline code, code blocks, links, blockquotes, and
 * bulleted/numbered lists. Everything is HTML-escaped first, so raw
 * HTML in the .md file is never executed.
 */
function inline_markdown($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // A link's address is set aside first (marked \x1A n \x1B), so a * _ ~ or ` inside it is never read as emphasis or code, which
    // would put tags into the href; the label stays open to emphasis. The marks are not text anybody types.
    $urls = [];
    $text = str_replace(["\x1A", "\x1B"], '', $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/', function ($m) use (&$urls) {
        $urls[] = $m[2];
        return '[' . $m[1] . '](' . "\x1A" . (count($urls) - 1) . "\x1B" . ')';
    }, $text);
    $text = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/s', '<u>$1</u>', $text);
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
    $text = preg_replace('/(?<![a-zA-Z0-9])_(.+?)_(?![a-zA-Z0-9])/s', '<em>$1</em>', $text);
    $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\(\x1A(\d+)\x1B\)/', function ($m) use ($urls) {
        return '<a href="' . $urls[(int) $m[2]] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $text);
    return preg_replace_callback('/\x1A(\d+)\x1B/', function ($m) use ($urls) { return $urls[(int) $m[1]] ?? ''; }, $text);   // (a mark left over by odd nesting becomes the address as plain text)
}

/** Strips Markdown syntax and {directives} down to plain text, for the OG description. */
function markdown_to_plaintext($text, $maxLen = 160) {
    // Code content is stripped/unwrapped before the {directive} pass below,
    // so a stray { or } inside a code block or inline code span can never
    // pair up with an unrelated brace outside it and eat text between them.
    $text = preg_replace('/```.*?```/s', ' ', $text);
    $text = preg_replace('/`([^`]+)`/', '$1', $text);
    $text = preg_replace('/\{[^}]*\}/', '', $text);
    $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
    $text = preg_replace('/[*_~#>]/', '', $text);
    $text = preg_replace('/^\(\d+\)\s*/m', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    if (function_exists('mb_strlen') && mb_strlen($text) > $maxLen) {
        $text = mb_substr($text, 0, $maxLen - 1) . '…';
    } elseif (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen - 1) . '…';
    }
    return unprotect_inline_code($text);
}

/**
 * Small clickable thumbnail strip + jump link + live-updatable view total
 * for a photo group, shown right under the paragraph that mentions it.
 * $status is '', 'sold', or 'reserved' — grayscales the thumbnails
 * accordingly (the SOLD/RESERVED tag itself is only shown once, above
 * the paragraph text — see markdown_to_html — not repeated here too).
 * $permalinkHtml, if given, is appended to the meta row (used to avoid
 * showing the same permalink icon twice when a paragraph matches more
 * than one group).
 */
function render_group_thumbs($key, $groupFiles, $allFiles, $photoViews, $status, $permalinkHtml = '') {
    // A folder shown as a cover card on this very page needs no strip of thumbnails under the paragraph that
    // mentions it: the card is right there (and carries the paragraph's sold/reserved/colour).
    if (isset($GLOBALS['folderMentionLinks'][$key])) return '';
    $slug = slugify($key);
    $count = count($groupFiles);
    $groupTotal = 0;
    foreach ($groupFiles as $gf) {
        $groupTotal += isset($photoViews[$gf]) ? (int) $photoViews[$gf] : 0;
    }
    $viewsHidden = (view_counts_shown() && ($groupTotal > 0 || (TRACK_VIEWS && is_admin_browser()))) ? '' : ' hidden';

    $html = '<div class="linked-photos' . ($status ? ' ' . $status : '') . '">';
    $html .= '<div class="linked-photos-thumbs">';
    foreach ($groupFiles as $gf) {
        $idx = array_search($gf, $allFiles, true);
        if ($idx === false) continue;
        $vt = video_tile_parts($gf, $GLOBALS['videoDurations'][$gf] ?? null);
        $img = '<img src="' . htmlspecialchars(img_url($gf, 'thumb'), ENT_QUOTES, 'UTF-8')
             . '" loading="lazy" ' . ($vt['inner'] === '' ? 'onclick="openLightbox(' . (int)$idx . ')" ' : '') . 'alt="'
             . htmlspecialchars($gf, ENT_QUOTES, 'UTF-8') . '">';
        $html .= $vt['inner'] === '' ? $img : '<span class="vthumb vtile"' . $vt['attrs'] . ' onclick="openLightbox(' . (int)$idx . ')">' . $img . $vt['inner'] . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="linked-photos-meta">';
    // A folder-group in 'overview' mode has no inline grid to scroll to:
    // its jump link opens the folder's own page instead.
    $folderHref = $GLOBALS['folderMentionLinks'][$key] ?? null;
    $html .= '<a class="linked-photos-jump" href="' . ($folderHref !== null ? htmlspecialchars($folderHref, ENT_QUOTES, 'UTF-8') : '#group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')) . '">View '
            . htmlspecialchars(media_count_label($groupFiles), ENT_QUOTES, 'UTF-8') . ($folderHref !== null ? ' &rarr;' : ' &darr;') . '</a>';
    $html .= '<span class="group-views' . $viewsHidden . '" data-group="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">&middot; '
            . number_format($groupTotal) . ' view' . ($groupTotal === 1 ? '' : 's') . '</span>';
    $html .= $permalinkHtml;
    $html .= '</div></div>';
    return $html;
}

/**
 * A bare thumbnail strip for a {photos:}-only paragraph (no {group:}) —
 * just the clickable thumbnails, with no "View N photos" jump link and
 * no aggregate view count, since these files were never boxed into a
 * real group in the first place (each keeps its own individual view
 * badge on its standalone card instead).
 */
function render_photo_thumbs_plain($clusterFiles, $allFiles, $permalinkHtml = '') {
    $html = '<div class="linked-photos plain"><div class="linked-photos-thumbs">';
    foreach ($clusterFiles as $gf) {
        $idx = array_search($gf, $allFiles, true);
        if ($idx === false) continue;
        $vt = video_tile_parts($gf, $GLOBALS['videoDurations'][$gf] ?? null);
        $img = '<img src="' . htmlspecialchars(img_url($gf, 'thumb'), ENT_QUOTES, 'UTF-8')
             . '" loading="lazy" ' . ($vt['inner'] === '' ? 'onclick="openLightbox(' . (int)$idx . ')" ' : '') . 'alt="'
             . htmlspecialchars($gf, ENT_QUOTES, 'UTF-8') . '">';
        $html .= $vt['inner'] === '' ? $img : '<span class="vthumb vtile"' . $vt['attrs'] . ' onclick="openLightbox(' . (int)$idx . ')">' . $img . $vt['inner'] . '</span>';
    }
    $html .= '</div>';
    if ($permalinkHtml !== '') {
        $html .= '<div class="linked-photos-meta">' . $permalinkHtml . '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Small 🔗 button that copies a link to #<anchorId> to the clipboard —
 * shared by a .md item's own anchor and a gallery group's anchor, since
 * both are just "copy a link to this hash" in the same way.
 */
function render_permalink_button($anchorId) {
    return '<a class="permalink-copy" href="#' . htmlspecialchars($anchorId, ENT_QUOTES, 'UTF-8')
         . '" title="Copy link">' . ui_icon('link') . '</a>';
}

/** Whether a rendered notes file has nothing to show: no text and no
 *  photos. That is what a file holding only a "# Title" (lifted out as the
 *  page title) or only directives renders to, and an empty box captioned
 *  with a filename tells a visitor nothing. The file still takes effect
 *  (its title, its directives) — only the empty box is left out. */
function note_box_is_empty($html) {
    if (preg_match('/<(img|video|iframe|hr)\b/i', $html)) return false;
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) === '';
}

/** Setup notices are for the owner. Once an admin password exists they are shown only to the owner signed in; before
 *  that (a fresh install, where there is nobody to sign in as) they are shown to whoever opens the page, since that is
 *  the person setting it up. */
function notices_visible() {
    return ADMIN_PASSWORD === '' || is_admin_browser();
}

/**
 * A collapsed-by-default banner surfacing $galleryNotices directly on
 * the page, not just in the HTML-comment fallback in the page source —
 * "view page source" isn't something most people would think to try.
 * Addressed explicitly to the gallery owner, so a visitor who clicks it
 * out of curiosity can see right away it isn't meant for them.
 */
function render_gallery_notices_banner($notices, $open = false) {
    if (empty($notices) || !notices_visible()) return '';
    $count = count($notices);
    $html = '<details class="gallery-notices"' . ($open ? ' open' : '') . '>';
    $html .= '<summary>' . ui_icon('warn') . ' ' . $count . ' setup notice' . ($count === 1 ? '' : 's') . ' for the gallery owner</summary>';
    $html .= '<ul>';
    foreach ($notices as $notice) {
        $html .= '<li>' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul></details>';
    return $html;
}

/**
 * Validates a {color: value} directive's value before it's ever used in
 * an inline style attribute — either a hex code (#abc, #aabbcc, #aabbccdd)
 * or a plain alphabetic CSS color word (e.g. "gold", "steelblue").
 */
function is_safe_color($value) {
    return (bool) preg_match('/^#[0-9a-f]{3,8}$/i', $value) || (bool) preg_match('/^[a-z]{2,20}$/i', $value);
}

/**
 * Masks every known {directive} in text with same-length blank spans,
 * preserving every other character's original position — unlike
 * actually removing them, this keeps byte offsets valid for
 * PREG_OFFSET_CAPTURE against the original string. Used before
 * mention-matching a group's name against raw .md content: without
 * this, a filename inside another paragraph's {photos: a.jpg, b.jpg}
 * list could falsely match as if it were mentioned in prose (a real
 * risk for short keys especially, but not limited to them). Also used
 * to detect whether a paragraph has any prose left after directives.
 */
function mask_directives($text) {
    return preg_replace_callback(
        '/\{\s*(?:color\s*:\s*[^}]+|sold|reserved|held|group\s*:\s*[^}]+|photos\s*:\s*[^}]+)\}/i',
        function ($m) { return str_repeat(' ', strlen($m[0])); },
        $text
    );
}

/**
 * Makes `inline code` inert to the directive parser. A notes file that
 * *documents* directive syntax (like the shipped example, or anyone
 * writing "use `{photos: a.jpg}` to ...") must not have that example
 * parsed as a real directive — it would raise "unknown file" notices and
 * could attach photos. Braces inside a same-line backtick span are
 * swapped for two control bytes, byte for byte, so every character offset
 * the ordering logic relies on is unchanged; unprotect_inline_code()
 * turns them back wherever text is shown. Applied once, where the notes
 * file is read. (Fenced ``` blocks are already skipped by the parser.)
 */
function protect_inline_code($text) {
    return preg_replace_callback('/`[^`\n]*`/', function ($m) {
        return strtr($m[0], ['{' => "\x01", '}' => "\x02"]);
    }, (string) $text);
}
function unprotect_inline_code($text) {
    return strtr((string) $text, ["\x01" => '{', "\x02" => '}']);
}

/**
 * True if this .md content's first non-blank line is exactly the
 * required "{gallery}" marker (case-insensitive). A file without it is
 * left completely alone — not parsed, not rendered — which is what lets
 * this script sit in a folder that already has its own unrelated .md
 * files (notes, a README, etc.) without swallowing them.
 */
function has_gallery_marker($content) {
    foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        return strcasecmp($trimmed, '{gallery}') === 0;
    }
    return false;
}

/** Removes a recognized leading "{gallery}" marker line, and nothing else. */
function strip_gallery_marker($content) {
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;
        unset($lines[$i]);
        break;
    }
    return implode("\n", $lines);
}

/** Every .md file sitting directly in a real folder, natural-sorted —
 *  every one, marker or not; the caller decides what counts as "active."
 *  Reused for the gallery root's own notes scan, a subfolder's own notes
 *  (see AUTO_GROUP_BY_FOLDER), and the admin tool's "every .md in this
 *  folder" listing, which deliberately includes non-marker ones too. */
function scan_md_candidates($realFolderPath) {
    $entries = @scandir($realFolderPath);
    if ($entries === false) return [];
    $candidates = array_filter($entries, function ($file) use ($realFolderPath) {
        if (!is_file("$realFolderPath/$file")) return false;
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'md';
    });
    usort($candidates, function ($a, $b) {
        return strnatcasecmp($a, $b);
    });
    return array_values($candidates);
}

/** Qualifying (marker-having) .md files in a real folder, with the
 *  marker already stripped from their content — same rule as the
 *  gallery root's own notes: only a .md whose first non-blank line is
 *  exactly "{gallery}" counts. Used for the root's own notes, and for a
 *  subfolder's own ?folder= page (see AUTO_GROUP_BY_FOLDER) — a Paris
 *  .md can't claim a StTropez photo simply because it's only ever
 *  evaluated against Paris's own already-scoped $groups/$files. */
function scan_gallery_md_files($realFolderPath) {
    $candidates = scan_md_candidates($realFolderPath);
    $files = [];
    $contents = [];
    $rejectedCount = 0;
    foreach ($candidates as $candidate) {
        $raw = file_get_contents("$realFolderPath/$candidate");
        if (has_gallery_marker($raw)) {
            $files[] = $candidate;
            $contents[$candidate] = protect_inline_code(strip_gallery_marker($raw));
        } else {
            $rejectedCount++;
        }
    }
    return ['files' => $files, 'contents' => $contents, 'rejectedCount' => $rejectedCount];
}

/**
 * Reads the {photos:}/{group:} directives of one level's notes: the gallery's
 * top-level notes, or the notes inside one folder. Both go through here, so a
 * directive means the same thing wherever it is written. A notes file can claim
 * the photos that sit directly in its own folder ($candidates, full paths); a name
 * is read relative to that folder ($prefix is '' at the top, 'Paris/' in Paris).
 * Problems are added to $notices. Returns ['claims' => path => true,
 * 'groups' => slug => [...], 'clusters' => [...]].
 */
function collect_explicit_directives($mdContents, $candidates, $prefix, $allFiles, &$notices) {
    $claims = [];
    $groups = [];
    $clusters = [];
    $offset = 0;
    foreach ($mdContents as $content) {
        foreach (extract_photo_directives($content) as $rec) {
            $validPhotos = [];
            foreach ($rec['photos'] as $typed) {
                $bare = basename($typed);
                $full = $prefix . $bare;
                if (!in_array($full, $candidates, true)) {
                    // A photo that lives in a subfolder is not "unknown": say where the directive can and can't reach.
                    $inFolder = false;
                    foreach ($allFiles as $f) {
                        if (strncmp($f, $prefix, strlen($prefix)) === 0 && strpos(substr($f, strlen($prefix)), '/') !== false && substr($f, -strlen('/' . $bare)) === '/' . $bare) { $inFolder = true; break; }
                    }
                    $notices[] = $inFolder
                        ? '"' . $typed . '" is inside a folder, and {photos:} only reaches photos directly in the same folder as the notes. To show a folder under a paragraph, write the folder\'s name in it.'
                        : 'Unknown file "' . $bare . '" referenced in a {photos:} directive — ignored.';
                    continue;
                }
                if (isset($claims[$full])) {
                    $notices[] = '"' . $bare . '" is referenced in more than one {photos:} directive — only the first applies.';
                    continue;
                }
                $validPhotos[] = $full;
            }
            // A directive-only paragraph only sees prose in its own paragraph (see GROUPING/order_within_zone), never a
            // description written just before it — a blank line between them is enough to disconnect them, which is easy
            // to write by accident. Flag it: it's not invalid, just probably not what was intended.
            if (!$rec['hasProse'] && $rec['precededByPlainProse']) {
                $notices[] = 'A {group:}/{photos:} paragraph right after a plain description isn\'t attached to it (a blank line separates them into different paragraphs) — merge them into one paragraph if that was intended.';
            }
            if (empty($validPhotos)) continue;
            $position = $offset + $rec['position'];
            if ($rec['groupName'] !== null && $rec['groupName'] !== '') {
                $slug = slugify($rec['groupName']);
                if ($slug === '') continue;
                if (isset($groups[$slug])) {
                    $notices[] = '{group: ' . $rec['groupName'] . '} is defined more than once — only the first definition applies.';
                    continue;
                }
                foreach ($validPhotos as $p) { $claims[$p] = true; }
                $groups[$slug] = ['name' => $rec['groupName'], 'files' => $validPhotos, 'position' => $position, 'hasProse' => $rec['hasProse']];
            } else {
                foreach ($validPhotos as $p) { $claims[$p] = true; }
                $clusters[] = ['files' => $validPhotos, 'position' => $position, 'hasProse' => $rec['hasProse']];
            }
        }
        $offset += strlen($content) + 1;
    }
    return ['claims' => $claims, 'groups' => $groups, 'clusters' => $clusters];
}

/**
 * Scans one .md file's raw content for {photos: a.jpg, b.jpg, ...}
 * directives — each optionally paired with a {group: Name} directive in
 * the same paragraph — respecting the same paragraph/code-fence rules
 * markdown_to_html uses (a blank line, or a line starting "(1)", starts
 * a new paragraph; header/list/blockquote lines are never paragraph
 * text). A bare {group:} with no {photos:} in the same paragraph isn't
 * returned here — it only ever renames an already-existing group at
 * render time (see markdown_to_html), so it doesn't affect membership.
 *
 * Returns an ordered list of ['position' => <char offset within
 * $content>, 'groupName' => string|null, 'photos' => string[]].
 */
function extract_photo_directives($content) {
    $content = str_replace("\r\n", "\n", $content);
    $lines = explode("\n", $content);
    $records = [];
    $paraLines = [];
    $paraStartPos = null;
    $pos = 0;
    $inCode = false;

    // Tracks whether the most recently flushed paragraph was plain prose
    // with no directive of its own — used to flag a likely-accidental
    // disconnect: a description followed by a blank line, then a
    // directive-only paragraph the author probably meant to attach to
    // it. Anything that isn't "a real paragraph with prose and nothing
    // else" (a header, list, blockquote, code fence, or a directive-
    // bearing paragraph already claimed by its own directive) resets
    // this to false, since something else now sits in between.
    $lastParaWasPlainProse = false;

    $flush = function () use (&$paraLines, &$paraStartPos, &$records, &$lastParaWasPlainProse) {
        if (empty($paraLines)) return;
        $photosRaw = null;
        $groupName = null;
        $hasProse = false;
        $hasAnyDirective = false;
        foreach ($paraLines as $l) {
            if ($photosRaw === null && preg_match('/\{\s*photos\s*:\s*([^}]+)\}/i', $l, $pm)) {
                $photosRaw = $pm[1];
            }
            if ($groupName === null && preg_match('/\{\s*group\s*:\s*([^}]+)\}/i', $l, $gm)) {
                $groupName = trim($gm[1]);
            }
            if (preg_match('/\{\s*(?:color\s*:\s*[^}]+|sold|reserved|held|group\s*:\s*[^}]+|photos\s*:\s*[^}]+)\}/i', $l)) {
                $hasAnyDirective = true;
            }
            // Anything left after masking every known directive is
            // prose — this is what decides whether the paragraph
            // gets an early ("mirrors the .md") position or falls
            // back to natural order (see compute_zone_order()).
            $stripped = trim(mask_directives($l));
            if ($stripped !== '') $hasProse = true;
        }
        if ($photosRaw !== null) {
            $photos = array_values(array_filter(array_map('trim', explode(',', $photosRaw))));
            if (!empty($photos)) {
                $records[] = [
                    'position' => $paraStartPos, 'groupName' => $groupName, 'photos' => $photos,
                    'hasProse' => $hasProse, 'precededByPlainProse' => $lastParaWasPlainProse,
                ];
            }
        }
        // A paragraph only "donates" adjacency to whatever comes next if
        // it's prose with no directive of its own — one already claimed
        // by its own directive shouldn't also be offered to a different,
        // later one.
        $lastParaWasPlainProse = $hasProse && !$hasAnyDirective;
        $paraLines = [];
        $paraStartPos = null;
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        $lineLen = strlen($line) + 1; // +1 for the \n explode() removed

        if (strpos($trimmed, '```') === 0) {
            $flush();
            $lastParaWasPlainProse = false;
            $inCode = !$inCode;
            $pos += $lineLen;
            continue;
        }
        if ($inCode) {
            $pos += $lineLen;
            continue;
        }
        if ($trimmed === '') {
            $flush();
            $pos += $lineLen;
            continue;
        }
        if (preg_match('/^(#{1,3})\s+/', $trimmed) || preg_match('/^>\s?/', $trimmed)
            || preg_match('/^[-*]\s+/', $trimmed) || preg_match('/^\d+\.\s+/', $trimmed)) {
            $flush();
            $lastParaWasPlainProse = false;
            $pos += $lineLen;
            continue;
        }
        if (preg_match('/^\(\d+\)\s+/', $trimmed)) {
            $flush();
        }
        if ($paraStartPos === null) {
            $paraStartPos = $pos;
        }
        $paraLines[] = $trimmed;
        $pos += $lineLen;
    }
    $flush();
    return $records;
}

