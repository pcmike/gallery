function render_admin_links_panel($csrfToken, $dir, $files) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    if (AUTO_GROUP_BY_FOLDER) link_scan_ambiguity($dir, $files);   // keeps the list of ids found in two folders current
    $data = links_read();
    $now = time();
    $map = folder_id_map($dir, $files);
    $index = (array) ($data['folderIndex'] ?? []);
    $rootFid = folder_id_read($dir);
    $seenAt = (int) ($data['seenAt'] ?? 0);
    $folderOpts = [];
    foreach (folder_tree_rows($dir, all_folder_paths($files)) as $r) $folderOpts[] = ['p' => $r['p'], 'l' => link_folder_label($dir, $r['p']), 'n' => $r['n'], 'd' => $r['d'], 'x' => folder_is_private($dir, $r['p'])];
    $privNow = admin_hidden_data($dir, $files);   // (what is private right now: the form says "opens private photos" only when the link would)
    $current = []; $ended = []; $problemLinks = 0; $pubRec = null;
    foreach ($data['links'] as $l) {
        if (!is_array($l) || !isset($l['id'], $l['token'])) continue;
        if (link_is_public_log($l)) { $pubRec = $l; continue; }   // (shown by itself, above the links)
        $s = link_state($l, $now);
        $report = link_scope_report($l, $map, $index, $dir, $rootFid);
        if ($report['problems'] > 0 && $s !== 'revoked') $problemLinks++;
        [$logL] = link_activity($l['id']);
        $report['log'] = $logL;
        $report['new'] = link_news_count($logL, $seenAt);
        if (in_array($s, ['active', 'paused', 'scheduled'], true)) $current[] = [$l, $s, $report]; else $ended[] = [$l, $s, $report];
    }
    usort($current, function ($a, $b) { return (int) $b[0]['created'] <=> (int) $a[0]['created']; });
    usort($ended, function ($a, $b) { return max((int) $b[0]['revokedAt'], (int) $b[0]['endsAt'], (int) $b[0]['lastOpened']) <=> max((int) $a[0]['revokedAt'], (int) $a[0]['endsAt'], (int) $a[0]['lastOpened']); });
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $loc = $base === '' ? '/' : $base . '/';
    $row = function ($l, $state, $report, $ended) use ($esc, $map, $index, $dir, $rootFid) {
        $urls = link_urls($l);
        $tail = !empty($l['custom']) ? $l['token'] : substr($l['token'], 0, 4) . '…' . substr($l['token'], -4);
        $title = ($l['titleMode'] ?? 'gallery') === 'neutral' ? 'A neutral title' : (($l['titleMode'] ?? '') === 'custom' ? '“' . $esc($l['titleText']) . '”' : 'The gallery\'s title');
        $heads = ['default' => 'The site setting (' . (SHARE_HEADS_UP ? 'on' : 'off') . ')', 'on' => 'On', 'off' => 'Off'][$l['headsUp'] ?? 'default'];
        $json = $esc(json_encode(['id' => $l['id'], 'label' => $l['label'], 'address' => !empty($l['custom']) ? $l['token'] : '', 'aliases' => count((array) ($l['aliases'] ?? [])), 'note' => $l['note'], 'titleMode' => $l['titleMode'], 'titleText' => $l['titleText'], 'picture' => !empty($l['picture']), 'pictureSeed' => (string) ($l['pictureSeed'] ?? ''), 'pictureFile' => (string) ($l['pictureFile'] ?? ''), 'maxOpens' => (int) $l['maxOpens'], 'opens' => (int) ($l['opens'] ?? 0), 'killedCount' => count(array_filter((array) ($l['killed'] ?? []), 'is_array')),
            'startsAt' => (int) $l['startsAt'], 'endsAt' => (int) $l['endsAt'], 'locations' => $l['locations'], 'headsUp' => $l['headsUp'], 'state' => $state,
            'scopeType' => $l['scope']['type'] ?? 'all', 'only' => !empty($l['only']), 'scopeFolders' => $report['paths']['scope'], 'scopePublicOnly' => $report['paths']['publicOnly'], 'scopePhotos' => $report['paths']['photos'], 'openIn' => $report['paths']['openIn'],
            'downloads' => $l['downloads']['mode'] ?? 'off', 'downloadsFolders' => $report['paths']['downloads'], 'zip' => $l['zip']['mode'] ?? 'off', 'zipFolders' => $report['paths']['zip']], JSON_HEX_TAG | JSON_HEX_AMP));
        $missing = array_merge(array_filter($report['folders'], function ($x) { return $x['state'] !== 'ok'; }), array_filter($report['photos'], function ($x) { return $x['state'] !== 'ok'; }));
        ob_start(); ?>
        <details class="sl-row" data-id="<?= $esc($l['id']) ?>" data-state="<?= $esc($state) ?>" data-link="<?= $json ?>">
          <summary>
            <span class="sl-pill <?= $esc($state) ?>"><?= $esc(link_state_label($state)) ?></span>
            <span class="sl-nm"><span class="sl-name"><?= $esc($l['label']) ?></span> <?php if ($report['problems'] > 0 && $state !== 'revoked'): ?><span class="sl-pill paused" title="Part of what this link covers can't be found">Folder missing</span> <?php endif; ?><?php if ($report['new'] > 0): ?><span class="sl-newt"><?= (int) $report['new'] ?> new since you last looked</span> <?php endif; ?><span class="sl-tail"><?= $esc($tail) ?></span>
              <span class="sl-sub"><?= link_summary_html($l, $state, $report['log']) ?></span></span>
            <?php if ($state === 'paused'): ?><button type="button" class="secondary sl-quick" data-sl="resume">Resume</button>
            <?php elseif ($state === 'expired' || $state === 'used_up'): ?><button type="button" class="secondary sl-quick" data-sl="extend">Extend…</button>
            <?php elseif (!$ended): ?><button type="button" class="secondary sl-quick sl-copy" data-sl="copy">Copy link</button><?php endif; ?>
            <span class="sl-chev"><?= ui_icon('chev-down') ?></span>
          </summary>
          <div class="sl-xp">
            <?php if ($state !== 'revoked'): ?>
            <input type="hidden" class="sl-url" value="<?= $esc($urls['long']) ?>" data-long="<?= $esc($urls['long']) ?>" data-short="<?= $esc($urls['short']) ?>">
            <div class="gs-mount" data-name="<?= $esc($l['label']) ?>" data-copy="<?= in_array($state, ['paused', 'expired', 'used_up'], true) ? '1' : '0' ?>"></div>
            <?php if (!empty($l['custom']) && ($shadow = link_name_shadowed_by($l['token'], $dir)) !== ''): ?>
            <div class="sl-warn"><?= ui_icon('warn') ?><div><strong>A folder called “<?= $esc($shadow) ?>” is in the gallery.</strong> With the current server rule the link still wins at <code>…/<?= $esc($l['token']) ?></code> and the folder keeps <code>…/<?= $esc($shadow) ?>/</code>. If the link stops opening, the rule on your server is the older one: copy the new one from Settings, or send the long form (<code>…/?link=<?= $esc($l['token']) ?></code>).</div></div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($missing && $state !== 'revoked'): ?>
            <div class="sl-warn"><?= ui_icon('warn') ?><div><strong>Part of this link can't be found.</strong> Nothing from it is shown to anyone using the link; the rest still works. Press <em>Edit…</em> to pick it again<?php foreach ($missing as $m): if ($m['state'] === 'missing' && isset($m['fid']) && ($l['scope']['type'] ?? '') === 'folders'): ?> <button type="button" class="linklike sl-forget" data-sl="forget" data-fid="<?= $esc($m['fid']) ?>">Take “<?= $esc($m['label']) ?>” out of this link</button><?php endif; endforeach; ?>.</div></div>
            <?php endif; ?>
            <p class="sl-para"><?= link_paragraph_html($l, $state, $report, $map, $index, $dir, $rootFid) ?><?php if ($state !== 'revoked'): ?> <button type="button" class="linklike" data-sl="edit">Edit…</button><?php endif; ?></p>
            <?php $kind = function ($name) { return link_address_is_custom($name) ? '1' : '0'; };
            $aliasNames = array_map(function ($a) { return is_array($a) ? (string) ($a['name'] ?? '') : ''; }, (array) ($l['aliases'] ?? []));
            $pausedAddr = array_values(array_filter(array_unique(array_map('strval', (array) ($l['oldTokens'] ?? []))), function ($t) use ($l, $aliasNames) { return $t !== '' && strtolower($t) !== strtolower((string) $l['token']) && !in_array($t, $aliasNames, true); }));
            $killedAddr = array_filter((array) ($l['killed'] ?? []), 'is_array');
            $oldCount = count($aliasNames) + count($pausedAddr) + count($killedAddr);
            if ($oldCount > 0 && $state !== 'revoked'):
              $oldBits = [];
              foreach ((array) ($l['aliases'] ?? []) as $a) { if (is_array($a)) $oldBits[] = '<code>…/' . $esc(link_address_label($a['name'], !empty($a['custom']))) . '</code> working'; }
              foreach ($pausedAddr as $rt) $oldBits[] = '<code>…/' . $esc(link_address_label($rt, link_address_is_custom($rt))) . '</code> paused';
              if ($killedAddr) $oldBits[] = count($killedAddr) . ' killed'; ?>
            <details class="sl-old"><summary><span>Old addresses (<?= $oldCount ?>): <?= implode(', ', $oldBits) ?></span> <span class="sl-manage">Manage…</span></summary>
            <dl class="sl-facts">
              <?php if (!empty($l['aliases'])): ?><dt>Also works at</dt><dd>
                <?php foreach ((array) $l['aliases'] as $a): if (!is_array($a)) continue; $nIps = link_address_log_stats($report['log'], 'via', $a['name'])[1]; $tr = link_address_log_stats($report['log'], 'tried', $a['name']); ?><div class="sl-alias"><div class="sl-aliasinfo"><code>…/<?= $esc(link_address_label($a['name'], !empty($a['custom']))) ?></code> <span class="muted">sent <?= (int) ($a['hits'] ?? 0) ?> visit<?= (int) ($a['hits'] ?? 0) === 1 ? '' : 's' ?> here from <?= $nIps ?> IP<?= $nIps === 1 ? '' : 's' ?><?= (int) ($a['last'] ?? 0) > 0 ? ', last ' . link_time_html($a['last']) : '' ?><?= $tr[0] > 0 ? ' &middot; tried ' . $tr[0] . ' time' . ($tr[0] === 1 ? '' : 's') . ' while paused' : '' ?></span></div><div class="sl-aliasacts"><?php if ($state !== 'revoked'): ?><button type="button" class="secondary sl-abtn" data-sl="aliaspause" data-alias="<?= $esc($a['name']) ?>">Pause</button><?php endif; ?><button type="button" class="secondary sl-abtn sl-kill" data-sl="aliaskill" data-alias="<?= $esc($a['name']) ?>" data-custom="<?= $kind($a['name']) ?>">Kill</button></div></div><?php endforeach; ?>
                <span class="muted">Each one sends people on to the link's present address.</span></dd><?php endif; ?>
              <?php $paused = $pausedAddr;
              if ($paused): ?><dt>Paused addresses</dt><dd>
                <?php foreach ($paused as $rt): $tr = link_address_log_stats($report['log'], 'tried', $rt); ?><div class="sl-alias"><div class="sl-aliasinfo"><code>…/<?= $esc(link_address_label($rt, link_address_is_custom($rt))) ?></code><?php if ($tr[0] > 0): ?> <span class="muted">tried <?= $tr[0] ?> time<?= $tr[0] === 1 ? '' : 's' ?> from <?= $tr[1] ?> IP<?= $tr[1] === 1 ? '' : 's' ?>, last <?= link_time_html($tr[2]) ?></span><?php endif; ?></div><div class="sl-aliasacts"><?php if ($state !== 'revoked'): ?><button type="button" class="secondary sl-abtn" data-sl="aliasresume" data-alias="<?= $esc($rt) ?>">Resume</button><?php endif; ?><button type="button" class="secondary sl-abtn sl-kill" data-sl="aliaskill" data-alias="<?= $esc($rt) ?>" data-custom="<?= $kind($rt) ?>">Kill</button></div></div><?php endforeach; ?>
                <span class="muted">These show the “not valid” page and stay reserved to this link. <em>Resume</em> sends people on to its present address again; <em>Kill</em> ends one for good and frees the name.</span></dd><?php endif; ?>
              <?php if (!empty($l['killed'])): ?><dt>Killed addresses</dt><dd>
                <?php foreach ((array) $l['killed'] as $k): if (!is_array($k)) continue; ?><div class="sl-alias"><div class="sl-aliasinfo"><code>…/<?= $esc((string) ($k['label'] ?? '')) ?></code> <span class="muted">killed <?= link_time_html((int) ($k['killedAt'] ?? 0)) ?>: sent <?= (int) ($k['hits'] ?? 0) ?> visit<?= (int) ($k['hits'] ?? 0) === 1 ? '' : 's' ?> from <?= (int) ($k['ips'] ?? 0) ?> IP<?= (int) ($k['ips'] ?? 0) === 1 ? '' : 's' ?>, tried <?= (int) ($k['tries'] ?? 0) ?> time<?= (int) ($k['tries'] ?? 0) === 1 ? '' : 's' ?> while paused</span></div></div><?php endforeach; ?>
                <div class="sl-aliasacts"><button type="button" class="secondary sl-abtn" data-sl="killedclear">Clear</button></div></dd><?php endif; ?>
            </dl>
            </details>
            <?php endif; ?>
            <div class="sl-act" data-act="link"><p class="muted sl-none">Loading…</p></div>
            <div class="sl-acts">
              <details class="sl-export"><summary class="secondary">Export log ▾</summary><div class="sl-menu">
                <a href="?admin&amp;linkexport=<?= $esc($l['id']) ?>&amp;what=visitors">Visitors<small>One line per IP: opens, first and last seen</small></a>
                <a href="?admin&amp;linkexport=<?= $esc($l['id']) ?>&amp;what=items">What they looked at<small>One line per item: seen, opened, played, downloaded</small></a>
                <a href="?admin&amp;linkexport=<?= $esc($l['id']) ?>&amp;what=events">Everything, in order<small>The timeline, one line per happening</small></a>
                <span class="sl-menu-note">Includes IP addresses. Opens in your downloads. <button type="button" class="linklike" data-sl="trim" data-trim-link="<?= $esc($l['id']) ?>">Trim this link's log&hellip;</button></span></div></details>
              <?php if ($state !== 'revoked'): ?><button type="button" class="linklike" data-sl="preview" title="Opens a new tab that shows the gallery exactly as this link does">Preview as this link</button><?php endif; ?>
              <?php if (in_array($state, ['active', 'scheduled'], true) && ($l['status'] ?? '') === 'active'): ?><button type="button" class="linklike" data-sl="pause">Pause</button><?php endif; ?>
              <?php if ($state === 'paused'): ?><button type="button" class="linklike" data-sl="resume">Resume</button><?php endif; ?>
              <?php if (in_array($state, ['expired', 'used_up'], true)): ?><button type="button" class="linklike" data-sl="extend">Extend…</button><?php endif; ?>
              <?php if ($state !== 'revoked'): ?><button type="button" class="linklike" data-sl="replace">Replace address…</button><button type="button" class="linklike" data-sl="revoke">Revoke</button><?php endif; ?>
              <button type="button" class="linklike sl-danger" data-sl="delete">Delete…</button>
            </div>
          </div>
        </details>
<?php
        return ob_get_clean();
    };
