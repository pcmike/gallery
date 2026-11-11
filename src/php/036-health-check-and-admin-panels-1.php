/** What the page already tells the owner on the front page, brought into the admin (where there is room to name things and to act): rows
 *  of ['label', 'detail', 'html' => the button]. Not problems: they are never counted as something to fix. */
function health_headsups($dir, $files) {
    $out = []; $ext = $GLOBALS['mediaExtensions'] ?? [];
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $btn = function ($label, $attrs) use ($e) { return '<div class="health-act"><button type="button" class="secondary" ' . $attrs . '>' . $e($label) . '</button></div>'; };
    if (!AUTO_GROUP_BY_FOLDER) {
        [$hidden, $trunc] = hidden_subfolders_summary($dir, $ext);
        if ($hidden) {
            $total = array_sum(array_column($hidden, 'count')); $n = count($hidden);
            $names = array_map(function ($h) { return $h['name'] . ' (' . $h['count'] . ')'; }, array_slice($hidden, 0, 3));
            $out[] = ['label' => ($trunc ? 'At least ' : '') . number_format($total) . ' photo' . ($total === 1 ? '' : 's') . ' in ' . $n . ' subfolder' . ($n === 1 ? '' : 's') . ' ' . ($total === 1 ? 'isn\'t' : 'aren\'t') . ' shown',
                'detail' => 'Show subfolders is off, so ' . ($n === 1 ? 'the folder ' : 'the folders ') . implode(', ', $names) . ($n > 3 ? ' and ' . ($n - 3) . ' more' : '') . ' ' . ($n === 1 ? 'doesn\'t' : 'don\'t') . ' appear in your gallery.',
                'html' => $btn('Show subfolders', 'data-health-switch="AUTO_GROUP_BY_FOLDER"')];
        }
    }
    if (!ENABLE_VIDEO) {
        [$nv, $tv] = count_ignored_videos($dir);
        if ($nv > 0) $out[] = ['label' => ($tv ? 'At least ' : '') . $nv . ' video' . ($nv === 1 ? '' : 's') . ' (.mov / .mp4) ' . ($nv === 1 ? 'isn\'t' : 'aren\'t') . ' shown',
            'detail' => 'Show videos is off, so ' . ($nv === 1 ? 'it doesn\'t' : 'they don\'t') . ' appear in your gallery.',
            'html' => $btn('Show videos', 'data-health-switch="ENABLE_VIDEO"')];
    }
    $active = array_flip(scan_gallery_md_files($dir)['files']);
    $inactive = array_values(array_filter(scan_md_candidates($dir), function ($f) use ($active) { return !isset($active[$f]); }));
    if ($inactive) {
        $n = count($inactive);
        $out[] = ['label' => $n === 1 ? 'The notes file ' . $inactive[0] . ' isn\'t active' : $n . ' notes files aren\'t active (' . implode(', ', array_slice($inactive, 0, 3)) . ($n > 3 ? ' …' : '') . ')',
            'detail' => 'A notes file needs {gallery} as its very first line to be used by the gallery.',
            'html' => $btn('→ Notes', 'onclick="return adminGoTo(\'notes\');"')];
    }
    return $out;
}

/** One heads-up as its row of markup (the Health check prints these; the new-version buttons bring one in without a reload). */
function health_headsup_row_html($n) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    return '<div class="health-row health-note note"' . ($n['attr'] ?? '') . '>'
        . '<span class="health-mark">&#9432;</span>'
        . '<div class="health-main"><div class="health-label">' . $esc($n['label']) . '</div><div class="health-detail">' . $esc($n['detail']) . '</div></div>'
        . $n['html'] . '</div>';
}

/** The health panel at the top of Settings: the server-side rows from health_checks(), then three the browser fills in
 *  (see the script: the web server rules can only be tested by asking for addresses the way a visitor would). */
