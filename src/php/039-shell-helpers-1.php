/* -----------------------------------------------------------------------
 * The owner's shell (?admin&shell=1): the admin's header and sidebar around
 * ONE frame that shows either the gallery page or an admin page. Both are the
 * ordinary pages, asked for with &inframe=1 (they hide their own bar and
 * sidebar and tell the shell where they are). Everything goes through
 * ?admin's own sign-in; the frame's pages are this site's, shown to this site.
 * ----------------------------------------------------------------------- */

/** The headers of a signed-in admin page asked for with &inframe=1: framed by this site, and by no other. Every other
 *  admin page keeps send_sensitive_page_headers() (framed by no one). */
function shell_frame_headers() {
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'none'; form-action 'self'; object-src 'none'");
}

/** The little script a page inside the shell carries: it tells the shell where it is (the folder, and which tab of the admin),
 *  and keeps &inframe=1 on the page's own links so the next page is framed the same way. Talks to this site's own window only. */
function shell_frame_script($kind) {
    return '<script>(function(){if(window.parent===window)return;var origin=location.origin,kind=' . json_encode($kind) . ';'
        . 'function norm(p){return p.replace(/index\.php$/,"")}'
        . 'function where(){var q=new URLSearchParams(location.search);try{parent.postMessage({galleryShell:1,kind:kind,folder:q.get("folder")||"",tab:(location.hash||"").slice(1)},origin)}catch(e){}}'
        . 'where();window.addEventListener("hashchange",where);window.addEventListener("pageshow",function(e){if(e.persisted)where()});'
        . ($kind === 'admin' ? 'var tk;function tick(){clearTimeout(tk);tk=setTimeout(function(){try{parent.postMessage({galleryShell:1,tick:1},origin)}catch(e){}},1200)}document.addEventListener("change",tick,true);document.addEventListener("click",function(e){if(e.target&&e.target.closest&&e.target.closest("button"))tick()},true);' : '')   // (a setting or a link was changed: the shell's numbers may have moved)
        . ($kind === 'admin' ? 'function top(){setTimeout(function(){scrollTo(0,0)},0)}window.addEventListener("load",top);window.addEventListener("hashchange",top);' : '')
        . 'document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;if(!a||e.defaultPrevented||a.target==="_blank"||a.hasAttribute("download"))return;var u;try{u=new URL(a.href,location.href)}catch(x){return}'
        . 'if(u.origin!==origin||norm(u.pathname)!==norm(location.pathname)||u.searchParams.has("inframe")||u.search===location.search)return;'
        . 'if(/^\?(img|video|archive|map|about|track|visitsexport|filesdownload|reveal)/.test(u.search))return;u.searchParams.set("inframe","1");a.href=u.href},true);})();</script>';
}

/** Does the admin offer a lock beside each folder (a one-press private/visible)? Only while the gallery is shown Flat: there are no folder
 *  cards then to carry the lock the cards have. */
function folder_lock_toggles_shown() {
    return AUTO_GROUP_BY_FOLDER && in_array(FOLDER_DISPLAY_MODE, ['flat', 'inline'], true);
}

/** The lock beside a folder in a tree: closed when the folder is private, open when it is visible, dimmed (and not pressable) when a
 *  folder above it makes it private. */
function folder_lock_button($path, $csrf, $cls = 'flock') {
    $dir = $GLOBALS['dir'];
    $own = folder_restriction_cached($dir, $path) === 'private';
    $inh = !$own && folder_above_is_private($dir, $path);
    $on = $own || $inh;
    return '<button type="button" class="' . $cls . ($on ? ' on' : '') . ($inh ? ' inh' : '') . '" data-lock-path="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"' . ($own ? ' data-own="1"' : '') . ' data-csrf="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '"' . ($inh ? ' disabled' : '')
        . ' aria-pressed="' . ($on ? 'true' : 'false') . '" title="' . ($inh ? 'Private because a folder above it is private' : ($on ? 'Private: press to make it visible' : 'Visible: press to make it private')) . '" aria-label="Private">' . ui_icon($on ? 'lock' : 'unlock') . '</button>';
}

/** The folders as the shell's tree: Root on top with the gallery's own picture, every folder nested inside it, a count of
 *  what is directly in each and a fold arrow beside the ones that hold folders. */