?>
    <section class="panel sl" id="sl-root" data-csrf="<?= $esc($csrfToken) ?>" data-probe="<?= $esc($loc . LINK_PROBE_TOKEN) ?>" data-nofolders="<?= AUTO_GROUP_BY_FOLDER ? '' : '1' ?>" data-default-uses="<?= (int) DEFAULT_LINK_USES ?>">
      <h2 class="section-title">Shares</h2>
      <?php if (!ENABLE_SHARE_LINKS): ?>
        <div class="sl-off">Share links are switched off, so none of these work. Turn on <a href="#site" onclick="return adminGoTo('site', 'setting-ENABLE_SHARE_LINKS');"><em>Allow share links</em> in Settings</a>.</div>
      <?php endif; ?>
      <?php if (($previewOpen = preview_cookie_link()) !== null): ?>
        <div class="sl-prevnote"><?= ui_icon('eye') ?><div><strong>A preview of &ldquo;<?= $esc($previewOpen['label']) ?>&rdquo; is open in this browser.</strong> The gallery's own pages here show that link's view until you end it (the admin pages stay yours). <a href="?exitpreview">End preview</a></div></div>
      <?php endif; ?>
      <?php if ($problemLinks > 0): ?>
        <div class="sl-check no" style="display:flex; gap:10px; align-items:center;"><?= ui_icon('warn') ?><div><strong><?= $problemLinks ?> link<?= $problemLinks === 1 ? ' refers' : 's refer' ?> to something that can't be found.</strong> It fails safe: nothing from it is shown. Open the link to see which.</div></div>
      <?php endif; ?>
      <div class="sl-head">
        <div class="sl-count muted"><?= count($current) ?> not ended &middot; <?= count($ended) ?> ended</div>
        <div class="sl-segbar sl-viewswitch" role="group" aria-label="What to list"><button type="button" class="sl-segbtn on" data-view="links">Links</button><button type="button" class="sl-segbtn" data-view="ips">IP addresses</button></div>
        <button type="button" class="sl-sel-btn" data-sl="select" aria-pressed="false" title="Select links to act on several at once" aria-label="Select links to act on several at once"><?= ui_icon('select') ?></button>
        <button type="button" class="sl-new-btn" data-sl="new">+ New link</button>
      </div>
      <div id="sl-ips-view" class="sl-ipsview" hidden><div class="sl-act" data-act="ips"><p class="muted sl-none">Loading…</p></div>
        <p class="note"><a href="?admin&amp;linkexport=all&amp;what=items" class="linklike">Export everything as CSV</a> (what each address looked at, for every link) &middot; <button type="button" class="linklike" data-sl="trim">Trim the log&hellip;</button></p></div>
      <form class="sl-form" id="sl-form" hidden data-folders="<?= $esc(json_encode($folderOpts, JSON_HEX_TAG | JSON_HEX_AMP)) ?>" data-private="<?= $esc(json_encode(['f' => $privNow['folders'], 'i' => $privNow['items'], 's' => $privNow['shown']], JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
        <div class="sl-form-head"><h3 class="sl-form-title">New share link</h3><button type="button" class="linklike sl-resetall" data-sl="resetall" hidden></button></div>
        <input type="hidden" name="id" value="">
        <label class="sl-f"><span class="sl-k">Name <span class="muted">(only you see it)</span></span><input type="text" name="label" maxlength="80" placeholder="Aunt Carol" autocomplete="off"></label>
        <div class="sl-f sl-addrblock" data-addr>
          <label class="sl-only sl-addrck"><input type="checkbox" name="addr_name" checked> <span>Use the name as the address <code class="sl-addrprev" data-addrprev>…/auntcarol</code></span></label>
          <div class="sl-addr" data-addrrow hidden><span class="sl-addrbase" title="<?= $esc($loc) ?>">…<?= $esc($loc) ?></span><input type="text" name="address" maxlength="40" placeholder="auntcarol" autocomplete="off" autocapitalize="none" spellcheck="false" aria-label="Address"><button type="button" class="secondary" data-sl="addrrandom">Random</button></div>
          <span class="sl-h" data-addrhint></span>
          <div class="sl-addrnote" data-addrnote role="status" hidden>This link opens private photos. An address made from a name is easy to guess, so untick the box to get a random one that can't be.</div>
          <label class="sl-keep" data-keeprow hidden><input type="checkbox" name="keep_old" value="1"> Keep the old address working too <span class="sl-h">(it sends people on to the new one)</span></label>
        </div>

        <div class="sl-asis" data-asis aria-live="polite"><div class="sl-asis-t">As it stands</div><span data-cl="1"></span> <span data-cl="2"></span> <span data-cl="3"></span> <span data-cl="4"></span> <span data-cl="5"></span> <span data-cl="6"></span><?php if (DEFAULT_LINK_USES > 0): ?> <span class="sl-asis-note" data-uses-note>Meant for more than one person? Open &ldquo;How long and how often&rdquo; and raise how many browsers can open it.</span><?php endif; ?></div>

        <div class="sl-grps">
        <details class="sl-grp" data-grp="scope"><summary><span class="sl-grp-main"><span class="sl-grp-t">What they can see</span><span class="sl-grp-tag" data-grp-tag hidden>Changed</span><span class="sl-grp-s" data-grp-sum></span></span><span class="sl-grp-go" aria-hidden="true"></span></summary>
         <div class="sl-grp-b">
        <div class="sl-f"><span class="sl-k">What they can see</span>
          <div class="sl-radios" role="radiogroup" aria-label="What they can see">
            <label class="sl-radio"><input type="radio" name="scope_type" value="all"><span><strong>Everything</strong><small>The whole gallery, public and private, including what you add later.</small></span></label>
            <label class="sl-radio"><input type="radio" name="scope_type" value="folders"<?= AUTO_GROUP_BY_FOLDER ? '' : ' disabled' ?>><span><strong>Only some folders</strong><small>Each folder includes everything inside it, or only its public items. They are never told other private folders exist.<?= AUTO_GROUP_BY_FOLDER ? '' : ' (Turn on “Show subfolders” in Settings first.)' ?></small></span></label>
            <label class="sl-radio"><input type="radio" name="scope_type" value="public" checked><span><strong>Just the public gallery</strong><small>Nothing private. They see what any visitor sees, and the log shows who looked at what. Useful for finding out who opens a link you post or send.</small></span></label>
            <label class="sl-radio" data-photos-only><input type="radio" name="scope_type" value="photos"><span><strong>Only some photos</strong><small class="sl-photos-note">Only the photos you pick, wherever they are. Anything already public stays visible too, unless you tick the box below.</small></span></label>
            <label class="sl-radio"><input type="radio" name="scope_type" value="mixed"<?= AUTO_GROUP_BY_FOLDER ? '' : ' disabled' ?>><span><strong>Some folders and some photos</strong><small>Pick folders (each with everything inside it, or only its public items) and single photos from anywhere, in one link.<?= AUTO_GROUP_BY_FOLDER ? '' : ' (Turn on “Show subfolders” in Settings first.)' ?></small></span></label>
          </div>
          <div class="sl-chips" data-chips="scope" hidden></div>
          <label class="sl-only" data-only-row hidden><input type="checkbox" name="only"> <span><strong>Show only what they can see here</strong> <small><?= gallery_is_closed() ? 'The pages they see list nothing but what this link covers, and nothing else can be opened through the gallery.' : 'The pages they see list nothing but what this link covers, not the rest of the public gallery. That stays public to everyone, so this is a tidy view, not a lock.' ?></small></span></label>
        </div>
        <div class="sl-f" data-openin><span class="sl-k">Opens in</span><div data-openin-menu></div><span class="sl-h">The first page they see.</span></div>
        <button type="button" class="linklike sl-grp-reset" data-sl="grpreset" data-grp-reset hidden>Back to the default</button>
         </div></details>

        <details class="sl-grp" data-grp="limits"><summary><span class="sl-grp-main"><span class="sl-grp-t">How long and how often</span><span class="sl-grp-tag" data-grp-tag hidden>Changed</span><span class="sl-grp-s" data-grp-sum></span></span><span class="sl-grp-go" aria-hidden="true"></span></summary>
         <div class="sl-grp-b">
        <div class="sl-f"><span class="sl-k">How many browsers can open it?</span>
          <?php $du = (int) DEFAULT_LINK_USES; $duPick = in_array($du, [0, 1, 2, 5, 10], true) ? (string) $du : 'n'; ?>
          <?= ui_segmented('opens_pick', ['1' => 'Just one', '2' => '2', '5' => '5', '10' => '10', '0' => 'Unlimited', 'n' => 'Other…'], $duPick, 'Browsers') ?>
          <input type="number" name="opens_other" min="1" max="100000" value="<?= $duPick === 'n' ? $du : 3 ?>"<?= $duPick === 'n' ? '' : ' hidden' ?>>
          <span class="sl-h">Counted per browser, not per page. Once someone is in, they can keep looking until the link ends.</span></div>
        <div class="sl-f"><span class="sl-k">When does it stop working?</span>
          <?= ui_segmented('ends_pick', ['86400' => '1 day', '604800' => '1 week', '2592000' => '30 days', 'pick' => 'Pick a date…', '0' => 'Never'], '0', 'Ends') ?>
          <input type="datetime-local" name="ends_at_local" hidden>
          <span class="sl-h">In your browser's time zone. People already inside are signed out at that moment.</span></div>
        <div class="sl-f"><span class="sl-k">Starts</span>
          <?= ui_segmented('starts_pick', ['now' => 'Immediately', 'pick' => 'Pick a date…'], 'now', 'Starts') ?>
          <input type="datetime-local" name="starts_at_local" hidden></div>
        <button type="button" class="linklike sl-grp-reset" data-sl="grpreset" data-grp-reset hidden>Back to the defaults</button>
         </div></details>

        <details class="sl-grp" data-grp="privacy"><summary><span class="sl-grp-main"><span class="sl-grp-t">Downloads and locations</span><span class="sl-grp-tag" data-grp-tag hidden>Changed</span><span class="sl-grp-s" data-grp-sum></span></span><span class="sl-grp-go" aria-hidden="true"></span></summary>
         <div class="sl-grp-b">
        <div class="sl-f"><span class="sl-k">Download originals</span>
          <?= ui_segmented('downloads', ['off' => 'Off', 'all' => 'Everything', 'public' => 'Everything but private', 'some' => 'Only some folders'], 'off', 'Download originals') ?>
          <div class="sl-chips" data-chips="downloads" hidden></div>
          <span class="sl-h">This link's own answer: the gallery-wide download setting does not decide for the people using it.</span></div>
        <div class="sl-f"><span class="sl-k">ZIP of a folder</span>
          <?= ui_segmented('zip', ['off' => 'Off', 'all' => 'Everything', 'public' => 'Everything but private', 'some' => 'Only some folders'], 'off', 'ZIP of a folder') ?>
          <div class="sl-chips" data-chips="zip" hidden></div></div>
        <div class="sl-f"><span class="sl-k">Photo locations</span>
          <?= ui_segmented('locations', ['public' => 'Shown only', 'all' => 'All'], 'public', 'Photo locations') ?>
          <span class="sl-h">The first shows only places you've set to show, in Folder settings or on a photo; the others stay hidden, and are removed from any download or ZIP. The second shows every saved location.</span></div>
        <button type="button" class="linklike sl-grp-reset" data-sl="grpreset" data-grp-reset hidden>Back to the defaults</button>
         </div></details>

        <details class="sl-grp" data-grp="page"><summary><span class="sl-grp-main"><span class="sl-grp-t">The opening page</span><span class="sl-grp-tag" data-grp-tag hidden>Changed</span><span class="sl-grp-s" data-grp-sum></span></span><span class="sl-grp-go" aria-hidden="true"></span></summary>
         <div class="sl-grp-b">
        <div class="sl-f"><span class="sl-k">Title on the opening page and in message previews</span>
          <?= ui_segmented('title_mode', ['gallery' => 'The gallery\'s title', 'neutral' => 'A neutral title', 'custom' => 'My own text…'], 'gallery', 'Title') ?>
          <input type="text" name="title_text" maxlength="80" placeholder="Photos for Carol" hidden>
          <label class="sl-only sl-pic"><input type="checkbox" name="picture" checked> <span><strong>Show a preview photo</strong> <small>In message previews and on the opening page, the same one every time for this link: the front page's own cover photo if it has one and it is public, else a cover from one of the public folders, or a photo you choose. Never a private photo and never a folder name. It starts on with the gallery's title and off with the other two, since a photo says which gallery it is. Without a photo the icon is the gallery's.</small></span></label>
          <div class="sl-coverrow" data-coverrow hidden>
            <span class="sl-coverimg" aria-hidden="true"><img alt="" hidden></span>
            <span class="sl-covertext"><strong data-cover-how>Choosing…</strong><small data-cover-name></small></span>
            <button type="button" class="secondary" data-cover-choose>Choose…</button><button type="button" class="linklike" data-cover-auto hidden>Automatic</button>
            <input type="hidden" name="picture_seed" value=""><input type="hidden" name="picture_file" value="">
          </div></div>
        <label class="sl-f"><span class="sl-k">Note on the opening page <span class="muted">(optional)</span></span><textarea name="note" rows="2" maxlength="200" placeholder="Hi Carol, here are the Rome photos."></textarea></label>
        <div class="sl-f"><span class="sl-k">Heads-up at the top of the gallery</span>
          <?= ui_segmented('heads_up', ['default' => 'Use the site setting (' . (SHARE_HEADS_UP ? 'on' : 'off') . ')', 'on' => 'On', 'off' => 'Off'], 'default', 'Heads-up') ?>
          <span class="sl-h">“The link being used to access this media expires on …”, a thin line they can dismiss. Not shown when there is no end date.</span></div>
        <button type="button" class="linklike sl-grp-reset" data-sl="grpreset" data-grp-reset hidden>Back to the defaults</button>
         </div></details>
        </div>
        <div class="sl-form-acts"><button type="submit" class="sl-save">Create link</button><button type="button" class="secondary" data-sl="cancel">Cancel</button><span class="sl-err" role="alert"></span></div>
      </form>
      <div id="sl-links-view">
      <?php if (!$current && !$ended): ?>
        <p class="muted sl-none">No links yet. Press <strong>New link</strong> to make one.</p>
      <?php endif; ?>
      <?php if (ENABLE_SHARE_LINKS && ($pubRec !== null || (LOG_PUBLIC_VISITS && !gallery_is_closed()))): ?>   <?php // (shown when there is a log to look at, or logging is on and the gallery is open: a closed gallery with nothing logged has nothing to say here) ?>
      <div class="sl-grp">The public address <span class="sl-grp-note">&middot; everyone who opens the gallery without a link</span></div>
      <?php if ($pubRec === null): ?>
        <p class="muted sl-none"><?= gallery_is_closed() ? '<strong>Closed.</strong> Nobody can open the gallery without a link or a sign-in, so nothing is logged here.' : 'Logging is on. Nobody has been logged yet.' ?></p>
      <?php else: [$logP] = link_activity(PUBLIC_LOG_ID); $pubNew = link_news_count($logP, $seenAt); $pubVisits = array_sum(array_column($logP['visitors'], 'opens')); $pubViews = array_sum(array_column($logP['visitors'], 'views')); $pubIps = count(array_unique(array_column($logP['visitors'], 'ip'))); ?>
        <details class="sl-row sl-public" data-id="<?= $esc(PUBLIC_LOG_ID) ?>" data-state="<?= LOG_PUBLIC_VISITS ? 'active' : 'paused' ?>" data-link="<?= $esc(json_encode(['id' => PUBLIC_LOG_ID, 'label' => 'Public address'], JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
          <summary>
            <span class="sl-pill <?= LOG_PUBLIC_VISITS && !gallery_is_closed() ? 'active' : 'paused' ?>"><?= gallery_is_closed() ? 'Closed' : (LOG_PUBLIC_VISITS ? 'Logging' : 'Logging is off') ?></span>
            <span class="sl-nm"><span class="sl-name">Public address</span> <?php if ($pubNew > 0): ?><span class="sl-newt"><?= (int) $pubNew ?> new since you last looked</span> <?php endif; ?>
              <span class="sl-sub"><b><?= number_format($pubVisits) ?></b> visit<?= $pubVisits === 1 ? '' : 's' ?> <span class="sl-dot">&middot;</span> <b><?= number_format($pubIps) ?></b> IP<?= $pubIps === 1 ? '' : 's' ?> <span class="sl-dot">&middot;</span> <?= number_format($pubViews) ?> view<?= $pubViews === 1 ? '' : 's' ?></span></span>
            <span class="sl-chev"><?= ui_icon('chev-down') ?></span>
          </summary>
          <div class="sl-xp">
            <details class="sl-settings"><summary>Settings</summary>
            <dl class="sl-facts">
              <?php if (gallery_is_closed()): ?><dt>Right now</dt><dd><strong>The gallery is closed:</strong> nobody opens it without a link or a sign-in, so nothing new is logged. The log so far stays.</dd><?php endif; ?>
              <dt>Who is logged</dt><dd>Everyone who opens the gallery's plain address in a browser. Not you, not people signed in with the private-view password, not people using a share link (their link has its own log), and not link-preview bots.</dd>
              <dt>Kept for</dt><dd><?= LOG_PUBLIC_DAYS > 0 ? (int) LOG_PUBLIC_DAYS . ' days, then removed by itself' : 'Until you trim it' ?></dd>
              <dt>Turn it on or off</dt><dd><a href="#site" onclick="return adminGoTo('site', 'setting-LOG_PUBLIC_VISITS');">Settings &rarr; Share links &rarr; Log visitors to the public address</a></dd>
            </dl>
            </details>
            <div class="sl-act" data-act="link"><p class="muted sl-none">Loading…</p></div>
            <div class="sl-acts">
              <details class="sl-export"><summary class="secondary">Export log ▾</summary><div class="sl-menu">
                <a href="?admin&amp;linkexport=<?= $esc(PUBLIC_LOG_ID) ?>&amp;what=visitors">Visitors<small>One line per IP: visits, first and last seen</small></a>
                <a href="?admin&amp;linkexport=<?= $esc(PUBLIC_LOG_ID) ?>&amp;what=items">What they looked at<small>One line per item: seen, opened, played</small></a>
                <a href="?admin&amp;linkexport=<?= $esc(PUBLIC_LOG_ID) ?>&amp;what=events">Everything, in order<small>The timeline, one line per happening</small></a>
                <span class="sl-menu-note">Includes IP addresses. Opens in your downloads. <button type="button" class="linklike" data-sl="trim" data-trim-link="<?= $esc(PUBLIC_LOG_ID) ?>">Trim this log&hellip;</button></span></div></details>
              <button type="button" class="linklike sl-danger" data-sl="delete">Delete the log…</button>
            </div>
          </div>
        </details>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($current): ?><div class="sl-grp">Current</div><?php foreach ($current as [$l, $s, $r]) echo $row($l, $s, $r, false); ?><?php endif; ?>
      <?php if ($ended): ?><div class="sl-grp">Ended <span class="sl-grp-note">&middot; the log is kept until you delete the link</span></div><?php foreach ($ended as [$l, $s, $r]) echo $row($l, $s, $r, true); ?><?php endif; ?>
      </div>
      <div class="admin-modal" id="sl-modal" role="dialog" aria-modal="true" aria-labelledby="sl-modal-title" hidden>
        <div class="admin-modal-card">
          <h3 id="sl-modal-title"></h3>
          <div class="sl-modal-body"></div>
          <div class="pm-btns"><button type="button" class="secondary" data-sl-modal="cancel">Cancel</button><button type="button" data-sl-modal="ok">OK</button><button type="button" class="secondary" data-sl-modal="extra" hidden></button></div>
        </div>
      </div>
      <div class="pv-toast" id="sl-toast" role="status" aria-live="polite" hidden></div>
    </section>
<?= render_share_kit() ?>
<?php
}

