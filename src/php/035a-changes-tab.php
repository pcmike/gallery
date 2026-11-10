
/** The Changes tab: the log of disk activity (see 034a). Newest first. A line says what happened and where, with a link that opens the folder (marking what is new there);
 *  a line opens to show the names (and, for a rename, what went beside what came). A pill picks the kinds shown (any number), a search box narrows to a folder's or a file's
 *  name, "Trim the log…" takes the old ones out. The days and the times are drawn by the script in the viewer's own clock; without it the list is the same in the server's. */
function render_admin_changes_panel($csrfToken) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $rows = changes_list(500);
    $seen = (int) changes_read(function ($db) { return visitlog_meta_get($db, 'chg_seen'); }, 0);
    $groups = changes_group_counts($rows);
?>
  <section class="panel" id="changes" data-csrf="<?= $esc($csrfToken) ?>">
    <h2 class="section-title">Changes</h2>
    <p class="hint" style="margin:0 0 12px">The log of disk activity: what the gallery noticed change in your folders (a sync, an upload, a rename), whoever made it.</p>
    <div class="ch-head">
      <div class="ch-pillbox">
        <button type="button" class="ch-pill" aria-haspopup="true" aria-expanded="false"><span class="ch-pill-t">All kinds</span><em class="ch-pill-n"></em> <i class="tri" aria-hidden="true"></i></button>
        <div class="ch-pop" role="group" aria-label="Show only" hidden>
          <label class="ch-row"><input type="checkbox" data-all="1" checked> <span>All kinds</span></label>
          <?php foreach ($groups as $k => [$name, $n]): ?>
          <label class="ch-row"><input type="checkbox" data-g="<?= $k ?>"> <span><?= $esc($name) ?></span><small><?= number_format($n) ?></small></label>
          <?php endforeach; ?>
          <button type="button" class="secondary ch-done">Done</button>
        </div>
      </div>
      <button type="button" class="linklike ch-trim-open">Trim the log&hellip;</button>
    </div>
    <div class="ch-trim" hidden>
      <label>Remove entries older than
        <select class="ch-trim-sel"><option value="30">30 days</option><option value="90">90 days</option><option value="180">180 days</option><option value="365">a year</option><option value="all">all of them (everything)</option></select></label>
      <span class="ch-trim-n muted" role="status" aria-live="polite"></span>
      <button type="button" class="secondary ch-trim-go">Trim</button> <button type="button" class="linklike ch-trim-cancel">Cancel</button>
    </div>
    <input type="search" class="ch-search" placeholder="Search file or folder names" aria-label="Search file or folder names" autocomplete="off">
    <?php if (!$rows): ?>
    <p class="muted ch-empty-log">Nothing has changed on disk since the gallery started watching. When photos are added, removed or renamed (a sync, an upload), they are listed here.</p>
    <?php endif; ?>
    <div class="ch-list" data-seen="<?= $seen ?>"><?= changes_entries_html($rows, $seen) ?></div>
    <p class="muted ch-none" hidden>Nothing matches.</p>
  </section>
<?php
}

/** How many entries there are of each kind the pill offers (Added, Removed, Renamed, Folders): [key => [name, count]]. */
function changes_group_counts($rows) {
    $groupOf = ['add' => 'add', 'rem' => 'rem', 'ren' => 'ren', 'new' => 'folder', 'gone' => 'folder'];
    $groups = ['add' => ['Added', 0], 'rem' => ['Removed', 0], 'ren' => ['Renamed', 0], 'folder' => ['Folders', 0]];
    foreach ($rows as $r) if (isset($groupOf[$r['kind']])) $groups[$groupOf[$r['kind']]][1]++;
    return $groups;
}

/** The entries themselves, newest first (the tab draws them when the page is made; the script asks for them again when the tab is opened or the window comes back). Entries newer than $seen are marked. */
function changes_entries_html($rows, $seen) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $groupOf = ['add' => 'add', 'rem' => 'rem', 'ren' => 'ren', 'new' => 'folder', 'gone' => 'folder'];
    $media = function ($n) { return number_format($n) . ' media'; };
    ob_start();