function shell_folder_tree($files, $current) {
    $e = function ($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); };
    $children = [];
    foreach (all_folder_paths($files) as $path) {
        $parent = strpos($path, '/') === false ? '' : substr($path, 0, strrpos($path, '/'));
        $children[$parent][] = $path;
    }
    [$own] = admin_folder_counts($files);
    $collNodes = [];   // the collections stand among the folders they live beside, in italics: they are not folders on disk
    if (AUTO_GROUP_BY_FOLDER && collections_any()) {
        foreach (collections_registry($GLOBALS['dir'], $files)['byPath'] as $cPath => $cRec) {
            $collNodes[$cPath] = $cRec; $own[$cPath] = count(collection_member_files($GLOBALS['dir'], $cRec, $files));
            $children[$cRec['parentPath']][] = $cPath;
        }
        foreach ($children as $cParent => $cList) { usort($cList, function ($a, $b) { return strnatcasecmp(basename($a), basename($b)); }); $children[$cParent] = $cList; }
    }
    $row = function ($path, $label, $icon = '') use ($own, $current, $e, $collNodes) {
        $cur = ($path === '' ? $current === null : $current === $path);
        $isColl = isset($collNodes[$path]);
        return '<a href="?admin&amp;shell=1' . ($path === '' ? '' : '&amp;folder=' . $e(rawurlencode($path))) . '" data-path="' . $e($path) . '" title="' . $e($isColl ? 'Collection: ' . $label : $label) . '"' . ($cur ? ' class="current' . ($isColl ? ' coll' : '') . '" aria-current="page"' : ($isColl ? ' class="coll"' : '')) . '>'
             . '<span class="fname' . ($isColl ? ' coll' : '') . '">' . $icon . $e($label) . '</span><span class="fcount">' . (int) ($own[$path] ?? 0) . '</span></a>';
    };
    $toggle = function ($name) use ($e) { return '<button type="button" class="ftoggle" aria-expanded="true" aria-label="Show or hide the folders inside ' . $e($name) . '"></button>'; };
    $lockCsrf = folder_lock_toggles_shown() ? admin_csrf_token() : '';
    $branch = function ($parent) use (&$branch, $children, $row, $toggle, $lockCsrf, $collNodes) {
        $out = '';
        foreach ($children[$parent] ?? [] as $path) {
            $kids = !empty($children[$path]);
            $nodeLabel = isset($collNodes[$path]) && $collNodes[$path]['title'] !== '' ? $collNodes[$path]['title'] : basename($path);
            $out .= '<li' . ($kids ? ' class="has-children"' : '') . '>' . $row($path, $nodeLabel) . ($lockCsrf !== '' && !isset($collNodes[$path]) ? folder_lock_button($path, $lockCsrf) : '') . ($kids ? $toggle(basename($path)) : '');
            if ($kids) $out .= '<ul>' . $branch($path) . '</ul>';
            $out .= '</li>';
        }
        return $out;
    };
    $rootKids = !empty($children['']);
    return '<nav class="sh-tree" aria-label="Folders"><ul><li class="root' . ($rootKids ? ' has-children' : '') . '">' . $row('', 'Root', ui_icon('gallery')) . ($rootKids ? $toggle('the gallery') : '')
         . ($rootKids ? '<ul>' . $branch('') . '</ul>' : '') . '</li></ul></nav>';
}

/** The small numbers beside the whole-gallery entries (the shell's sidebar and the classic admin's): each is a list of
 *  ['text' => ..., 'kind' => ''|'warn'|'bad'|'new', 'title' => ...]. Features: how many of the switchable features are on (amber and
 *  alone when one needs setup, the only one that asks you to act). Shares: the links in use, and how many have news since you last
 *  looked. Settings: nothing unless the Health check has something to fix (the rows the server can see: the web server rules are
 *  tested by the browser inside Settings itself). */