function render_admin_health_panel() {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $rows = health_checks($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $notes = array_merge(update_headsups(), health_headsups($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []));
    $loud = 0; $asideN = 0; $suggN = 0;
    foreach ($rows as $r) { if ($r[1] === 'bad' || $r[1] === 'warn') $loud++; elseif ($r[1] === 'aside') $asideN++; elseif ($r[1] === 'opt') $suggN++; }
    $strip = health_strip($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $mark = ['ok' => 'check', 'warn' => 'warn', 'bad' => 'warn'];
    $groups = [];
    foreach ($rows as $r) $groups[$r[0]][] = $r;
?>
    <section class="panel health-panel<?= $loud ? '' : ' is-checking' ?>" id="health-panel" data-csrf="<?= $esc(admin_csrf_token()) ?>"<?= update_check_due() ? ' data-update-due="1"' : '' ?>>
      <h2 class="section-title">Health check</h2>
      <p class="health-summary"><strong class="health-summary-text <?= $loud ? 'note-text' : 'muted' ?>"><?= $loud ? $esc($loud . ' thing' . ($loud === 1 ? '' : 's') . ' to look at.') : 'Checking…' ?></strong><?php if ($asideN): ?> <span class="muted health-aside-n"><?= $esc($asideN . ' set aside') ?></span><?php endif; ?> <span class="muted health-sugg-n"><?= $suggN ? $esc('· ' . $suggN . ' suggestion' . ($suggN === 1 ? '' : 's')) : '' ?></span>
        <span class="health-btns"><button type="button" class="secondary health-toggle" aria-expanded="false"><svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="#aab2ff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="ht-label">Details</span></button><button type="button" class="secondary health-refresh" data-health-refresh title="Check again" aria-label="Check again"><?= ui_icon('refresh') ?></button></span></p>
      <div class="health-strip" aria-label="The gallery in numbers"><?php foreach ($strip as $si): $sl = $si[0]; $sv = $si[1]; $sx = $si[2] ?? null; $sh = $si[3] ?? null; $st = $si[4] ?? ''; ?><?php if ($sx): ?><button type="button" class="hs-t hs-more" aria-expanded="false" aria-controls="hs-vols" title="<?= $esc(implode("\n", array_map(function ($l) { return $l[0] . ': ' . $l[1] . ' free of ' . $l[2]; }, $sx))) ?>"><b><?= $esc($sv) ?></b><small><?= $esc($sl) ?><i class="tri" aria-hidden="true"></i></small></button><?php else: ?><span class="hs-t"<?= $st !== '' ? ' title="' . $esc($st) . '"' : '' ?>><b><?= $sh !== null ? $sh : $esc($sv) ?></b><small><?= $esc($sl) ?></small></span><?php endif; ?><?php endforeach; ?></div>
      <?php foreach ($strip as $si): if (!empty($si[2])): ?><div class="hs-vols" id="hs-vols" hidden><p class="hs-vols-h">Free space on <?= count($si[2]) ?> disks, the lowest first</p><?php foreach ($si[2] as $l): ?><div><span><?= $esc($l[0]) ?></span><em><b><?= $esc($l[1]) ?></b> free of <?= $esc($l[2]) ?></em></div><?php endforeach; ?></div><?php endif; endforeach; ?>
      <p class="muted health-intro" style="margin-top:0;">This looks and tells you what it found. It changes nothing by itself, except that once a day it looks for a new version and once a week the first visit makes a backup. Only a button in a row does anything. A red or amber row is something to fix; a grey circle is a suggestion; an amber ! is worth a look; the rest is information.</p>
      <?php if ($notes): ?>
        <div class="health-group health-notes">
          <h3 class="subhead">Heads-up</h3>
          <?php foreach ($notes as $n) echo health_headsup_row_html($n), "\n"; ?>
        </div>
      <?php endif; ?>
      <?php foreach ($groups as $g => $items): $allQuiet = true; foreach ($items as $it) if ($it[1] === 'bad' || $it[1] === 'warn' || $it[1] === 'heads') $allQuiet = false; ?>
        <div class="health-group<?= $allQuiet ? ' all-quiet' : '' ?>">
          <h3 class="subhead"><?= $esc($g) ?></h3>
          <?php foreach ($items as [, $status, $label, $detail, $rowLink, $rowHtml, $rowHow]): $isLoud = $status === 'bad' || $status === 'warn'; ?>
            <div class="health-row health-<?= $esc($status) ?> <?= $isLoud ? 'loud' : 'quiet' ?><?= $status === 'opt' ? ' optional' : ($status === 'heads' ? ' heads' : '') ?>">
              <span class="health-mark <?= $status === 'ok' ? 'ok-text' : ($status === 'bad' ? 'warn-text' : ($status === 'warn' ? 'note-text' : ($status === 'heads' ? 'heads-mark' : 'muted'))) ?>"><?= isset($mark[$status]) ? ui_icon($mark[$status]) : ($status === 'opt' ? '&#9675;' : ($status === 'heads' ? '!' : '&bull;')) ?></span>
              <div><div class="health-label"><?= $esc($label) ?></div><?php if ($detail !== ''): ?><div class="health-detail"><?= $esc($detail) ?><?php if ($rowLink): ?> <a href="#<?= $esc($rowLink[0]) ?>" onclick="return adminGoTo('<?= $esc($rowLink[0]) ?>', '<?= $esc($rowLink[1]) ?>');"><?= $esc($rowLink[2]) ?></a><?php endif; ?></div><?php endif; ?><?= (string) $rowHtml ?><?= $rowHow ? health_how_html($rowHow) : '' ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <div class="health-group all-quiet" data-health-group="web">
        <h3 class="subhead">Web server (tested by your browser, just now)</h3>
        <?php foreach (array_merge([['lock', 'Private photos can\'t be fetched by their address'], ['tidy', 'Tidy folder links'], ['accel', 'nginx internal redirect']], ENABLE_SHARE_LINKS ? [['sharetidy', 'Tidy share addresses']] : [], is_file(visitlog_path()) ? [['visitlog', 'The visitor log can\'t be fetched by its address']] : [], backup_find() !== null ? [['backupfile', 'The backup can\'t be fetched by its address']] : []) as [$key, $label]): ?>
          <div class="health-row health-info quiet pending" data-health-row="<?= $key ?>"<?= ($key === 'lock' && !empty($GLOBALS['__lock_probe']['anyPrivate'])) ? '' : ' data-optional="1"' ?>>
            <span class="health-mark muted">&bull;</span>
            <div><div class="health-label"><?= $esc($label) ?></div><div class="health-detail" data-health="<?= $key ?>">Checking&hellip;</div><?php if ($key === 'sharetidy'): ?><a class="health-more" href="#site" onclick="return adminGoTo('site', 'server-setup');">&rarr; Server setup</a><?php endif; ?><?php if ($key === 'visitlog' || $key === 'backupfile') echo health_how_html(health_how_gallery_files(), $key, true); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
<?php
}

/** The Prepare media panel: makes every photo's tile pictures and records what each file says about itself (when taken, where), for the
 *  whole gallery, with a list of what the gallery knows and doesn't know about each folder. */
function render_admin_thumbnails_panel($csrfToken) {
    if (!ENABLE_THUMBNAIL_CACHE) return;   // (nothing to keep what is made in: the Thumbnails switch above says so)
    $r = media_readiness($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $left = $r['left'];
    $tidyTot = media_meta_enabled() ? media_tidy_run($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? [], true, false)['totals'] : null;
    $tidyAny = $tidyTot !== null && ($tidyTot['copies'] + $tidyTot['refreshed'] + $tidyTot['marks'] + $tidyTot['unread']) > 0;   // (the "refresh" reading is the wider one; saved locations of files not read yet can't be compared, so Prepare media comes first and the box stays)
    $backups = media_tidy_backups($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
?>
    <section class="media-panel in-group" id="media-panel" data-csrf="<?= $csrfToken ?>" data-left="<?= (int) $left ?>" data-total="<?= (int) $r['total'] ?>">
      <?php $hiddenFiles = 0; if (!AUTO_GROUP_BY_FOLDER) foreach ($GLOBALS['__admin_files'] ?? [] as $hf) if (strpos($hf, '/') !== false) $hiddenFiles++; ?>
      <details class="fold media-outer"<?= $left === 0 ? '' : '' ?>>
      <summary><span class="media-title">Prepare media</span> <span class="muted media-sum"><?= $left === 0 ? 'everything is ready' : 'optional: ' . number_format($r['total'] - $left) . ' of ' . number_format($r['total']) . ' ready ahead of time' ?></span></summary>
      <p class="muted" style="margin-top:0;">The gallery makes each picture, and reads each file's date and location, the first time someone looks. This does them all <strong>now</strong>, which helps before a big gallery goes public. Stop any time; what's done stays done. The location switches decide what <em>shows</em>, not this.</p>
      <p class="media-line"><strong class="media-count <?= $left === 0 ? 'ok-text' : '' ?>"><?= $left === 0 ? 'Everything is prepared: ' . number_format($r['total']) . ' file' . ($r['total'] === 1 ? '' : 's') . '.' : 'Prepared: ' . number_format($r['total'] - $left) . ' of ' . number_format($r['total']) . ' files.' ?></strong>
        <button type="button" class="secondary media-go"<?= $left === 0 ? ' hidden' : '' ?>>Prepare the rest</button>
        <button type="button" class="secondary media-stop" hidden>Stop</button><?php if ($hiddenFiles > 0): ?> <span class="muted">(<?= number_format($hiddenFiles) ?> of these are in folders that are hidden while Show subfolders is off)</span><?php endif; ?></p>
      <div class="media-bar" hidden><span></span></div>
      <details class="media-more">
        <summary>What the gallery knows about each folder</summary>
        <div class="media-report" aria-live="polite"><p class="muted">Loading&hellip;</p></div>
      </details>
      <div class="media-moved" hidden>
        <h3 class="subhead">Left behind in folders from elsewhere</h3>
        <details class="media-leftover-box" hidden>
          <summary>Tidy leftover thumbnails <span class="muted media-leftover-sum"></span></summary>
          <p class="hint">Thumbnails and video pictures in these folders that no photo here uses any more (photos deleted, renamed or replaced, or pictures made by another gallery that showed the same folders). <strong>Photos, captions, links, private flags and the read-once file data are never touched</strong>: only files named exactly like a made thumbnail or poster go.</p>
          <p class="hint media-leftover-list"></p>
          <p><label class="check"><input type="checkbox" class="media-leftover-ok"> No other gallery still shows these folders (the old one is retired)</label></p>
          <p><button type="button" class="secondary media-leftover-go" disabled>Remove the leftover thumbnails</button> <span class="media-leftover-out" aria-live="polite"></span></p>
        </details>
      </div>
      <h3 class="subhead media-tidy-head"<?= $tidyAny || $backups ? '' : ' hidden' ?>>Tidy up</h3>
      <details class="media-tidy-box"<?= $tidyAny ? '' : ' hidden' ?>>
        <summary>Tidy up saved locations</summary>
        <p class="hint">Older versions copied each photo's location into the folder's data; now the files are read once into the cache, so the copies can go. <strong>Captions, links, private flags, covers and each photo's Location switch are never touched</strong>, and each folder that changes is copied first (<code>.gallery-items.before-tidy-&hellip;.php</code>). Run <em>Prepare the rest</em> first. The first button only shows what would change.</p>
        <div class="media-tidy-modes">
          <label class="check"><input type="radio" name="media-tidy-mode" value="careful" checked> <strong>Careful.</strong> Remove only saved locations that match the file and have no label; the rest are kept and listed.</label>
          <label class="check"><input type="radio" name="media-tidy-mode" value="refresh"> <strong>Refresh from the files.</strong> I never typed a location: use the file's wherever it has one, even where the saved one differs. One with a label, or that the file can't confirm, is still kept. Old &ldquo;checked&rdquo; marks are cleared too.</label>
        </div>
        <p><button type="button" class="secondary media-tidy-preview">Show what would change</button></p>
        <div class="media-tidy-out" aria-live="polite"></div>
      </details>
      <div class="media-backups"><?= render_media_backups_html($backups) ?></div>
      <details class="media-again-box">
        <summary>Read everything again</summary>
        <p class="hint">Reads every file again, in case files were replaced or you want to be sure. It also re-reads photos an earlier version marked "checked, nothing found", which can make a location from the file show where there was none. A location you typed in or removed yourself is never changed.</p>
        <p><label class="check"><input type="checkbox" class="media-remake"> Also make the thumbnails again</label></p>
        <p><button type="button" class="secondary media-again">Read everything again</button></p>
      </details>
      </details>
    </section>
<?php
}

/** The two password rows of Settings, inside the Signing in and passwords group. */
function render_admin_password_rows($fromFile) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
        <p class="muted" style="margin:0 0 6px;">Stored as hashes, so they can't be shown again. Forgotten? Delete the <code>ADMIN_PASSWORD</code> line in <code>.gallery-config.php</code>, then open <code>?admin</code>: the gallery makes a file <code>gallery-setup-code.php</code> beside <code>index.php</code>, and you type the code in it on the page that asks you to choose a new password.</p>
        <div class="site-row">
          <div>
            <div class="name">Admin password <?php if (isset($fromFile['ADMIN_PASSWORD'])): ?><span class="src">your setting</span><?php endif; ?></div>
            <div class="help">Set. Leave blank to keep it; to change it use 8+ characters with a letter and a number. It can't be removed here.</div>
          </div>
          <div class="ctl col">
            <input type="password" name="pw_admin" autocomplete="new-password" placeholder="New admin password">
            <input type="password" name="pw_admin2" autocomplete="new-password" placeholder="Type it again">
            <span class="pwline"><button type="button" class="pw-btn secondary" data-pw="admin">Change admin password</button><span class="rowstate" role="status" aria-live="polite"></span></span>
          </div>
        </div>
        <div class="site-row">
          <div>
            <div class="name">Private-view password <?php if (isset($fromFile['PRIVATE_VIEW_PASSWORD'])): ?><span class="src">your setting</span><?php endif; ?></div>
            <div class="help"><?= PRIVATE_VIEW_PASSWORD === '' ? 'Not set: only you can see private items.' : 'Set.' ?> Give it to people who may see private items. Leave blank to keep it.</div>
            <?php if (PRIVATE_VIEW_PASSWORD !== ''): ?><div class="warn-note">Removing it ends private view for everyone you gave it to. Nothing is deleted.</div><?php endif; ?>
          </div>
          <div class="ctl col">
            <input type="password" name="pw_private" autocomplete="new-password" placeholder="New private-view password">
            <input type="password" name="pw_private2" autocomplete="new-password" placeholder="Type it again">
            <span class="pwline"><button type="button" class="pw-btn secondary" data-pw="private">Set private-view password</button><span class="rowstate" role="status" aria-live="polite"></span></span>
            <?php if (PRIVATE_VIEW_PASSWORD !== ''): ?><span class="pwline"><button type="button" class="pw-btn secondary" data-pw="private-remove">Remove it (turn private view off)</button><span class="rowstate" role="status" aria-live="polite"></span></span>
            <label class="muted nojs-only"><input type="checkbox" name="pw_private_remove" value="1"> Remove it (turn private view off)</label><?php endif; ?>
          </div>
        </div>

<?php
}

/** The option that says which country each address is in (the country file), as one row of the Signing in group: what it is, what it needs, and its buttons. */
function render_admin_geo_row($csrfToken) {
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $st = geo_state(); $have = is_file(geo_db_path());
?>
        <div class="site-row" id="geo-row" data-csrf="<?= $e($csrfToken) ?>" data-on="<?= COUNTRY_LOOKUP ? '1' : '' ?>">
          <div>
            <div class="name">Look up the country of each address <span class="ft-chip <?= COUNTRY_LOOKUP ? ($have ? 'ft-on' : 'ft-need') : 'ft-off' ?>"><?= COUNTRY_LOOKUP ? ($have ? 'On' : 'Waiting for the file') : 'Off' ?></span></div>
            <div class="help">Says which country each browser's addresses are in, so a sign-in from a new country is flagged. It uses a small file of address ranges this gallery downloads, then renews about monthly; no address is ever sent anywhere. Data: <a href="https://db-ip.com" target="_blank" rel="noopener">IP geolocation by DB-IP</a> (CC BY 4.0). Needs about 9 MB of disk (a 4 MB download).</div>
            <?php if (COUNTRY_LOOKUP): ?>
              <div class="geo-status<?= $have ? '' : ' geo-wait' ?>" data-geo-state><?php if ($have): ?><b><?= $e(($st['ym'] ?? '') !== '' ? date('F Y', (int) strtotime($st['ym'] . '-01')) : 'Current') ?> edition</b> in use · <?= $e(signin_size_text((int) @filesize(geo_db_path()))) ?> · fetched <?= $e(date('M j', (int) ($st['at'] ?: @filemtime(geo_db_path())))) ?> · next check <?= $e(($next = (int) @filemtime(geo_state_path()) + 7 * 86400) > time() ? date('M j', $next) : 'at the next visit') ?><?php else: ?>The file is not here yet: it is fetched at the next visit, or press Refresh now.<?php endif; ?><?= ($st['error'] ?? '') !== '' ? '<div class="geo-err">The last try (' . $e(date('M j, g:i a', (int) $st['triedAt'])) . ') did not work: ' . $e($st['error']) . '</div>' : '' ?></div>
            <?php endif; ?>
          </div>
          <div class="ctl col">
            <?php if (COUNTRY_LOOKUP): ?>
              <span class="pwline"><button type="button" class="secondary" data-geo="refresh">Refresh now</button><button type="button" class="secondary" data-geo="off">Turn off…</button><span class="rowstate" role="status" aria-live="polite"></span></span>
              <label class="muted" data-geo-del hidden><input type="checkbox" data-geo-delete> Also delete the file (about <?= $e(signin_size_text((int) @filesize(geo_db_path()))) ?>)</label>
            <?php else: ?>
              <span class="pwline"><button type="button" class="secondary" data-geo="on">Turn on…</button><span class="rowstate" role="status" aria-live="polite"></span></span>
              <div class="help" data-geo-need hidden></div>
            <?php endif; ?>
          </div>
        </div>
<?php
}

/** "today", "yesterday", "3 days ago". */
function signin_ago($t, $now = null) {
    $d = (int) floor((($now ?? time()) - $t) / 86400);
    return $d <= 0 ? 'today' : ($d === 1 ? 'yesterday' : $d . ' days ago');
}

/** The list of signed-in browsers, last in the Signing in and passwords group: who, on what, since when, from where, what looks wrong, and
 *  a way to sign each one out; below it the browsers signed out lately, with their histories. */
function render_admin_browsers_panel($csrfToken) {
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $rows = browser_rows(); $ended = BROWSER_ENDED_DAYS > 0 ? browser_ended_rows() : []; $now = time();
    $others = count(array_filter($rows, fn ($r) => !$r['current']));
    $hint = client_ip_proxy_hint();
?>
        <div class="site-row br-wide" id="browsers-row" data-csrf="<?= $e($csrfToken) ?>">
          <div class="name">Browsers signed in</div>
          <div class="help">Every phone or computer signed in as you or in private view, with the addresses it has come from. Sign one out and it must type the password again: a lost phone or a shared computer, with no new password for everyone. A red or amber mark means it looks different from when it signed in.</div>
          <?php if ($hint !== ''): ?><div class="warn-note"><?= $e($hint) ?> Until then every visitor looks like the proxy, so the addresses here tell you nothing.</div><?php endif; ?>
          <div class="br-scroll">
          <table class="br-table">
            <thead><tr><th>Browser</th><th>Signed in</th><th>Last here</th><th>Last address</th><th>Addresses</th><th>Device changes</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <?php foreach ($rows as $r): $hs = signin_history_stats($r['id']); ?>
            <tbody class="br-item <?= trim(($r['current'] ? 'br-me ' : '') . ($r['flags'] ? 'br-flagged' : '')) ?>" data-id="<?= $e($r['id']) ?>">
              <tr class="br-main">
                <td data-label="Browser"><strong class="br-who <?= $e($r['kind']) ?>"><?= $r['kind'] === 'owner' ? 'You (admin)' : 'Private view' ?></strong><?= $r['current'] ? ' <span class="br-this">this browser</span>' : '' ?><span class="br-dev"><?= $e($r['lab'] !== '' ? $r['lab'] : 'A device') ?></span></td>
                <td data-label="Signed in"><span class="br-v"><?= $e(date('M j', $r['made'])) ?></span></td>
                <td data-label="Last here"><span class="br-v"><?= $e(signin_ago($r['seen'], $now)) ?><span class="br-sub">stays until <?= $e(date('M j', $r['exp'])) ?></span></span></td>
                <td data-label="Last address"><span class="br-v"><?= $r['ip'] !== '' ? '<code>' . $e($r['ip']) . '</code>' . ($r['ccs'] ? ' <span class="br-cc">' . $e(end($r['ccs'])) . '</span>' : '') : '<span class="muted">none yet</span>' ?></span></td>
                <td data-label="Addresses"><span class="br-v"><button type="button" class="linklike br-num" data-br="details"><?= number_format($hs['ips']) ?></button><?= $r['peak'] > 0 ? '<span class="br-sub">most networks in 24 hours: <b>' . (int) $r['peak'] . '</b>' . ($r['peakAt'] > 0 ? ' (' . $e(date('M j', $r['peakAt'])) . ')' : '') . '</span>' : '' ?></span></td>
                <td data-label="Device changes"><span class="br-v"><button type="button" class="linklike br-num" data-br="details"><?= number_format($hs['dev']) ?></button><?= $hs['sys'] > 0 ? '<span class="br-sub">' . ($hs['sys'] === $hs['dev'] ? ($hs['dev'] === 1 ? 'it was' : 'all') : number_format($hs['sys']) . ' of them') . ' Apple system networking, not flagged</span>' : '' ?></span></td>
                <td class="br-acts"><button type="button" class="linklike br-more" data-br="details" aria-expanded="false">History</button><?php if (!$r['current']): ?><button type="button" class="secondary" data-br="cancel">Sign out</button><?php endif; ?></td>
              </tr>
              <?php foreach ($r['flags'] as $fname => [$fdetail, $fat, $floud]): ?>
              <tr class="br-flagrow"><td colspan="7"><div class="br-flag <?= $e($floud) ?>" data-flag="<?= $e($fname) ?>"><span><strong><?= $e(signin_flag_info($fname)[0]) ?></strong> · <?= $e($fdetail) ?> (<?= $e(date('M j, g:i a', $fat)) ?>)</span><button type="button" class="secondary" data-br="ack">Looks fine</button></div></td></tr>
              <?php endforeach; ?>
              <tr class="br-detailrow" hidden><td colspan="7"><div class="br-detail"></div></td></tr>
            </tbody>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tbody class="br-none"><tr><td colspan="7">None yet.</td></tr></tbody><?php endif; ?>
          </table>
          </div>
          <span class="pwline"><button type="button" class="secondary" data-br="others"<?= $others === 0 ? ' disabled' : '' ?>>Sign out all other browsers</button><span class="rowstate" role="status" aria-live="polite"></span></span>
          <?php if (BROWSER_ENDED_DAYS > 0): ?>
          <details class="br-ended">
            <summary>Recently signed out (<?= count($ended) ?>)<span class="muted"> · kept <?= (int) BROWSER_ENDED_DAYS ?> days</span></summary>
            <div class="br-scroll">
            <table class="br-table br-table-ended">
              <thead><tr><th>Browser</th><th>Signed in</th><th>Signed out</th><th>Why</th><th>Addresses</th><th>Device changes</th><th><span class="sr-only">Actions</span></th></tr></thead>
              <?php foreach ($ended as $r): $hasH = is_file(signin_history_path($r['id'])); $hs = $hasH ? signin_history_stats($r['id']) : null; ?>
              <tbody class="br-item" data-id="<?= $e($r['id']) ?>">
                <tr class="br-main">
                  <td data-label="Browser"><strong class="br-who <?= $e($r['kind']) ?>"><?= $r['kind'] === 'owner' ? 'You (admin)' : 'Private view' ?></strong><span class="br-dev"><?= $e($r['label'] !== '' ? $r['label'] : 'A device') ?></span></td>
                  <td data-label="Signed in"><span class="br-v"><?= $e(date('M j', (int) $r['made'])) ?></span></td>
                  <td data-label="Signed out"><span class="br-v"><?= $e(date('M j, g:i a', (int) $r['ended'])) ?></span></td>
                  <td data-label="Why"><span class="br-v"><b><?= $e(signin_reason_text($r['reason'])) ?></b><?= !empty($r['detail']) ? ' <span class="muted">· ' . $e($r['detail']) . '</span>' : '' ?></span></td>
                  <td data-label="Addresses"><span class="br-v"><?= $hs ? '<button type="button" class="linklike br-num" data-br="details">' . number_format($hs['ips']) . '</button>' : '<span class="muted">none</span>' ?></span></td>
                  <td data-label="Device changes"><span class="br-v"><?= $hs ? '<button type="button" class="linklike br-num" data-br="details">' . number_format($hs['dev']) . '</button>' . ($hs['sys'] > 0 ? '<span class="br-sub">' . ($hs['sys'] === $hs['dev'] ? ($hs['dev'] === 1 ? 'it was' : 'all') : number_format($hs['sys']) . ' of them') . ' Apple system networking, not flagged</span>' : '') : '<span class="muted">none</span>' ?></span></td>
                  <td class="br-acts"><?php if ($hasH): ?><button type="button" class="linklike br-more" data-br="details" aria-expanded="false">History</button><?php endif; ?></td>
                </tr>
                <tr class="br-detailrow" hidden><td colspan="7"><div class="br-detail"></div></td></tr>
              </tbody>
              <?php endforeach; ?>
              <?php if (!$ended): ?><tbody class="br-none"><tr><td colspan="7">None lately.</td></tr></tbody><?php endif; ?>
            </table>
            </div>
          </details>
          <?php endif; ?>
        </div>
<?php
}

/** One browser's history as the list's "History" opens it: the counts, then the stored lines as they are, newest first (the CSV has all of them). */
function render_browser_history_html($id) {
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $lines = signin_history_read($id);
    if (!$lines) return '<p class="muted">Nothing is recorded for this browser.</p>';
    $total = count($lines); $shown = array_slice($lines, -200);
    $h = '<p class="br-sum">' . $e(signin_stats_text(signin_history_stats($id, $lines))) . '</p>';
    $h .= '<p class="br-dl"><a href="?admin&amp;browserhist=' . $e($id) . '&amp;csv=1">Download all ' . number_format($total) . ' line' . ($total === 1 ? '' : 's') . ' (CSV)</a>' . ($total > count($shown) ? ' · showing the newest ' . number_format(count($shown)) : '') . '</p>';
    $h .= '<div class="br-log">';
    foreach (array_reverse($shown) as $l) {
        $h .= '<div class="br-ln"><span class="br-lt">' . $e(date('Y-m-d H:i:s', (int) $l['t'])) . '</span> <span class="br-le">' . $e($l['e'] ?? '') . '</span> <b class="br-lip">' . $e($l['ip']) . '</b> <span class="br-lc">' . $e(($l['cc'] ?? '') !== '' ? $l['cc'] : '--') . '</span> <span class="br-lu">' . $e((string) ($l['ua'] ?? '') !== '' ? $l['ua'] : '(nothing)') . '</span></div>';
    }
    return $h . '</div>';
}

/** ?admin&browserhist=<id>: that browser's history as HTML (what the list opens), or with &csv=1 all of it as a spreadsheet file. */
function admin_browser_history_route() {
    $id = (string) ($_GET['browserhist'] ?? '');
    if (!preg_match('/^[0-9a-f]{12}$/', $id) || !is_file(signin_history_path($id))) { http_response_code(404); echo 'No such history.'; return; }
    header('Cache-Control: no-store');
    if (($_GET['csv'] ?? '') === '1') {
        header('Content-Type: text/csv; charset=UTF-8');
        shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
        header('Content-Disposition: attachment; filename="signin-' . $id . '.csv"');
        echo "time,address,country,event,what it said it was\n";
        foreach (signin_history_read($id) as $l) echo implode(',', [link_csv_cell(date('Y-m-d H:i:s', (int) $l['t'])), link_csv_cell($l['ip']), link_csv_cell($l['cc'] ?? ''), link_csv_cell($l['e'] ?? ''), link_csv_cell($l['ua'] ?? '')]) . "\n";
        return;
    }
    admin_json_exit(['ok' => true, 'html' => render_browser_history_html($id)]);
}

/** The Settings panel. */
/** Features: what the gallery can do, and where each thing stands. No switches here (Settings is the only place with controls): every button
 *  jumps to the setting or opens the gallery with a pointer on the button in question ("Show me"). */
function render_admin_features_panel($adminEdited, $adminHidden) {
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $dir = $GLOBALS['dir']; $files = $GLOBALS['__admin_files'] ?? [];
    $fx = setting_effects($dir, $files);
    $root = gallery_root_href();
    $chip = function ($kind, $text) use ($e) { return '<span class="ft-chip ft-' . $kind . '">' . $e($text) . '</span>'; };
    $goSetting = function ($label, $id) use ($e) { return '<button type="button" class="secondary" onclick="return adminGoTo(\'site\', \'' . $e($id) . '\');">' . $e($label) . '</button>'; };
    $row = function ($name, $chipHtml, $text, $btnHtml, $attr = '') use ($e) { return '<div class="ft-row"' . $attr . '><div class="ft-main"><div class="ft-name">' . $e($name) . ' ' . $chipHtml . '</div><div class="ft-text">' . $e($text) . '</div></div><div class="ft-act">' . $btnHtml . '</div></div>'; };
    $onOff = function ($on) use ($chip) { return $on ? $chip('on', 'On') : $chip('off', 'Off'); };
    $private = count($adminHidden['folders'] ?? []) + count($adminHidden['items'] ?? []);
    $inactive = array_values(array_filter(scan_md_candidates($dir), function ($f) use ($dir) { return !in_array($f, scan_gallery_md_files($dir)['files'], true); }));
    $links = ENABLE_SHARE_LINKS ? count(array_filter((array) (links_read()['links'] ?? []), function ($l) { return is_array($l) && !link_is_public_log($l); })) : 0;
    $mdActive = scan_gallery_md_files($dir)['files'] !== [];
    ob_start();
?>
    <section class="panel ft-panel" id="features">
      <h2 class="section-title">Features</h2>
      <p class="muted" style="margin-top:0;">Two kinds of things: features you can switch on, and things you do right on the gallery. Nothing is hidden; what is off is just off.</p>
      <h3 class="subhead">Features you can switch on</h3>
      <p class="muted ft-sub">Each is switched on or off in Settings; these buttons take you straight to it.</p>
      <?= $row('Subfolders', $onOff(AUTO_GROUP_BY_FOLDER), AUTO_GROUP_BY_FOLDER ? ($fx['AUTO_GROUP_BY_FOLDER']['on'] !== '' ? 'Showing your subfolders ' . (folder_style_flat() ? 'flat, all their photos on one page, folder by folder' . (FOLDER_FLAT_NAMES ? ', with their names' . (FOLDER_FLAT_NOTES ? ' and notes' : '') : '') : 'as cover cards') . '.' : 'No subfolders here yet.') : $fx['AUTO_GROUP_BY_FOLDER']['off'], $goSetting(AUTO_GROUP_BY_FOLDER ? 'Folder style →' : 'Turn on →', AUTO_GROUP_BY_FOLDER ? 'setting-FOLDER_DISPLAY_MODE' : 'setting-AUTO_GROUP_BY_FOLDER')) ?>
      <?= $row('Videos', $onOff(ENABLE_VIDEO), ENABLE_VIDEO ? 'Videos play in the viewer, sent as recorded.' : 'Shows .mov and .mp4 files. ' . $fx['ENABLE_VIDEO']['off'], $goSetting(ENABLE_VIDEO ? 'Settings →' : 'Turn on →', 'setting-ENABLE_VIDEO')) ?>
      <?= $row('Photo locations and maps', $onOff(ENABLE_PHOTO_LOCATION), ENABLE_PHOTO_LOCATION ? 'Each folder and photo can show its location, and each folder its own map.' : $fx['ENABLE_PHOTO_LOCATION']['off'], $goSetting(ENABLE_PHOTO_LOCATION ? 'Settings →' : 'Turn on →', 'setting-ENABLE_PHOTO_LOCATION')) ?>
      <?= $row('Share links', $onOff(ENABLE_SHARE_LINKS), ENABLE_SHARE_LINKS ? ($links > 0 ? $links . ' link' . ($links === 1 ? '' : 's') . ' made.' : 'None made yet: press Share on a private folder, or press Select, tick photos and press Share….') : 'Temporary links that let someone see private photos, with a log of what they looked at.', $goSetting(ENABLE_SHARE_LINKS ? 'Settings →' : 'Turn on →', 'setting-ENABLE_SHARE_LINKS')) ?>
      <?php if (gallery_is_closed()): ?>
      <?= $row('Visitor downloads', $chip('opt', 'Per link'), 'The gallery is closed, so strangers see nothing to download. Each share link has its own choice of whether its visitors may download.', '<button type="button" class="secondary" onclick="return adminGoTo(\'links\');">→ Shares</button>') ?>
      <?php else: ?>
      <?= $row('Visitor downloads', !ENABLE_PUBLIC_DOWNLOAD ? $chip('off', 'Off') : $chip('on', 'On'), !ENABLE_PUBLIC_DOWNLOAD ? 'Visitors cannot download original photos. Switch it on in a folder\'s settings.' : 'Visitors can download the photos in the folders you switched on (never a ZIP).', '<button type="button" class="secondary" onclick="return adminGoTo(\'settings\');">Folder settings →</button>') ?>
      <?php endif; ?>
      <?= $row('View counts and a visitor log', (TRACK_VIEWS || LOG_PUBLIC_VISITS) ? $chip('on', 'On') : $chip('off', 'Off'), (TRACK_VIEWS || LOG_PUBLIC_VISITS) ? 'Counting views' . (LOG_PUBLIC_VISITS ? ' and logging visitors to the public address.' : '.') : 'See how often photos are looked at, and by whom.', $goSetting((TRACK_VIEWS || LOG_PUBLIC_VISITS) ? 'Settings →' : 'Turn on →', 'setting-TRACK_VIEWS')) ?>
      <?= $row('Country lookup', $onOff(COUNTRY_LOOKUP), COUNTRY_LOOKUP ? (is_file(geo_db_path()) ? 'Saying which country each signed-in browser\'s addresses are in, from a file kept on this server.' : 'Switched on, but the country file is not here yet.') : 'Says which country a signed-in browser is used from, so a new country can be flagged. Downloads one small file.', $goSetting(COUNTRY_LOOKUP ? 'Settings →' : 'Turn on →', 'geo-row')) ?>
      <?= $row('Notes', $inactive ? $chip('need', 'Needs setup') : ($mdActive ? $chip('on', 'On') : $chip('off', 'Not used')), $inactive ? (count($inactive) === 1 ? $inactive[0] . ' is not active: it is missing {gallery}.' : count($inactive) . ' notes files are not active: they are missing {gallery}.') : ($mdActive ? 'A notes page is in use.' : 'A notes.md with {gallery} on its first line puts words around your photos.'), '<button type="button" class="secondary" onclick="return adminGoTo(\'notes\');">' . ($inactive ? 'Fix it →' : 'Notes →') . '</button>') ?>
      <?= $row('Thumbnails', $onOff(ENABLE_THUMBNAIL_CACHE), ENABLE_THUMBNAIL_CACHE ? 'Small copies make pages load fast; made as people look.' : 'Photos are sent at full size and iPhone (HEIC) photos cannot show.', $goSetting('Settings →', 'setting-ENABLE_THUMBNAIL_CACHE')) ?>
      <?= $row('Short share addresses', $chip('opt', 'Optional'), 'Links look like …/?k7Q… until the server has one rule; then …/trip/k7Q… or a name of your own.', $goSetting('Open the rule →', 'server-setup'), ' data-ft-sharetidy data-ft-was="Links look like …/?k7Q… until the server has one rule; then …/trip/k7Q… or a name of your own."') ?>
      <h3 class="subhead">Things you do on the gallery itself</h3>
      <p class="muted ft-sub">These are not settings; they are buttons on the photos. "Show me" opens the gallery with that button pointed out.</p>
      <?php
        // The buttons live on photo tiles, so "Show me" opens a page that has photos of its own: the top level when it has any, otherwise the
        // first folder that does (a gallery whose photos all sit in folders has nothing to point at on its front page).
        $showAt = '';
        foreach ($files as $f) { $i = strrpos($f, '/'); if ($i === false) { $showAt = ''; break; } if ($showAt === '') $showAt = substr($f, 0, $i); }
        $anyPhoto = !empty($files);
        $show = function ($key) use ($e, $root, $showAt, $anyPhoto) { if (!$anyPhoto) return '<span class="muted">Add a photo first</span>'; return '<a class="btn-link secondary" href="' . $e($root . '?' . ($showAt !== '' ? 'folder=' . rawurlencode($showAt) . '&' : '') . 'show=' . $key) . '">Show me</a>'; };
        echo $row('Make a photo or folder private', $chip('off', $private . ' private'), 'Press the lock in a photo\'s corner, or on a folder card.', $show('lock'));
        echo $row('Add a caption, a place or a link', '', 'Press the pencil on a photo.', $show('pencil'));
        echo $row('Choose a folder\'s cover picture', '', 'Press the star on a photo.', $show('star'));
        echo $row('Act on many photos at once', '', 'Press Select (the tick in a circle in the icon strip), then tick photos: make them private or public' . (ENABLE_SHARE_LINKS ? ', share them' : '') . ', or place them side by side.', $show('select'));
        echo $row('Write a page around your photos', '', 'Add a notes.md with {gallery} on its first line. A photo named in a paragraph appears under it.', '<button type="button" class="secondary" onclick="return adminGoTo(\'notes\');">How it works</button>');
        echo $row('Take a backup', '', 'A ZIP of everything the gallery has made, with a README that says how to put it back.', $goSetting('Download ZIP →', 'files-made'));
      ?>
    </section>
<?php
    return ob_get_clean();
}

/** For the switches whose effect can be counted (Show subfolders, Show videos, Allow photo locations): one sentence for each state, with the
 *  real numbers and names, so the row says what turning it on shows and what turning it off would hide. '' means nothing to say. */
function setting_effects($dir, $files) {
    $ext = $GLOBALS['mediaExtensions'] ?? [];
    $plural = function ($n, $one, $many) { return number_format($n) . ' ' . ($n === 1 ? $one : $many); };
    $out = [];
    // subfolders
    $top = [];
    foreach ($files as $f) if (($i = strpos($f, '/')) !== false) { $t = substr($f, 0, $i); $top[$t] = ($top[$t] ?? 0) + 1; }
    [$hid] = AUTO_GROUP_BY_FOLDER ? [[]] : hidden_subfolders_summary($dir, $ext);
    $names = function ($list) { $n = count($list); $shown = array_slice($list, 0, 3); return implode(', ', $shown) . ($n > 3 ? ' and ' . ($n - 3) . ' more' : ''); };
    $out['AUTO_GROUP_BY_FOLDER'] = [
        'off' => $hid ? 'Turning this on shows ' . $plural(array_sum(array_column($hid, 'count')), 'photo', 'photos') . ' in ' . $plural(count($hid), 'folder', 'folders') . ' to visitors: ' . $names(array_column($hid, 'name')) . '.' : 'Each subfolder you add later will show, with everything in it.',
        'on' => $top ? 'Turning this off hides ' . $plural(count($top), 'folder', 'folders') . ' and ' . $plural(array_sum($top), 'photo', 'photos') . ' from visitors (' . $names(array_keys($top)) . '); links to them stop working. Nothing is deleted.' : '',
    ];
    // videos
    $vids = 0; foreach ($files as $f) if (is_video_file($f)) $vids++;
    $ignored = ENABLE_VIDEO ? 0 : count_ignored_videos($dir)[0];
    $out['ENABLE_VIDEO'] = [
        'off' => $ignored > 0 ? 'Turning this on shows ' . $plural($ignored, 'video', 'videos') . ' to visitors.' : 'Videos you add later will show, sent as recorded.',
        'on' => $vids > 0 ? 'Turning this off hides ' . $plural($vids, 'video', 'videos') . ' from visitors. Nothing is deleted.' : '',
    ];
    // locations
    $with = 0; $byFolder = [];
    foreach ($files as $f) { $i = strrpos($f, '/'); $byFolder[$i === false ? '' : substr($f, 0, $i)][] = $f; }
    if (count($byFolder) <= 60 && media_meta_enabled()) {
        foreach ($byFolder as $path => $list) { $real = $path === '' ? $dir : resolve_real_path($dir, $path); if ($real) $with += media_folder_summary($dir, $real, $list)['fromFile']; }
    }
    $out['ENABLE_PHOTO_LOCATION'] = [
        'off' => $with > 0 ? $plural($with, 'photo has', 'photos have') . ' a location in the file. Turning this on lets each folder and photo show it; nothing shows until one opts in.' : 'No photo has a location yet. Turning this on lets each folder and photo show one.',
        'on' => 'Turning this off hides every location and map from visitors. Nothing is deleted.',
    ];
    return $out;
}

function render_admin_site_settings($csrfToken) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $state = gallery_config_load();
    $path = gallery_config_path();
    $hash = md5(is_file($path) ? (string) file_get_contents($path) : '');
    $defaults = $GLOBALS['__gallery_defaults'] ?? [];
    $fromFile = array_flip(array_unique($GLOBALS['__gallery_config_applied'] ?? []));
    $show = function ($v) { return is_bool($v) ? ($v ? 'on' : 'off') : (is_array($v) ? ($v ? count($v) . ' item' . (count($v) === 1 ? '' : 's') : 'none') : ($v === '' ? 'none' : (string) $v)); };
    $broken = !in_array($state['status'], ['absent', 'ok'], true);
    $defByName = []; foreach (gallery_setting_definitions() as $g0) foreach ($g0['items'] as $i0) $defByName[$i0['name']] = $i0;
    // A setting that only matters while another is on (and that one while a third is on ...) is hidden unless the whole chain is on.
    $settingOn = function ($d) use (&$settingOn, $defByName) {
        if (empty($d['requires'])) return true;
        $r = $d['requires'];
        if (!(defined($r) && constant($r))) return false;
        return isset($defByName[$r]) ? $settingOn($defByName[$r]) : true;
    };
    $settingDepth = function ($d) use (&$settingDepth, $defByName) {   // how many switches deep a row hangs (the pin grouping: map, then locations)
        if (empty($d['requires'])) return 0;
        return 1 + (isset($defByName[$d['requires']]) ? $settingDepth($defByName[$d['requires']]) : 0);
    };
    $effects = setting_effects($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $orderPairKey = function () { return (PHOTO_SORT_ORDER === 'mtime' ? 'taken' : PHOTO_SORT_ORDER) . '_' . (PHOTO_SORT_DESC ? 'desc' : 'asc'); };
    $orderPairOptions = ['taken_asc' => 'Date, oldest first', 'taken_desc' => 'Date, newest first', 'filename_asc' => 'Name, A to Z', 'filename_desc' => 'Name, Z to A'];
?>
    <section class="panel site-settings">
      <div class="site-head"><h2 class="section-title">Settings</h2><div class="site-tools" data-sg-tools hidden><button type="button" class="linklike" data-sg="all">Expand all</button><span class="sg-sep"> &middot; </span><button type="button" class="linklike" data-sg="none">Collapse all</button></div></div>
      <p class="muted" style="margin-top:0;">Whole-gallery settings. <strong>Each change saves as you make it</strong>, in <code>.gallery-config.php</code> (it survives replacing <code>index.php</code>). Your photos, captions and stats are never touched.</p>
      <?php if ($broken): ?>
        <div class="flash error">The settings file exists but can't be read as settings, so this screen won't change it (that could overwrite what's in it). Fix or delete <code>.gallery-config.php</code>, then reload.</div>
      <?php endif; ?>
      <?php if (!empty($GLOBALS['__site_settings_manual'])): ?>
        <p class="muted">Create a file named <code>.gallery-config.php</code> next to <code>index.php</code> containing exactly this:</p>
        <textarea readonly rows="12" style="width:100%; font-family:ui-monospace,monospace;" onfocus="this.select()"><?= $esc($GLOBALS['__site_settings_manual']) ?></textarea>
      <?php endif; ?>
      <p class="muted sitesync" hidden>Saved. Other tabs refresh when you leave this one.</p>
      <div class="site-find" data-site-find-wrap hidden><input type="search" data-site-find placeholder="Search settings" aria-label="Search settings" autocomplete="off" spellcheck="false" enterkeyhint="search"><p class="muted site-find-count" data-site-find-count role="status" aria-live="polite" hidden></p></div>
      <form method="post" class="site-settings-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_site_settings">
        <input type="hidden" name="config_hash" value="<?= $esc($hash) ?>">
        <?php $groupNo = 0; foreach (gallery_setting_definitions() as $group): $groupNo++; ?>
          <details class="site-group"<?= $group['title'] === 'Server setup (advanced)' ? ' id="server-setup"' : '' ?>>
          <summary><span class="site-group-title"><?= $esc($group['title']) ?></span> <span class="muted"><?php $nShown = count(array_filter($group['items'], function ($i) { return empty($i['in']); })) + ($group['title'] === 'Signing in and passwords' ? 2 : 0); echo $nShown; ?> setting<?= $nShown === 1 ? '' : 's' ?><?php
            $hidAll = array_filter($group['items'], function ($i) { return empty($i['in']) && !empty($i['hidden_when']); });   // (settings that are out of the way while another is on: counted, and said, with the reason on hover)
            if ($hidAll): $nHid = gallery_is_closed() ? count($hidAll) : 0; ?><span class="site-hid" data-hid-group<?= $nHid ? '' : ' hidden' ?> title="<?= $esc(implode(' ', array_map(function ($i) { return $i['label'] . ': ' . ($i['hidden_why'] ?? 'Not needed with the current settings.'); }, $hidAll))) ?>"> (<span data-hid-n><?= $nHid ?></span> hidden)</span><?php endif; ?></span></summary>
          <?php if ($group['title'] === 'Signing in and passwords'): render_admin_password_rows($fromFile); endif; ?>
          <?php foreach ($group['items'] as $d): if (!empty($d['in'])) continue; $name = $d['name']; $now = defined($name) ? constant($name) : ($defaults[$name] ?? null); $def = $defaults[$name] ?? null;
            if ($name === 'FOLDER_DISPLAY_MODE') $now = in_array($now, ['flat', 'inline'], true) ? 'flat' : 'overview';   // (the older words show as today's two choices)
            // "default: …" is part of the tag beside the name ("your setting · default: off"), shown only while the value differs from the default
            $showDefault = !in_array($d['type'], ['folders', 'sortfirst', 'pathlist'], true);
            $defKey = $nowKey = $defText = ''; $isDef = true;
            if (!$showDefault) { /* (a list: no default line) */ }
            elseif ($name === 'PHOTO_SORT_ORDER') { $defKey = ($def === 'mtime' ? 'taken' : $def) . '_' . (($defaults['PHOTO_SORT_DESC'] ?? false) ? 'desc' : 'asc'); $nowKey = $orderPairKey(); $defText = $orderPairOptions[$defKey] ?? $defKey; }
            else { $defKey = is_bool($def) ? ($def ? '1' : '0') : (string) $def; $nowKey = is_bool($now) ? ($now ? '1' : '0') : (string) $now; $defText = ($d['type'] === 'enum' && isset($d['options'][$def])) ? $d['options'][$def] : $show($def); }
            if ($showDefault) $isDef = $defKey === $nowKey || (is_numeric($defKey) && is_numeric($nowKey) && (float) $defKey === (float) $nowKey); ?>
            <?php $reqOn = $settingOn($d);
              $swOk = true;   // (a row that only matters for one choice of another setting, such as the Flat switches under Folder style, is shown only then)
              if (!empty($d['shown_when'])) { [$swName, $swWant] = explode('=', $d['shown_when'], 2); $swNow = $swName === 'FOLDER_DISPLAY_MODE' ? (in_array(FOLDER_DISPLAY_MODE, ['flat', 'inline'], true) ? 'flat' : 'overview') : (string) (defined($swName) ? constant($swName) : ''); $swOk = $swNow === $swWant; } ?>
            <div class="site-row<?= !empty($d['requires']) ? ' sub-row' . ($settingDepth($d) >= 2 ? ' depth-2' : '') : '' ?>" id="setting-<?= $esc($name) ?>"<?= !empty($d['requires']) ? ' data-requires="set_' . $esc($d['requires']) . '"' . ($reqOn && $swOk ? '' : ' hidden') : '' ?><?= !empty($d['shown_when']) ? ' data-shown-when="' . $esc($d['shown_when']) . '"' . (empty($d['requires']) && !$swOk ? ' hidden' : '') : '' ?><?= !empty($d['hidden_when']) ? ' data-hidden-when="set_' . $esc($d['hidden_when']) . '"' . (gallery_is_closed() ? ' hidden' : '') : '' ?>>
              <div>
                <div class="name"><?= $esc($d['label']) ?>
                  <?php if ($showDefault): ?><span class="src<?= $isDef ? ' is-default' : '' ?>" data-default="<?= $esc($defKey) ?>" title="This value is not the default, so it is kept in .gallery-config.php">your setting &middot; default: <?= $esc($defText) ?></span>
                  <?php elseif (isset($fromFile[$name])): ?><span class="src" title="This value comes from .gallery-config.php">your setting</span><?php endif; ?></div>
                <div class="help"><?= $esc($d['help']) ?></div>
                <?php if (($d['status'] ?? '') === 'ffmpeg'): $ffFound = ffmpeg_binary(); ?>
                <div class="help ffstatus <?= $ffFound !== '' ? 'ok-text' : 'warn-text' ?>"><strong>ffmpeg on this server: <?= $ffFound !== '' ? 'found' : 'not found' ?></strong> <?= $ffFound !== '' ? '(' . $esc($ffFound) . ')' : 'Videos show the browser\'s own first frame. Installed somewhere unusual? Set "FFMPEG_PATH" in .gallery-config.php.' ?></div>
                <?php endif; ?>
                <?php if (($d['status'] ?? '') === 'earlierviews'): ?>
                <div class="earlier-views" data-earlier data-csrf="<?= $esc($csrfToken) ?>" hidden>
                  <p class="earlier-text"></p>
                  <p class="earlier-act"><button type="button" class="secondary earlier-add">Add earlier views to the counts</button></p>
                  <p class="hint earlier-hint">Adds each folder's once, in one press. The old numbers are not changed, and it can't be undone.</p>
                </div>
                <?php endif; ?>
                <?php if (($d['status'] ?? '') === 'visitlog' && !visitlog_check_quiet()[0]): ?>
                <div class="help warn-text"><strong>Needs SQLite.</strong> <?= $esc(visitlog_check_quiet()[1]) ?></div>
                <?php endif; ?>
                <?php if (($d['status'] ?? '') === 'visitlog' && $name === 'LOG_PUBLIC_VISITS' && gallery_is_closed()): ?>
                <div class="help">The gallery is closed, so nobody opens it without a link: nothing more is logged here while it stays closed.</div>
                <?php endif; ?>
                <?php if (($d['status'] ?? '') === 'clientip'): ?>
                <div class="help">Your address as the gallery sees it: <strong><?= $esc(client_ip()) ?></strong><?= client_country() !== '' ? ' (' . $esc(client_country()) . ')' : '' ?><?= client_ip_proxy_hint() !== '' ? '. ' . client_ip_proxy_hint() : '' ?></div>
                <?php endif; ?>
                <?php if (($d['status'] ?? '') === 'accel'): $accelBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'); ?>
                <div class="help accel-status" data-accel-status data-accel-current="<?= $esc(NGINX_ACCEL_REDIRECT_PREFIX) ?>" data-accel-candidate="<?= $esc($accelBase) ?>"></div>
                <?= render_accel_rule_help() ?>
                <?php endif; ?>
                <?php if (isset($effects[$name])): ?>
                <?php foreach (['off', 'on'] as $st): if ($effects[$name][$st] === '') continue; ?>
                <div class="risk effect" data-effect="<?= $st ?>"<?= ($st === 'on') === (bool) $now ? '' : ' hidden' ?>><?= $esc($effects[$name][$st]) ?></div>
                <?php endforeach; ?>
                <?php else: ?>
                <?php if (!empty($d['risk'])): ?><div class="risk"<?= ($d['status'] ?? '') === 'accel' ? ' data-accel-risk hidden' : '' ?>><?= $esc($d['risk']) ?></div><?php endif; ?>
                <?php if (!empty($d['offwarn'])): ?><div class="warn-note offnote" hidden><?= $esc($d['offwarn']) ?></div><?php endif; ?>
                <?php endif; ?>
              </div>
              <div class="ctl">
                <?php if ($d['type'] === 'bool'): ?>
                  <input type="hidden" name="set_<?= $esc($name) ?>" value="0">
                  <label class="switch"><input type="checkbox" name="set_<?= $esc($name) ?>" value="1" <?= $now ? 'checked' : '' ?><?= ($name === 'LOG_PUBLIC_VISITS' && !$now && !visitlog_check_quiet()[0]) ? ' disabled' : '' ?> data-initial="<?= $now ? '1' : '0' ?>" data-label="<?= $esc($d['label']) ?>"<?php if (!empty($d['offwarn'])): ?> data-offwarn="<?= $esc($d['offwarn']) ?>"<?php endif; ?>><span class="slider"></span></label>
                <?php elseif ($d['type'] === 'sortfirst'): $sfNames = sort_priority_names(); $sfOrder = sort_priority_list(); $sfOn = []; foreach ($sfNames as $k => $nm) $sfOn[$k] = (bool) constant($nm[2]); $sfOrder = array_merge(array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return $sfOn[$k]; })), array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return !$sfOn[$k]; }))); ?>
                  <div class="sortfirst" data-name="set_<?= $esc($name) ?>" data-order="<?= $esc(implode(',', $sfOrder)) ?>">
                    <ol class="sf-list">
                    <?php foreach ($sfOrder as $k): $nm = $sfNames[$k]; ?>
                      <li data-kind="<?= $esc($k) ?>">
                        <button type="button" class="sf-grip" aria-label="Move <?= $esc($nm[1]) ?>: drag it, or press the up and down arrow keys" title="Drag to reorder"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><g fill="currentColor"><circle cx="9" cy="6" r="1.7"/><circle cx="15" cy="6" r="1.7"/><circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/><circle cx="9" cy="18" r="1.7"/><circle cx="15" cy="18" r="1.7"/></g></svg></button>
                        <span class="sf-name"><?= $esc($nm[0]) ?></span>
                        <input type="hidden" name="set_<?= $esc($nm[2]) ?>" value="0">
                        <label class="switch"><input type="checkbox" name="set_<?= $esc($nm[2]) ?>" value="1" <?= $sfOn[$k] ? 'checked' : '' ?> data-initial="<?= $sfOn[$k] ? '1' : '0' ?>" data-label="<?= $esc($nm[0]) ?> first" aria-label="<?= $esc($nm[0]) ?> first"><span class="slider"></span></label>
                      </li>
                    <?php endforeach; ?>
                    </ol>
                    <p class="sf-summary" aria-live="polite"><?= $esc(sort_priority_summary($sfOrder, $sfOn)) ?></p>
                  </div>
                <?php elseif ($name === 'PHOTO_SORT_ORDER'): ?>
                  <?= ui_menu('pair_PHOTO_ORDER', $orderPairOptions, $orderPairKey(), $d['label']) ?>
                <?php elseif ($d['type'] === 'enum'): ?>
                  <?= count($d['options']) >= 3 ? ui_menu('set_' . $name, $d['options'], $now, $d['label']) : ui_segmented('set_' . $name, $d['options'], ($name === 'PHOTO_SORT_ORDER' && $now === 'mtime') ? 'taken' : $now, $d['label']) ?>
                <?php elseif ($d['type'] === 'text'): ?>
                  <input type="text" name="set_<?= $esc($name) ?>" value="<?= $esc($now) ?>" placeholder="empty = off" spellcheck="false" autocomplete="off" data-label="<?= $esc($d['label']) ?>" style="max-width:180px;">
                <?php elseif ($d['type'] === 'folders'): $extraNamed = (array) EXTRA_FOLDERS; $extraRoots = array_values((array) EXTRA_ROOTS); ?>
                  <div class="extra-folders" data-name="set_<?= $esc($name) ?>" data-map="<?= $esc(json_encode(['named' => (object) $extraNamed, 'roots' => $extraRoots], JSON_HEX_TAG)) ?>" data-label="<?= $esc($d['label']) ?>">
                    <ul class="extra-list"><?php foreach ($extraRoots as $rpath): ?><li><span class="extra-what"><code><?= $esc($rpath) ?></code></span><button type="button" class="extra-remove secondary" data-kind="root" data-name="<?= $esc($rpath) ?>">Remove</button><span class="muted extra-note">&rarr; its folders are at the top of the gallery</span></li><?php endforeach; ?><?php foreach ($extraNamed as $fname => $fpath): ?><li><span class="extra-what"><strong><?= $esc($fname) ?></strong> <span class="muted">&rarr;</span> <code><?= $esc($fpath) ?></code></span><button type="button" class="extra-remove secondary" data-kind="named" data-name="<?= $esc($fname) ?>">Remove</button></li><?php endforeach; ?></ul>
                    <p class="muted extra-none"<?= ($extraNamed || $extraRoots) ? ' hidden' : '' ?>>None added.</p>
                    <div class="extra-add">
                      <input type="text" class="extra-path" placeholder="/full/path/to/the/place" aria-label="Path to the place" autocomplete="off" spellcheck="false">
                      <input type="text" class="extra-name" placeholder="Name (optional)" aria-label="Name (optional)" maxlength="60" autocomplete="off">
                      <button type="button" class="extra-add-btn secondary">Add</button>
                    </div>
                  </div>
                <?php else: ?>
                  <input type="number" name="set_<?= $esc($name) ?>" min="<?= (int) $d['min'] ?>" max="<?= (int) $d['max'] ?>" step="<?= $esc($d['step'] ?? '1') ?>" value="<?= $esc($now) ?>" style="max-width:110px;">
                <?php endif; ?>

                <span class="rowstate" role="status" aria-live="polite"></span>
              </div>
            </div>
            <?php if ($name === 'ENABLE_THUMBNAIL_CACHE'): ?><div id="media-slot"><?php render_admin_thumbnails_panel($csrfToken); ?></div><?php endif; ?>
          <?php endforeach; ?>
          <?php if ($group['title'] === 'Server setup (advanced)'): ?>
            <div class="site-rules" id="lock-rules-row"><div class="name">Keep private photos from being fetched by address</div><div class="lock-result" data-lock-status></div><div class="help lock-help">Making something private only stops the gallery from showing it; the file can still be fetched by its direct address unless your web server is told not to. Rules to copy:</div><?= render_server_rules_help(true) ?></div>
            <?php if (ENABLE_SHARE_LINKS): ?>
            <div class="site-rules"><div class="name">Tidy share addresses</div><div class="lock-result" data-sharetidy-status></div><div data-sharetidy-rules><div class="help">Optional. A share link always works as <code>.../?link=&hellip;</code>; with this rule it is the short <code>.../k7QpR2mXv9LwTb4e</code>, a link can have a name of your own (<code>.../carol-and-sam</code>), and <code>.../admin</code> goes to the sign-in. If you added the older rule, replace it. Rules to copy:</div><?= render_link_rules_help() ?></div></div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($group['title'] === 'Signing in and passwords') { render_admin_browsers_panel($csrfToken); render_admin_geo_row($csrfToken); } ?>
          </details>
        <?php endforeach; ?>


        <details class="site-group" id="files-made">
          <summary><span class="site-group-title">Files this gallery has made</span></summary>
          <div class="site-row"><div>
            <div class="name">The files</div>
            <div class="help">Everything the gallery has written, apart from your photos and videos. See what and where it is, take a copy, or remove it all.</div>
            <div class="fm-also muted" style="margin:0 0 10px">Also a readable copy of what you typed (captions, locations, links, names, covers, private flags), without passwords or visitor records:
              <button type="button" class="linklike" data-export="export_items">this folder (<?= htmlspecialchars($GLOBALS['__admin_folder_label'] ?? 'Root', ENT_QUOTES, 'UTF-8') ?>)</button>
              or <button type="button" class="linklike" data-export="export_tree">the whole gallery</button>. It holds any GPS you entered and which things are hidden, so keep it private.</div>
            <div class="fm-btns"><button type="button" class="secondary" data-fm="list">Show list</button><button type="button" class="fm-primary" data-fm="zip">Download ZIP</button><button type="button" class="secondary fm-danger" data-fm="delete">Delete&hellip;</button></div>
            <div class="fm-list" data-fm-list hidden></div>
          </div></div>
        </details>

        <div class="item-actions nojs-only" style="margin-top:14px;">
          <button type="submit"<?= $broken ? ' disabled' : '' ?>>Save settings</button>
        </div>
        <p class="muted nojs-only" style="margin-top:14px;">Only settings that differ from the defaults are stored.</p>
      </form>
    </section>
    <script>
    // Files this gallery has made: the list, the ZIP and the gated delete. Every check that matters is made again on the server.
    (function () {
      var root = document.getElementById('files-made'); if (!root) return;
      var csrf = <?= json_encode($csrfToken) ?>, listBox = root.querySelector('[data-fm-list]'), sum = null, copied = false;