?>
<?php foreach ($rows as $r):
        $kind = (string) $r['kind']; $folder = (string) $r['folder']; $n = (int) $r['n'];
        $names = json_decode((string) $r['names'], true); $names = is_array($names) ? $names : [];
        $gone = json_decode((string) $r['gone'], true); $gone = is_array($gone) ? $gone : [];
        $more = (int) $r['more'];
        $label = [ 'add' => ['ADDED', 'add'], 'rem' => ['REMOVED', 'rem'], 'ren' => ['RENAMED', 'ren'], 'new' => ['FOLDER', 'fol'], 'gone' => ['FOLDER', 'fol'] ][$kind] ?? ['', ''];
        $where = $folder === '' ? 'the front page' : $folder;
        $open = '?' . ($folder !== '' ? 'folder=' . rawurlencode($folder) : '') . (in_array($kind, ['add', 'ren', 'new'], true) ? ($folder !== '' ? '&' : '') . 'since=' . max(0, (int) $r['t0'] - 2) : '');
        $link = $kind === 'gone' ? '<b>' . $esc($where) . '</b>' : '<a href="' . $esc($open) . '">' . $esc($where) . '</a>';
        if ($kind === 'add') $text = $media($n) . ' added to ' . $link;
        elseif ($kind === 'rem') $text = $media($n) . ' removed from ' . $link;
        elseif ($kind === 'ren') $text = $media($n) . ' in ' . $link . ' renamed or replaced';
        elseif ($kind === 'new') $text = 'New folder ' . $link . ' (' . $media($n) . ')';
        else $text = 'Folder ' . $link . ' has no media now (' . $media($n) . ' before)';
        $hay = strtolower($where . ' ' . implode(' ', $names) . ' ' . implode(' ', $gone));
        $list = function ($ns, $extra) use ($esc) { $o = ''; foreach ($ns as $x) $o .= '<code>' . $esc($x) . '</code>'; if ($extra > 0) $o .= '<em class="muted">and ' . number_format($extra) . ' more</em>'; return $o; };
    ?>
      <div class="ch-e<?= (int) $r['id'] > $seen ? ' ch-new' : '' ?>" data-id="<?= (int) $r['id'] ?>" data-g="<?= $esc($groupOf[$kind] ?? '') ?>" data-t="<?= (int) $r['t'] ?>" data-text="<?= $esc($hay) ?>">
        <div class="ch-h">
          <span class="ch-t" data-t0="<?= (int) $r['t0'] ?>" data-t1="<?= (int) $r['t1'] ?>" data-t="<?= (int) $r['t'] ?>"><?= $esc(gmdate('j M H:i', (int) $r['t'])) ?></span>
          <div class="ch-b"><span class="ch-k <?= $label[1] ?>"><?= $label[0] ?></span><?= $text ?></div>
          <button type="button" class="linklike ch-tg" aria-expanded="false"><i class="tri" aria-hidden="true"></i> Show</button>
        </div>
        <div class="ch-x" hidden>
          <?php if ($kind === 'ren'): ?>
            <div class="ch-cols"><div class="ch-col ch-gone"><h4>Gone (<?= number_format($n) ?>)</h4><?= $list($gone, max(0, $n - count($gone))) ?></div><div class="ch-col ch-came"><h4>New (<?= number_format($n) ?>)</h4><?= $list($names, $more) ?></div></div>
          <?php else: ?>
            <div class="ch-names"><?= $list($names, $more) ?></div>
          <?php endif; ?>
          <?php if (in_array($kind, ['add', 'ren', 'new'], true) && (int) $r['t1'] > 0): ?><div class="ch-when muted" data-t0="<?= (int) $r['t0'] ?>" data-t1="<?= (int) $r['t1'] ?>"></div><?php endif; ?>
          <?php if ($kind !== 'gone'): ?><div class="ch-go"><a href="<?= $esc($open) ?>">Open <?= $esc($where) ?><?= in_array($kind, ['add', 'ren', 'new'], true) ? ' (the new ones are marked)' : '' ?></a></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
<?php
    return ob_get_clean();
}