function admin_nav_counts($dir, $files) {
    $out = ['features' => [], 'links' => [], 'site' => [], 'visits' => []];
    $inactive = count(array_filter(scan_md_candidates($dir), function ($f) use ($dir) { return !in_array($f, scan_gallery_md_files($dir)['files'], true); }));
    $notesOn = scan_gallery_md_files($dir)['files'] !== [];
    $closed = gallery_is_closed(); $of = $closed ? 8 : 9;   // (a closed gallery's downloads are each share link's own choice: not one of the switches)
    $on = (int) AUTO_GROUP_BY_FOLDER + (int) ENABLE_VIDEO + (int) ENABLE_PHOTO_LOCATION + (int) ENABLE_SHARE_LINKS + (int) (ENABLE_PUBLIC_DOWNLOAD && !$closed)
        + (int) (TRACK_VIEWS || LOG_PUBLIC_VISITS) + (int) (!$inactive && $notesOn) + (int) ENABLE_THUMBNAIL_CACHE + (int) COUNTRY_LOOKUP;
    if ($inactive > 0) $out['features'][] = ['text' => (string) $inactive, 'kind' => 'warn', 'title' => $inactive . ' feature' . ($inactive === 1 ? '' : 's') . ' to set up'];
    else $out['features'][] = ['text' => $on . '/' . $of, 'kind' => '', 'title' => $on . ' of the ' . $of . ' features you can switch on are on'];
    if (ENABLE_SHARE_LINKS) {
        $seenAt = (int) (links_read()['seenAt'] ?? 0); $now = time(); $live = 0; $news = 0;
        foreach ((array) (links_read()['links'] ?? []) as $l) {
            if (!is_array($l) || !isset($l['id']) || link_is_public_log($l)) continue;
            if (link_state($l, $now) === 'active') $live++;
            if (link_news_count(link_activity($l['id'])[0], $seenAt) > 0) $news++;
        }
        if ($live > 0) $out['links'][] = ['text' => (string) $live, 'kind' => '', 'title' => $live . ' link' . ($live === 1 ? '' : 's') . ' in use'];
        if ($news > 0) $out['links'][] = ['text' => $news . ' new', 'kind' => 'new', 'title' => $news . ' link' . ($news === 1 ? ' has' : 's have') . ' news since you last looked'];
    }
    if (visits_tab_wanted() && ($vn = visitlog_visitor_count()) > 0) {
        $ln = lockout_unseen_count();   // (red while a lockout has happened that the Visitors tab has not been opened for)
        $out['visits'][] = ['text' => number_format($vn), 'kind' => $ln > 0 ? 'bad' : '', 'title' => number_format($vn) . ' visitor' . ($vn === 1 ? '' : 's') . ' in the log' . ($ln > 0 ? ', and ' . number_format($ln) . ' lockout' . ($ln === 1 ? '' : 's') . ' you have not seen' : '')];
    }
    $loud = 0;
    foreach (health_checks($dir, $files) as $r) if ($r[1] === 'bad' || $r[1] === 'warn') $loud++;
    if ($loud > 0) $out['site'][] = ['text' => (string) $loud, 'kind' => 'bad', 'title' => $loud . ' thing' . ($loud === 1 ? '' : 's') . ' to look at in the Health check'];
    return $out;
}
function admin_nav_badges($list) {
    $h = '';
    foreach ($list as $b) $h .= ' <span class="count' . ($b['kind'] !== '' ? ' ' . $b['kind'] : '') . '" title="' . htmlspecialchars($b['title'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($b['text'], ENT_QUOTES, 'UTF-8') . '</span>';
    return $h;
}

/** The whole-gallery entries of the shell's sidebar (also what the page asks for again to keep their numbers fresh: ?admin&shellcounts=1). */
function shell_whole_nav($dir, $files, $folderGroupKeys) {
    $hidden = admin_any_hidden($dir, $files, $folderGroupKeys) ? admin_hidden_data($dir, $files) : null;
    $edited = admin_edited_overview($dir, $files, $hidden);
    $counts = admin_nav_counts($dir, $files);
    $h = '<a href="?admin&amp;shell=1&amp;tab=features" data-tab="features">Features' . admin_nav_badges($counts['features']) . '</a>';
    if ($hidden || $edited['total'] > 0) $h .= '<a href="?admin&amp;shell=1&amp;tab=hidden" data-tab="hidden">Edited <span class="count">' . number_format($edited['total']) . '</span></a>';
    if (ENABLE_SHARE_LINKS) $h .= '<a href="?admin&amp;shell=1&amp;tab=links" data-tab="links">Shares' . admin_nav_badges($counts['links']) . '</a>';
    if (collections_any()) $h .= '<a href="?admin&amp;shell=1&amp;tab=collections" data-tab="collections">Collections <span class="count">' . count(collections_list()) . '</span></a>';
    $h .= '<a href="?admin&amp;shell=1&amp;tab=site" data-tab="site">Settings' . admin_nav_badges($counts['site']) . '</a>';
    if (visits_tab_wanted()) $h .= '<a href="?admin&amp;shell=1&amp;tab=visits" data-tab="visits">Visitors' . admin_nav_badges($counts['visits']) . '</a>';
    return $h;
}

/** The owner's bar on a phone. There is no frame on a phone (a frame and a fixed bar are trouble in iPhone Safari), so the pages stay the
 *  ordinary ones and carry one bar at the bottom: Gallery, Folder settings, Notes and a Menu sheet (the whole-gallery pages, the folders, Log
 *  out). Same bar on the gallery page and on the admin page; it keeps the folder you are in. $context is 'gallery' or 'admin'. */
function owner_phone_bar($context, $files, $folder, $showEdited, $logoutHtml, $counts = null, $editedTotal = null) {
    $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
    $inAdmin = $context === 'admin';
    $root = gallery_root_href();
    $galHref = $folder !== null ? $root . '?folder=' . rawurlencode($folder) : $root;
    $admBase = '?admin' . ($folder !== null ? '&folder=' . rawurlencode($folder) : '');
    $tab = function ($t) use ($inAdmin, $admBase) { return $inAdmin ? '#' . $t : $admBase . '#' . $t; };
    $whole = ['features' => 'Features'];
    if ($showEdited) $whole['hidden'] = 'Edited';
    if (ENABLE_SHARE_LINKS) $whole['links'] = 'Shares';
    if (collections_any()) $whole['collections'] = 'Collections';
    $whole['site'] = 'Settings';
    if (visits_tab_wanted()) $whole['visits'] = 'Visitors';
    $children = [];
    foreach (all_folder_paths($files) as $path) $children[strpos($path, '/') === false ? '' : substr($path, 0, strrpos($path, '/'))][] = $path;
    [$own] = admin_folder_counts($files);
    $collNodes = [];   // collections stand among the folders they live beside, in italics
    if (AUTO_GROUP_BY_FOLDER && collections_any()) {
        foreach (collections_registry($GLOBALS['dir'], $files)['byPath'] as $cPath => $cRec) {
            $collNodes[$cPath] = $cRec; $own[$cPath] = count(collection_member_files($GLOBALS['dir'], $cRec, $files));
            $children[$cRec['parentPath']][] = $cPath;
        }
        foreach ($children as $cParent => $cList) { usort($cList, function ($a, $b) { return strnatcasecmp(basename($a), basename($b)); }); $children[$cParent] = $cList; }
    }
    $folderLink = function ($path, $label) use ($e, $inAdmin, $root, $folder, $own, $collNodes) {
        $cur = ($path === '' ? $folder === null : $folder === $path);
        $href = $inAdmin ? '?admin' . ($path === '' ? '' : '&folder=' . rawurlencode($path)) : ($path === '' ? $root : $root . '?folder=' . rawurlencode($path));
        $isColl = isset($collNodes[$path]);
        return '<a class="pb-f' . ($cur ? ' cur' : '') . ($isColl ? ' coll' : '') . '" href="' . $e($href) . '"' . ($inAdmin ? ' data-keep' : '') . ($cur ? ' aria-current="page"' : '') . '><span class="n' . ($isColl ? ' coll' : '') . '">' . $e($label) . '</span><span class="c">' . (int) ($own[$path] ?? 0) . '</span></a>';
    };
    // Every folder is a row (a fold arrow when it holds folders, then the link); the folders inside it follow in a box that folds away. A branch
    // that does not lead to the folder you are in starts folded, like the shell's tree.
    $node = function ($path, $label, $depth) use (&$node, $children, $folderLink, $folder, $e, $collNodes) {
        $kids = $children[$path] ?? [];
        $open = $path === '' || ($folder !== null && ($folder === $path || strpos($folder . '/', $path . '/') === 0));
        $row = '<div class="pb-fr">' . ($kids ? '<button type="button" class="pb-t" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-label="Show or hide the folders inside ' . $e($label) . '"></button>' : '<span class="pb-t pb-t0"></span>') . $folderLink($path, $label) . ($path !== '' && !isset($collNodes[$path]) && folder_lock_toggles_shown() ? folder_lock_button($path, admin_csrf_token(), 'flock pb-lk') : '') . '</div>';
        $inner = '';
        foreach ($kids as $k) $inner .= $node($k, isset($collNodes[$k]) && $collNodes[$k]['title'] !== '' ? $collNodes[$k]['title'] : basename($k), $depth + 1);
        return '<div class="pb-g' . ($kids ? '' : ' leaf') . ($kids && !$open ? ' collapsed' : '') . '" style="--d:' . $depth . '" data-path="' . $e($path) . '"' . ($open ? ' data-here' : '') . '>' . $row . ($kids ? '<div class="pb-kids">' . $inner . '</div>' : '') . '</div>';
    };
    $h = '<nav class="pb" id="pb" aria-label="Your pages" data-context="' . $e($context) . '">'
       . '<a class="pb-i' . ($inAdmin ? '' : ' on') . '" href="' . $e($galHref) . '" data-pb="gallery"' . ($inAdmin ? '' : ' data-up="' . ($folder === null ? '' : $e(strpos($folder, '/') === false ? $root : $root . '?folder=' . rawurlencode(substr($folder, 0, strrpos($folder, '/'))))) . '"') . '>' . ui_icon('gallery') . '<span>Gallery</span></a>'
       . '<a class="pb-i" href="' . $e($tab('settings')) . '" data-pb="settings">' . ui_icon('gear') . '<span>Folder</span></a>'
       . '<a class="pb-i" href="' . $e($tab('notes')) . '" data-pb="notes">' . ui_icon('notes') . '<span>Notes</span></a>'
       . '<button type="button" class="pb-i" id="pb-menu" data-pb="menu" aria-expanded="false" aria-controls="pb-sheet">' . ui_icon('menu') . '<span>Menu</span></button>'
       . '</nav><div class="pb-sheet" id="pb-sheet" hidden' . ($counts === null ? ' data-lazy' : '') . '><div class="pb-top"><div class="pb-grab" aria-hidden="true"></div><div class="pb-head"><span class="pb-title">' . $e((string) ($GLOBALS['pageTitle'] ?? 'Gallery')) . '</span>' . $logoutHtml . '</div></div><div class="pb-scroll"><div class="pb-lab">The whole gallery</div>';
    foreach ($whole as $t => $label) {
        $badges = $t === 'hidden' ? ($editedTotal !== null ? ' <span class="count">' . number_format($editedTotal) . '</span>' : '') : ($t === 'collections' ? ' <span class="count">' . count(collections_list()) . '</span>' : admin_nav_badges($counts[$t === 'site' ? 'site' : $t] ?? []));
        $h .= '<a class="pb-w" href="' . $e($tab($t)) . '" data-pb-tab="' . $t . '"><span>' . $e($label) . '</span><span class="pb-b" data-b="' . $t . '">' . $badges . '</span></a>';
    }
    $h .= '<div class="pb-lab">Folders</div>' . $node('', 'Root', 0) . '</div></div>';
    return $h . '<script>window.galleryFolderLock=window.galleryFolderLock||function(b){if(b.disabled)return;var on=b.classList.contains("on");b.disabled=true;var body=new URLSearchParams({csrf_token:b.getAttribute("data-csrf"),action:"toggle_folder_restriction",hidden:on?"":"1"});fetch("?admin&folder="+encodeURIComponent(b.getAttribute("data-lock-path")),{method:"POST",body:body,credentials:"same-origin",headers:{"X-Requested-With":"gallery-fetch"}}).then(function(r){return r.json()}).then(function(j){b.disabled=false;if(j&&j.ok){var now=!on;b.classList.toggle("on",now);b.setAttribute("aria-pressed",now?"true":"false");b.title=now?"Private: press to make it visible":"Visible: press to make it private";var u=b.querySelector("use");if(u)u.setAttribute("href",now?"#i-lock":"#i-unlock")}}).catch(function(){b.disabled=false})};</script><script>(function(){var bar=document.getElementById("pb"),sheet=document.getElementById("pb-sheet"),btn=document.getElementById("pb-menu");if(!bar||!sheet||!btn)return;'
        . 'var admin=bar.getAttribute("data-context")==="admin",loaded=0,busy=false,stale=false,sBusy=false;'
        . 'function top(){setTimeout(function(){scrollTo(0,0)},30);setTimeout(function(){scrollTo(0,0)},200)}'
        . 'function flag(){try{sessionStorage.setItem("pbTop","1")}catch(x){}}'
        . 'function badge(o){var s=document.createElement("span");s.className="count"+(o.kind?" "+o.kind:"");s.title=o.title||"";s.textContent=o.text;return s}'
        . 'function fill(d){["features","links","site","visits"].forEach(function(k){var h=sheet.querySelector(\'[data-b="\'+k+\'"]\');if(!h)return;h.textContent="";(d[k]||[]).forEach(function(o){h.appendChild(document.createTextNode(" "));h.appendChild(badge(o))})});var e=sheet.querySelector(\'[data-b="hidden"]\');if(e&&typeof d.edited==="number"){e.textContent="";if(d.edited>0){e.appendChild(document.createTextNode(" "));e.appendChild(badge({text:d.edited.toLocaleString("en-US")}))}}}'
        . 'function counts(){if(!sheet.hasAttribute("data-lazy")||busy||Date.now()-loaded<20000)return;busy=true;fetch("?admin&pbcounts=1",{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json()}).then(function(d){fill(d);loaded=Date.now()}).catch(function(){}).then(function(){busy=false})}'
        . 'function open_(on){if(on){sheet.style.transition="";sheet.style.transform=""}sheet.hidden=!on;btn.setAttribute("aria-expanded",on?"true":"false");bar.classList.toggle("sheet",on);if(on){if(stale)refresh();counts()}}'
        . 'function close_(){sheet.style.transition="transform .18s ease-out";sheet.style.transform="translateY(100%)";setTimeout(function(){open_(false)},170)}'
        . 'btn.addEventListener("click",function(){if(sheet.hidden)open_(true);else close_()});'
        . 'document.addEventListener("click",function(e){if(!sheet.hidden&&!sheet.contains(e.target)&&!btn.contains(e.target))close_()});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!sheet.hidden)close_()});'
        // how the folders were left folded is remembered in this browser (the branch to the folder you are in is always open)
        . 'var FK="galleryMenuFolders|"+location.pathname.replace(/index\.php$/,""),fs={};try{fs=JSON.parse(localStorage.getItem(FK)||"{}")||{}}catch(x){fs={}}'
        . 'function applyFolds(){[].forEach.call(sheet.querySelectorAll(".pb-g[data-path]"),function(g){var p=g.getAttribute("data-path");if(g.hasAttribute("data-here")||!(p in fs))return;var t=g.querySelector(":scope > .pb-fr > button.pb-t");if(!t)return;g.classList.toggle("collapsed",fs[p]===0);t.setAttribute("aria-expanded",fs[p]===0?"false":"true")})}applyFolds();'
        // something was changed on this page (a setting, a folder\'s privacy): the Menu is asked for again, from the page itself, the next time it opens, so its locks and counts are not out of date
        . 'document.addEventListener("change",function(e){if(admin&&!sheet.contains(e.target))stale=true});'
        . 'function refresh(){if(sBusy)return;sBusy=true;fetch(location.pathname+location.search,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.text()}).then(function(h){var d=new DOMParser().parseFromString(h,"text/html"),n=d.querySelector("#pb-sheet .pb-scroll"),o=sheet.querySelector(".pb-scroll");if(!n||!o)return;o.innerHTML=n.innerHTML;applyFolds();stale=false;loaded=0}).catch(function(){}).then(function(){sBusy=false})}'
        . 'sheet.addEventListener("click",function(e){var lk=e.target.closest?e.target.closest(".flock"):null;if(lk){e.preventDefault();window.galleryFolderLock&&window.galleryFolderLock(lk);return}var t=e.target.closest?e.target.closest(".pb-t"):null;if(t&&t.tagName==="BUTTON"){var g=t.closest(".pb-g"),c=g.classList.toggle("collapsed");t.setAttribute("aria-expanded",c?"false":"true");fs[g.getAttribute("data-path")]=c?0:1;try{localStorage.setItem(FK,JSON.stringify(fs))}catch(x){}return}var a=e.target.closest?e.target.closest("a"):null;if(!a)return;if(a.hasAttribute("data-keep")){e.preventDefault();location.href=a.getAttribute("href").split("#")[0]+location.hash;return}if(!admin&&a.getAttribute("href").indexOf("?admin")===0)flag();open_(false);if(admin&&a.getAttribute("href").charAt(0)==="#")top()});'
        . 'bar.addEventListener("click",function(e){var a=e.target.closest?e.target.closest("a.pb-i"):null;if(!a)return;var h=a.getAttribute("href")||"";'
        // on the gallery page the Gallery button is also "up": scrolled down it goes to the top of this page, at the top it goes up one folder
        . 'if(!admin&&a.getAttribute("data-pb")==="gallery"&&a.hasAttribute("data-up")){e.preventDefault();if(window.pageYOffset>8){var calm=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;window.scrollTo({top:0,behavior:calm?"auto":"smooth"})}else if(a.getAttribute("data-up")!=="")location.href=a.getAttribute("data-up");return}'
        . 'if(!admin){if(h.indexOf("?admin")===0)flag()}else if(h.charAt(0)==="#")top()});'
        // dragging the sheet down dismisses it: from its top strip at once, from the list once the list is scrolled to its top (one gesture can scroll the list up and carry on into the dismissal)
        . 'var top_=sheet.querySelector(".pb-top"),box=sheet.querySelector(".pb-scroll"),y0=null,dy=0,drag=false,last=0,fromTop=false;'
        . 'function begin(y){y0=y;dy=0;drag=true;sheet.style.transition="none"}'
        . 'sheet.addEventListener("touchstart",function(e){drag=false;y0=null;if(e.touches.length!==1)return;last=e.touches[0].clientY;fromTop=!!(top_&&top_.contains(e.target));if(fromTop)begin(last)},{passive:true});'
        . 'sheet.addEventListener("touchmove",function(e){if(e.touches.length!==1)return;var y=e.touches[0].clientY;if(!drag){if(!fromTop&&box.scrollTop<=0&&y>last)begin(y);else{last=y;return}}'
        . 'dy=y-y0;last=y;if(dy>=0){if(e.cancelable)e.preventDefault();sheet.style.transform="translateY("+dy+"px)"}else{dy=0;sheet.style.transform="";if(!fromTop){drag=false;y0=null}}},{passive:false});'
        . 'sheet.addEventListener("touchend",function(){if(!drag)return;drag=false;if(dy>80)close_();else{sheet.style.transition="transform .15s ease-out";sheet.style.transform=""}dy=0;y0=null});'
        . 'if(admin){function mark(){var p=document.querySelector(".tab-panel.active"),t=p?p.getAttribute("data-tab"):(location.hash||"").slice(1),m=t==="settings"?"settings":t==="notes"?"notes":t?"menu":"";[].forEach.call(bar.querySelectorAll("[data-pb]"),function(x){x.classList.toggle("on",x.getAttribute("data-pb")===m)})}mark();window.addEventListener("hashchange",function(){setTimeout(mark,0)});function arm(){mark();var mm=document.querySelector(".admin-main");if(window.MutationObserver&&mm)new MutationObserver(mark).observe(mm,{attributes:true,attributeFilter:["class"],subtree:true})}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",arm);else arm();'
        . 'var f=false;try{f=sessionStorage.getItem("pbTop")==="1";if(f)sessionStorage.removeItem("pbTop")}catch(x){}if(f){window.addEventListener("load",top);top()}}'
        . '})();</script>';
}
function owner_phone_bar_css() {
    return '.pb, .pb-sheet { display:none; }
  @media (max-width: 800px) {
    body.pb-on:not(.selecting) { padding-bottom: calc(66px + env(safe-area-inset-bottom, 0px)); }
    body.pb-on .map-back { bottom: calc(76px + env(safe-area-inset-bottom, 0px)); }   /* (above the bar, not on its buttons) */
    .pb { display:flex; position:fixed; left:0; right:0; bottom:0; z-index:60; background:#0f0f10; border-top:1px solid #26262a; padding-bottom:env(safe-area-inset-bottom, 0px); }
    body.selecting .pb, body.selecting .pb-sheet { display:none; }
    .pb-i { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; min-height:58px; padding:0; background:none; border:0; border-radius:0; color:#9a9aa2; font:inherit; font-size:0.72rem; line-height:1.2; text-decoration:none; cursor:pointer; -webkit-tap-highlight-color:transparent; }
    .pb-i .ico { width:22px; height:22px; }
    .pb-i.on, .pb.sheet #pb-menu { color:#fff; }
    .pb-i.on .ico, .pb.sheet #pb-menu .ico { color:#aab2ff; }
    .pb.sheet .pb-i[data-pb="gallery"].on, .pb.sheet .pb-i[data-pb="settings"].on, .pb.sheet .pb-i[data-pb="notes"].on { color:#9a9aa2; }
    .pb-sheet:not([hidden]) { display:flex; flex-direction:column; position:fixed; left:0; right:0; bottom:calc(58px + env(safe-area-inset-bottom, 0px)); z-index:59; max-height:min(72vh, 560px); box-sizing:border-box; padding:0; overflow:hidden; background:#18181b; border-top:1px solid #2c2d30; border-radius:18px 18px 0 0; box-shadow:0 -8px 24px rgba(0,0,0,0.5); }
    .pb-top { flex:none; padding:8px 16px 2px; touch-action:none; }
    .pb-scroll { flex:1; min-height:0; overflow-y:auto; overscroll-behavior:contain; padding:0 16px 14px; }
    .pb-head { display:flex; align-items:center; justify-content:space-between; gap:12px; min-height:46px; }
    .pb-title { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:700; font-size:1rem; color:#fff; }
    .pb-head button, body.pb-on .admin-bar .logout-form button.secondary { flex:none; display:inline-flex; align-items:center; justify-content:center; width:42px; min-height:34px; height:34px; padding:0; border:1px solid #2c2c30; border-radius:8px; background:#1a1a1c; color:#eaeaea; font-size:1.05rem; cursor:pointer; }   /* (the gallery icon button: the same Log out as the shell) */
    .pb-head button .ico, body.pb-on .admin-bar .logout-form .ico { width:1.15em; height:1.15em; }
    body.pb-on .admin-bar .logout-form .lo-text { display:none; }
    .pb-grab { width:38px; height:4px; border-radius:2px; background:#3a3b40; margin:0 auto 4px; }
    .pb-w > span:first-child { flex:1; }
    .pb-b { flex:none; display:inline-flex; align-items:center; }
    .pb-b .count { margin-left:6px; font-size:0.72rem; font-weight:600; padding:1px 8px; border-radius:10px; background:rgba(255,255,255,0.09); color:#a0a0a8; }
    .pb-b .count.new { background:rgba(88,101,242,0.35); color:#c7ccff; }
    .pb-b .count.warn { background:rgba(245,194,107,0.2); color:#f5c26b; }
    .pb-b .count.bad { background:rgba(237,66,69,0.25); color:#ff9a9c; }
    body.pb-on .admin-bar h1 { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); clip-path:inset(50%); white-space:nowrap; }   /* (not shown, but still the heading of the page for a screen reader) */
    body.pb-on .admin-bar a.back { max-width:64vw; }
    body.pb-on .admin-folder-head .admin-tabs { display:none; }   /* (the bars Folder and Notes are those tabs) */
    body.pb-on header h1 a.title-admin { pointer-events:none; }   /* (a phone has the bar at the bottom: the title is only a title) */
    body.pb-on .admin-layout.off-folder .admin-side { display:none; }   /* (the folder picker is for jumping between the settings or the notes of folders: on Features, Shares, Settings and Edited it has nothing to do) */
    body.pb-on .admin-side .side-group { display:none; }   /* (the Menu has the whole-gallery pages; the folder picker stays: it is the quick way to another folder\'s settings) */
    .pb-lab { font-size:0.72rem; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#8a8a8f; margin:10px 0 4px; }
    .pb-scroll > .pb-lab:first-child { margin-top:2px; }
    .pb-w, .pb-f { display:flex; align-items:center; justify-content:space-between; gap:10px; min-height:44px; box-sizing:border-box; color:#eaeaea; text-decoration:none; border-top:1px solid #26262a; font-size:0.95rem; }
    .pb-fr { display:flex; align-items:stretch; padding-left:calc(var(--d, 0) * 14px); border-top:1px solid #26262a; }
    .pb-kids > .pb-g { position:relative; }
    .pb-kids > .pb-g::before { content:""; position:absolute; left:calc(var(--d, 1) * 14px + 1px); top:0; bottom:0; border-left:1px solid #34343a; }
    .pb-kids > .pb-g:last-child::before { bottom:auto; height:23px; }
    .pb-t.pb-t0 { width:8px; }   /* (nothing inside this folder: no room kept for a fold arrow) */
    .pb-kids > .pb-g.leaf > .pb-fr::before { width:7px; }
    .pb-kids > .pb-g > .pb-fr::before { content:""; position:absolute; left:calc(var(--d, 1) * 14px + 1px); top:23px; width:12px; border-top:1px solid #34343a; }
    .pb-fr .pb-f { flex:1; min-width:0; border-top:0; }
    .pb-t { flex:none; width:30px; min-height:44px; padding:0; border:0; background:none; color:#8a8a8f; display:flex; align-items:center; justify-content:center; cursor:pointer; }
    button.pb-t::before { content:"\\25BE"; font-size:1.25rem; line-height:1; transition:transform .12s; }
    .pb-g.collapsed > .pb-fr > button.pb-t::before { transform:rotate(-90deg); }
    .pb-g.collapsed > .pb-kids { display:none; }
    .pb-f { font-size:0.9rem; color:#c9c9cd; }
    .pb-f .n { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pb-f .c { flex:none; font-size:0.72rem; color:#8a8a8f; }
    .pb-w.cur, .pb-f.cur { color:#fff; font-weight:600; background:#5865f2; border-radius:8px; border-top-color:transparent; padding-left:12px; padding-right:12px; }
    .pb-w.cur .count, .pb-w.cur .c, .pb-f.cur .c { background:rgba(0,0,0,0.3); color:#fff; }
    .pb-f.cur .c { background:none; }
    .pb-fr:has(> .pb-lk) { position:relative; }
    .pb-fr:has(> .pb-lk) .pb-f .n { margin-right:44px; }   /* (room for the lock, which sits just before the count, same as the desktop tree) */
    .pb-lk { all:unset; box-sizing:border-box; flex:none; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; width:44px; min-height:44px; color:#77777e; position:absolute; right:26px; top:0; bottom:0; }
    .pb-lk .ico { width:17px; height:17px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .pb-lk.on { color:#ffb3b5; } .pb-lk.inh { opacity:.45; cursor:default; }
    .pb-sheet.off-folder .pb-f.cur { background:rgba(255,255,255,0.07); font-weight:500; }
  }';
}

/** The folders that have a display name of their own, as path => name (the shell's path line shows these; a gallery with a great many
 *  folders leaves them out). */
function shell_folder_labels($dir, $files) {
    $labels = [];
    $paths = all_folder_paths($files);
    if (AUTO_GROUP_BY_FOLDER && collections_any()) foreach (array_keys(collections_registry($dir, $files)['byPath']) as $cPath) $paths[] = $cPath;   // (a collection's title is its display name)
    if (count($paths) <= 400) foreach ($paths as $path) { $l = folder_display_meta($dir, $path)['label']; if ($l !== '') $labels[$path] = $l; }
    return $labels;
}

/** The shell page itself. $adminFolderPath is the folder it opens on (null: the front page). */
function render_shell_page($dir, $files, $adminFolderPath, $folderGroupKeys) {
    $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
    $title = (string) ($GLOBALS['pageTitle'] ?? 'Gallery');
    $hidden = admin_any_hidden($dir, $files, $folderGroupKeys) ? admin_hidden_data($dir, $files) : null;
    $edited = admin_edited_overview($dir, $files, $hidden);
    $showEdited = (bool) $hidden || $edited['total'] > 0;
    $whole = ['features' => 'Features', 'hidden' => 'Edited', 'links' => 'Shares', 'site' => 'Settings', 'visits' => 'Visitors'];
    $tab = (string) ($_GET['tab'] ?? '');
    if ($tab !== '' && $tab !== 'settings' && $tab !== 'notes' && !isset($whole[$tab])) $tab = '';
    if (($tab === 'hidden' && !$showEdited) || ($tab === 'links' && !ENABLE_SHARE_LINKS) || ($tab === 'visits' && !visits_tab_wanted())) $tab = '';
    $labels = shell_folder_labels($dir, $files);
    $rootHref = gallery_root_href();
    $folderQ = $adminFolderPath !== null ? 'folder=' . rawurlencode($adminFolderPath) . '&' : '';
    $src = $tab === '' ? $rootHref . '?' . $folderQ . 'inframe=1' : '?admin&' . $folderQ . 'inframe=1#' . $tab;
    $cfg = ['title' => $title, 'labels' => (object) $labels, 'folder' => (string) ($adminFolderPath ?? ''), 'tab' => $tab, 'root' => $rootHref, 'whole' => $whole];
    $csrf = $e(admin_csrf_token());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> — Admin</title>
<style>
  :root { --bg:#0f0f10; --card:#1a1a1c; --text:#eaeaea; --muted:#8a8a8f; --accent:#5865f2; --line:#26262a; }
  * { box-sizing:border-box; }
  html, body { height:100%; }
  html { background:var(--bg); }
  body { margin:0 auto; max-width:1400px; /* (no wider than the gallery itself, centred like it) */ background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.5; display:flex; flex-direction:column; }
  <?= ui_icon_css() ?>
