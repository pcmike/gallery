/* -----------------------------------------------------------------------
 * What the visitor log shows the owner (the Visitors tab; all server-rendered
 * fragments fetched into it): the visitors of the public address and of every
 * share link, the folders and photos they looked at, the timeline, and the CSV
 * exports. Every view is a query over the SQLite log, narrowed by the same
 * few choices: which sources (the public address, share links), which
 * countries, and a search (an address, a browser, a country).
 * ----------------------------------------------------------------------- */

/** The folder an item lives in ('' for the gallery's own). */
function link_item_folder($rel) { $s = strrpos($rel, '/'); return $s === false ? '' : substr($rel, 0, $s); }

/** How many items are under a folder in the gallery (all of them: the owner's list). */
function link_folder_total($files, $folder) {
    static $counts = null;
    if ($counts === null) { $counts = ['' => count($files)]; foreach ($files as $f) { $d = link_item_folder($f); while ($d !== '') { $counts[$d] = ($counts[$d] ?? 0) + 1; $d = link_item_folder($d); } } }
    return $counts[$folder] ?? 0;
}

function link_csv_cell($v) {
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;   // a spreadsheet would run it as a formula otherwise
    return '"' . str_replace('"', '""', $s) . '"';
}

function link_seg_html($name, $options, $current, $label = 'Choose one') {
    $out = '<div class="sl-segbar" role="group" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($options as $v => $t) $out .= '<button type="button" class="sl-segbtn' . ((string) $v === (string) $current ? ' on' : '') . '" data-tab="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</button>';
    return $out . '</div>';
}

/** Country names by their two-letter codes. */
function visit_country_names() {
    static $names = [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan', 'AG' => 'Antigua and Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AO' => 'Angola', 'AQ' => 'Antarctica', 'AR' => 'Argentina',
        'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Åland Islands', 'AZ' => 'Azerbaijan', 'BA' => 'Bosnia and Herzegovina', 'BB' => 'Barbados', 'BD' => 'Bangladesh', 'BE' => 'Belgium',
        'BF' => 'Burkina Faso', 'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi', 'BJ' => 'Benin', 'BL' => 'Saint Barthélemy', 'BM' => 'Bermuda', 'BN' => 'Brunei', 'BO' => 'Bolivia', 'BQ' => 'Caribbean Netherlands',
        'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan', 'BV' => 'Bouvet Island', 'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize', 'CA' => 'Canada', 'CC' => 'Cocos Islands', 'CD' => 'DR Congo',
        'CF' => 'Central African Republic', 'CG' => 'Congo', 'CH' => 'Switzerland', 'CI' => 'Côte d\'Ivoire', 'CK' => 'Cook Islands', 'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia', 'CR' => 'Costa Rica',
        'CU' => 'Cuba', 'CV' => 'Cape Verde', 'CW' => 'Curaçao', 'CX' => 'Christmas Island', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany', 'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica',
        'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt', 'EH' => 'Western Sahara', 'ER' => 'Eritrea', 'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland',
        'FJ' => 'Fiji', 'FK' => 'Falkland Islands', 'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France', 'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada', 'GE' => 'Georgia', 'GF' => 'French Guiana',
        'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Greenland', 'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe', 'GQ' => 'Equatorial Guinea', 'GR' => 'Greece', 'GS' => 'South Georgia',
        'GT' => 'Guatemala', 'GU' => 'Guam', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HK' => 'Hong Kong', 'HM' => 'Heard Island', 'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary',
        'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man', 'IN' => 'India', 'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy',
        'JE' => 'Jersey', 'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya', 'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros', 'KN' => 'Saint Kitts and Nevis',
        'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka',
        'LR' => 'Liberia', 'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya', 'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova', 'ME' => 'Montenegro',
        'MF' => 'Saint Martin', 'MG' => 'Madagascar', 'MH' => 'Marshall Islands', 'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar', 'MN' => 'Mongolia', 'MO' => 'Macao', 'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique',
        'MR' => 'Mauritania', 'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Maldives', 'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia',
        'NC' => 'New Caledonia', 'NE' => 'Niger', 'NF' => 'Norfolk Island', 'NG' => 'Nigeria', 'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue',
        'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia', 'PG' => 'Papua New Guinea', 'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PM' => 'Saint Pierre and Miquelon',
        'PN' => 'Pitcairn Islands', 'PR' => 'Puerto Rico', 'PS' => 'Palestine', 'PT' => 'Portugal', 'PW' => 'Palau', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania', 'RS' => 'Serbia',
        'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SB' => 'Solomon Islands', 'SC' => 'Seychelles', 'SD' => 'Sudan', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'Saint Helena', 'SI' => 'Slovenia',
        'SJ' => 'Svalbard and Jan Mayen', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone', 'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Suriname', 'SS' => 'South Sudan', 'ST' => 'São Tomé and Príncipe', 'SV' => 'El Salvador',
        'SX' => 'Sint Maarten', 'SY' => 'Syria', 'SZ' => 'Eswatini', 'TC' => 'Turks and Caicos Islands', 'TD' => 'Chad', 'TF' => 'French Southern Territories', 'TG' => 'Togo', 'TH' => 'Thailand', 'TJ' => 'Tajikistan', 'TK' => 'Tokelau',
        'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan', 'TN' => 'Tunisia', 'TO' => 'Tonga', 'TR' => 'Türkiye', 'TT' => 'Trinidad and Tobago', 'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine',
        'UG' => 'Uganda', 'UM' => 'U.S. Outlying Islands', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VA' => 'Vatican City', 'VC' => 'Saint Vincent and the Grenadines', 'VE' => 'Venezuela', 'VG' => 'British Virgin Islands', 'VI' => 'U.S. Virgin Islands',
        'VN' => 'Vietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis and Futuna', 'WS' => 'Samoa', 'XK' => 'Kosovo', 'YE' => 'Yemen', 'YT' => 'Mayotte', 'ZA' => 'South Africa', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];
    return $names;
}

/** The English name of a country from its two-letter code (the code itself when unknown). */
function visit_country_name($cc) { return visit_country_names()[strtoupper((string) $cc)] ?? (string) $cc; }

/** The codes of the countries whose name holds this word (so searching "germany" finds the visitors from DE). */
function visit_country_codes_matching($word) {
    $out = [];
    $w = strtolower($word);
    if (strlen($w) < 3) return $out;
    foreach (visit_country_names() as $cc => $name) if (stripos($name, $w) !== false) $out[] = $cc;
    return $out;
}

/** The visitor log's name for a source: the public address, or a link's name. */
function visits_source_labels() {
    $out = [PUBLIC_LOG_ID => 'Public', LOCKOUT_LOG_ID => 'Lockouts'];
    foreach ((array) (links_read()['links'] ?? []) as $l) if (is_array($l) && isset($l['id'], $l['label']) && !link_is_public_log($l)) $out[$l['id']] = (string) $l['label'];
    return $out;
}

/** What the Visitors tab takes from the address bar: sources ("public" and link ids, comma separated), countries (codes), a search, and for the Media view the folders, the choice and "show all". */
function visits_filters() {
    $csv = function ($k, $rx) {
        $out = [];
        foreach (explode(',', isset($_GET[$k]) && is_string($_GET[$k]) ? substr($_GET[$k], 0, 1000) : '') as $v) {
            $v = trim($v);
            if ($k === 'src' && $v === 'public') $v = PUBLIC_LOG_ID;
            if ($k === 'src' && $v === 'lockouts') $v = LOCKOUT_LOG_ID;
            if (preg_match($rx, $v)) $out[$v] = true;
        }
        return array_slice(array_keys($out), 0, 60);
    };
    $list = function ($k) {
        $out = [];
        foreach (isset($_GET[$k]) && is_array($_GET[$k]) ? array_slice($_GET[$k], 0, 300) : [] as $v) if (is_string($v)) { $v = str_replace("\0", '', $v); if ($v !== '') $out[$v] = true; }
        return array_keys($out);
    };
    $what = isset($_GET['what']) && is_string($_GET['what']) ? $_GET['what'] : '';
    return [
        'src' => $csv('src', '/^[0-9a-f]{10}$/'),
        'cc' => $csv('cc', '/^[A-Z]{2}$/'),
        'q' => substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '')), 0, 80),
        'folders' => array_map(function ($f) { return $f === '/' ? '' : (string) $f; }, $list('folders')),
        'what' => in_array($what, ['viewed', 'recent', 'photos', 'videos', 'private', 'opened', 'downloaded'], true) ? $what : 'viewed',
        'all' => isset($_GET['all']) && $_GET['all'] === '1',
        'exact' => isset($_GET['exact']) && $_GET['exact'] === '1',   // (a folder's own files only, not the folders inside it: what a number on the Folders view counted)
    ];
}

/** [" WHERE ...", params] narrowing a query over the visitors table (or a table with the same src, ip, ua and country columns) to the chosen sources, countries and search. $a is the table's alias. */
function visits_where(array $f, $a = 'v') {
    $w = []; $p = [];
    foreach ([['src', 'src'], ['cc', 'country']] as [$key, $col]) {
        if (!$f[$key]) continue;
        $w[] = $a . '.' . $col . ' IN (' . implode(',', array_fill(0, count($f[$key]), '?')) . ')';
        foreach ($f[$key] as $x) $p[] = $x;
    }
    foreach (preg_split('/\s+/', (string) $f['q'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
        $or = [$a . ".ip LIKE ? ESCAPE '\\'", $a . ".ua LIKE ? ESCAPE '\\'", $a . ".country LIKE ? ESCAPE '\\'"];
        array_push($p, $like, $like, $like);
        if ($codes = visit_country_codes_matching($word)) { $or[] = $a . '.country IN (' . implode(',', array_fill(0, count($codes), '?')) . ')'; foreach ($codes as $c) $p[] = $c; }
        $w[] = '(' . implode(' OR ', $or) . ')';
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
}

function visits_query($db, $sql, array $p = []) { $st = $db->prepare($sql); $st->execute($p); return $st; }

/** The visitors the choices leave, newest first: [rows, how many there are in all]. */
function visits_load_visitors($db, array $f, $limit) {
    [$w, $p] = visits_where($f, 'v');
    $total = (int) visits_query($db, 'SELECT COUNT(*) FROM visitors v' . $w, $p)->fetchColumn();
    $rows = visits_query($db, 'SELECT v.* FROM visitors v' . $w . ' ORDER BY v.last DESC LIMIT ' . (int) $limit, $p)->fetchAll();
    return [$rows, $total];
}
/** Per file, across the visitors the choices leave: when first seen, times opened / played / downloaded, last activity (file => ['sf', 'on', 'pn', 'dn', 'last']). */
function visits_load_media($db, array $f) {
    [$w, $p] = visits_where($f, 'v');
    $st = visits_query($db, 'SELECT i.item, MIN(NULLIF(i.sf, 0)) AS sf, SUM(i.opn) AS opn, SUM(i.pln) AS pln, SUM(i.dwn) AS dwn, MAX(MAX(i.sl, i.opl, i.pll, i.dwl)) AS last FROM items i JOIN visitors v ON v.src = i.src AND v.vid = i.vid' . $w . ' GROUP BY i.item', $p);
    $m = [];
    foreach ($st as $r) $m[$r['item']] = ['sf' => (int) $r['sf'], 'on' => (int) $r['opn'], 'pn' => (int) $r['pln'], 'dn' => (int) $r['dwn'], 'last' => (int) $r['last']];
    return $m;
}
/** Folder pages opened, across the visitors the choices leave (folder => ['n', 'first', 'last']). */
function visits_load_folder_opens($db, array $f) {
    [$w, $p] = visits_where($f, 'v');
    $st = visits_query($db, 'SELECT o.folder, SUM(o.n) AS n, MIN(o.first) AS first, MAX(o.last) AS last FROM folders o JOIN visitors v ON v.src = o.src AND v.vid = o.vid' . $w . ' GROUP BY o.folder', $p);
    $out = [];
    foreach ($st as $r) $out[$r['folder']] = ['n' => (int) $r['n'], 'first' => (int) $r['first'], 'last' => (int) $r['last']];
    return $out;
}
/** The happenings in order, newest first: [events, how many there are in all]. */
function visits_load_events($db, array $f, $limit) {
    [$w, $p] = visits_where($f, 'v');
    $from = ' FROM events e JOIN visitors v ON v.src = e.src AND v.vid = e.vid' . $w;
    $total = (int) visits_query($db, 'SELECT COUNT(*)' . $from, $p)->fetchColumn();
    $out = [];
    foreach (visits_query($db, 'SELECT e.*, v.ip AS _ip' . $from . ' ORDER BY e.t1 DESC, e.id DESC LIMIT ' . (int) $limit, $p) as $r) {
        $e = ['t' => (int) $r['t'], 't1' => (int) $r['t1'], 'k' => $r['k'], 'f' => $r['f'], 'd' => $r['d'], 'n' => (int) $r['n'], 's' => $r['s'] !== null ? (array) json_decode($r['s'], true) : [], 'h' => (string) $r['h'], 'u' => (int) $r['u'], '_src' => $r['src'], '_ip' => $r['_ip']];
        $out[] = $e;
    }
    return [$out, $total];
}

/** One menu of the filter line: a button that opens a list of tick boxes (any number; the first row, "All", is ticked while none of the others is) or of round buttons (one). Both apply as soon as they are touched.
 *  $options: value => [text, text for the button, depth in the tree]. */
function link_pop_html($key, $multi, $allLabel, $noun, $options, $chosen, $default = '', $note = '', $exact = false) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $chosen = array_values(array_filter(array_map('strval', $chosen), function ($v) use ($options) { return isset($options[$v]); }));
    $n = count($chosen);
    if ($multi) $label = $n === 0 ? $allLabel : ($n === 1 ? $options[$chosen[0]][1] . ($exact ? ' only' : '') : $n . ' ' . $noun);
    else $label = $options[$chosen[0] ?? $default][1] ?? $allLabel;
    $active = $multi ? $n > 0 : ($chosen && $chosen[0] !== $default);
    $out = '<div class="sl-pop" data-pop="' . $esc($key) . '" data-multi="' . ($multi ? '1' : '0') . '"><button type="button" class="sl-popbtn' . ($active ? ' on' : '') . '" aria-haspopup="true" aria-expanded="false"><span class="sl-poplab">' . $esc($label) . '</span><span class="sl-caret" aria-hidden="true">&#9662;</span></button><div class="sl-popmenu" hidden>';
    if ($multi) $out .= '<label class="sl-popopt sl-popall"><input type="checkbox" data-all value=""' . ($n === 0 ? ' checked' : '') . '><span>' . $esc($allLabel) . '</span></label>';
    foreach ($options as $v => $o) {
        $on = $multi ? in_array((string) $v, $chosen, true) : ((string) ($chosen[0] ?? $default) === (string) $v);
        $depth = min(6, (int) ($o[2] ?? 0));
        $out .= '<label class="sl-popopt' . ($depth > 0 ? ' sl-d' . $depth : '') . '"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="pop-' . $esc($key) . '" value="' . $esc($v) . '"' . ($on ? ' checked' : '') . '><span>' . $esc($o[0]) . '</span></label>';
    }
    if ($note !== '') $out .= '<p class="sl-popnote">' . $esc($note) . '</p>';
    return $out . '</div></div>';
}

/** Is this folder one of the chosen folders, or inside one? (The front page is only itself.) $exact: only the chosen folders themselves. */
function link_folder_selected($f, $set, $exact) {
    if (isset($set[$f])) return true;
    if ($exact || $f === '') return false;
    for ($d = dirname($f); $d !== '.' && $d !== ''; $d = dirname($d)) if (isset($set[$d])) return true;
    return false;
}

/** A small linked picture of a photo or video: it opens the folder at that photo, as the thumbnails everywhere else in the log do. */
function link_pic_html($rel, $cls = 'sl-tth') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    return '<a class="' . $cls . '" href="' . admin_find_href($rel) . '" title="' . $esc($rel) . '"><img src="' . $esc(img_url($rel, 'thumb')) . '" alt="" loading="lazy" decoding="async"></a>';
}

/** A folder (or the front page) as a link that opens it in the gallery, in a new tab. */
function visits_folder_link($dir, $rel) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $label = $rel === '' ? 'Front page' : link_folder_label($dir, $rel);
    return '<a class="vt-ci" href="' . ($rel === '' ? '?' : '?folder=' . $esc(rawurlencode($rel))) . '" target="_blank" rel="noopener" title="Open it in the gallery"><b>' . $esc($label) . '</b></a>';
}

/** Where a visit came in (the public address's records): a folder or the front page, or a Not listed photo opened by its own address, each a link to it. */
function visits_entry_html($dir, $a) {
    if (!is_array($a) || !is_string($a['f'] ?? null)) return '<span class="muted">—</span>';
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    if (!empty($a['u'])) return '<a class="vt-ci" href="' . admin_find_href($a['f']) . '" target="_blank" rel="noopener" title="Open it in the gallery"><span class="sl-lock" title="Not listed">' . ui_icon('lock') . '</span> Not listed photo <b>' . $esc(basename($a['f'])) . '</b></a>';
    return visits_folder_link($dir, $a['f']);
}

/** The country as a button that picks it in the All menu above (the name is its tooltip). */
function visits_country_html($cc) {
    if ($cc === '') return '<span class="muted">—</span>';
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    return '<button type="button" class="vt-cc" data-cc="' . $esc($cc) . '" title="' . $esc(visit_country_name($cc)) . '">' . $esc($cc) . '</button>';
}

/** The old addresses a link's visitors came in on, for the links that have had more than one: [src => [vid => "name, name"]]. */
function visits_via_map($db, array $srcs, array $labels) {
    $out = [];
    if (!$srcs) return $out;
    foreach (visits_query($db, 'SELECT src, vid, name FROM notes WHERE kind = \'via\' AND src IN (' . implode(',', array_fill(0, count($srcs), '?')) . ')', array_values($srcs))->fetchAll() as $r) {
        $out[$r['src']][$r['vid']][] = link_address_label($r['name'], link_address_is_custom($r['name']));
    }
    return $out;
}

/** Which of a link's addresses a visitor came in on: the old addresses their notes name; else, for someone first seen after the link got a second address, the present one; else ''. Only for links that have had a second address. */
function visits_via_text($l, $names, $anyVia, $first) {
    if (!is_array($l) || (empty($l['aliases']) && !$anyVia)) return '';
    $text = $names ? implode(', ', array_unique($names)) : '';
    if ($text === '' && ($since = (int) ($l['aliasSince'] ?? 0)) > 0 && (int) $first > $since) $text = link_address_label($l['token'], !empty($l['custom']));
    return $text;
}

/** The part of the Visitors tab that changes with the choices: the table (or grid, or timeline) of one view for the visitors the choices leave. Wrapped in a div that says how many visitors match. */
function render_visits_fragment($tab, array $f, $dir, $files) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $db = is_file(visitlog_path()) && visitlog_ready() ? visitlog_db() : null;
    $none = function ($text) { return '<div class="vt-frag" data-match="0" data-all="0"><p class="muted sl-none">' . $text . '</p></div>'; };
    if ($db === null) return $none('The visitor log isn\'t available: ' . $esc(visitlog_check()[1]));
    $all = (int) visits_query($db, 'SELECT COUNT(*) FROM visitors')->fetchColumn();
    $wantLock = $f['src'] === [LOCKOUT_LOG_ID];
    [$w0, $p0] = visits_where($f, 'v');
    $match = (int) visits_query($db, 'SELECT COUNT(*) FROM visitors v' . $w0, $p0)->fetchColumn();
    $wrap = function ($html) use ($match, $all) { return '<div class="vt-frag" data-match="' . $match . '" data-all="' . $all . '">' . $html . '</div>'; };
    if ($all === 0) return $wrap('<p class="muted sl-none">' . ($wantLock ? 'No lockouts have been logged.' : 'Nobody has been logged yet.') . '</p>');
    if ($match === 0) return $wrap('<p class="muted sl-none">Nobody matches. <button type="button" class="linklike" data-vt="clear">Clear the choices</button></p>');
    $labels = visits_source_labels();
    $links = (array) (links_read()['links'] ?? []);

    if ($tab === 'visitors') {
        [$rows, $total] = visits_load_visitors($db, $f, 300);
        $linkSrcs = []; foreach ($rows as $r) if ($r['src'] !== PUBLIC_LOG_ID && $r['src'] !== LOCKOUT_LOG_ID) $linkSrcs[$r['src']] = true;
        $via = visits_via_map($db, array_keys($linkSrcs), $labels);
        $out = '<div class="sl-scroll"><table class="sl-table sl-cards vt-table"><thead><tr><th>IP address</th><th>Where</th><th>Browser</th><th>Came in at</th><th>First seen</th><th>Last seen</th><th>Opens</th><th>Viewed</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $pub = $r['src'] === PUBLIC_LOG_ID; $lock = $r['src'] === LOCKOUT_LOG_ID; $l = $links[$r['src']] ?? null;
            if ($lock) $came = '<button type="button" class="vt-src" data-src="lockouts" title="Show only the lockouts">Locked out: ' . $esc(lockout_kind_names()[(string) $r['entry_h']] ?? 'sign-in') . (((int) $r['opens']) > 1 ? ' &times;' . (int) $r['opens'] : '') . '</button>';
            elseif ($pub) $came = visits_entry_html($dir, $r['entry_f'] !== null ? ['f' => $r['entry_f'], 'h' => $r['entry_h']] + ($r['entry_u'] ? ['u' => 1] : []) : null);
            else {
                $viaText = visits_via_text($l, $via[$r['src']][$r['vid']] ?? null, !empty($via[$r['src']]), $r['first']);
                $came = '<button type="button" class="vt-src" data-src="' . $esc($r['src']) . '" title="Show only visitors who came in on this link">' . $esc($labels[$r['src']] ?? 'A deleted link') . '</button>' . ($viaText !== '' ? '<span class="sl-via">via: ' . $esc($viaText) . '</span>' : '');
            }
            $out .= '<tr><td data-label="IP address"><button type="button" class="linklike vt-ip" data-ip="' . $esc($r['ip']) . '">' . $esc($r['ip']) . '</button></td><td data-label="Where">' . visits_country_html($r['country']) . '</td><td data-label="Browser">' . $esc($r['ua']) . '</td><td data-label="Came in at">' . $came . '</td><td data-label="First seen">' . link_time_html($r['first']) . '</td><td data-label="Last seen">' . link_time_html($r['last']) . '</td><td data-label="Opens">' . ($lock ? '—' : (int) $r['opens']) . '</td><td data-label="Viewed">' . ($lock ? '—' : '<button type="button" class="linklike vt-viewed" data-ip="' . $esc($r['ip']) . '" data-go="media">' . (int) $r['views'] . ' views &rsaquo;</button>') . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
        if ($total > count($rows)) $out .= '<p class="muted">Showing the latest ' . count($rows) . ' of ' . number_format($total) . '. Narrow it with the choices above, or export the rest.</p>';
        $folded = 0;
        if (!$f['cc'] && $f['q'] === '') foreach ($f['src'] ?: array_keys($labels) as $s) $folded += visitlog_folded($db, $s);
        if ($folded > 0) $out .= '<p class="muted">+ ' . number_format($folded) . ' earlier visitor' . ($folded === 1 ? '' : 's') . ' (trimmed out of the list; their opens and views were counted before).</p>';
        [$wb, $pb] = visits_where($f, 'b');
        $wb = $wb === '' ? ' WHERE b.src <> ?' : $wb . ' AND b.src <> ?'; $pb[] = PUBLIC_LOG_ID;
        $blocked = visits_query($db, 'SELECT b.* FROM blocked b' . $wb . ' ORDER BY b.last DESC LIMIT 100', $pb)->fetchAll();
        if ($blocked) {
            $out .= '<div class="sl-sub2 warn">Blocked tries &middot; ' . count($blocked) . ' address' . (count($blocked) === 1 ? '' : 'es') . '</div><div class="sl-scroll"><table class="sl-table"><thead><tr><th>IP address</th><th>Where</th><th>Browser</th><th>Link</th><th>First tried</th><th>Last tried</th><th>Tries</th></tr></thead><tbody>';
            foreach ($blocked as $b) {
                $why = []; foreach ((array) json_decode((string) $b['why'], true) as $s => $n) $why[] = ['expired' => 'expired', 'used_up' => 'already used', 'paused' => 'paused', 'revoked' => 'revoked', 'scheduled' => 'not started', 'replaced' => 'old address'][$s] ?? $s;
                $out .= '<tr class="rj"><td><span class="sl-ipt">' . $esc($b['ip']) . '</span></td><td>' . visits_country_html($b['country']) . '</td><td>' . $esc($b['ua']) . '</td><td>' . $esc($labels[$b['src']] ?? 'A deleted link') . '</td><td>' . link_time_html($b['first']) . '</td><td>' . link_time_html($b['last']) . '</td><td class="sl-why">' . (int) $b['tries'] . ' &middot; ' . $esc(implode(', ', array_unique($why))) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }
        return $wrap($out . '<details class="fold sl-howto"><summary>How to read this</summary><p class="note">An IP address is a device on a network, not a person: a phone moving from wifi to cellular looks like two. On the public address a visit is a visitor arriving, or coming back after half an hour; on a link, opens count the &ldquo;Open the gallery&rdquo; presses. Views count photos and folder pages looked at afterwards. Someone who used the public address and a link is in the list once for each.</p></details>');
    }

    $m = visits_load_media($db, $f); $opens = visits_load_folder_opens($db, $f);

    if ($tab === 'folders') {
        $fo = []; $blank = ['opens' => 0, 'seen' => 0, 'opened' => 0, 'down' => 0, 'first' => 0, 'last' => 0];
        foreach ($m as $rel => $a) {
            $d = link_item_folder($rel); $x = &$fo[$d]; $x = $x ?? $blank;
            if ($a['sf']) { $x['seen']++; $x['first'] = $x['first'] ? min($x['first'], $a['sf']) : $a['sf']; }
            if ($a['on'] || $a['pn']) $x['opened']++;
            if ($a['dn']) $x['down']++;
            $x['last'] = max($x['last'], $a['last']); unset($x);
        }
        foreach ($opens as $d => $o) { $x = &$fo[$d]; $x = $x ?? $blank; $x['opens'] = $o['n']; $x['first'] = $x['first'] ? min($x['first'], $o['first']) : $o['first']; $x['last'] = max($x['last'], $o['last']); unset($x); }
        uasort($fo, function ($a, $b) { return $b['last'] <=> $a['last']; });
        if (!$fo) return $wrap('<p class="muted sl-none">Nothing has been looked at yet.</p>');
        $out = '<div class="sl-scroll"><table class="sl-table"><thead><tr><th>Folder</th><th>Opens</th><th>Media seen</th><th>Opened</th><th>Downloaded</th><th>First seen</th><th>Last activity</th></tr></thead><tbody>';
        foreach ($fo as $rel => $x) {
            $rel = (string) $rel; $priv = $rel !== '' && folder_is_private($dir, $rel);
            $go = function ($n, $what, $text) use ($esc, $rel) { return $n > 0 ? '<button type="button" class="linklike sl-num" data-media="' . $what . '" data-folder="' . $esc($rel === '' ? '/' : $rel) . '">' . $esc($text) . '</button>' : '—'; };
            $out .= '<tr><td>' . ($priv ? '<span class="sl-lock" title="Private folder">' . ui_icon('lock') . '</span> ' : '') . $esc($rel === '' ? 'Front page' : link_folder_label($dir, $rel)) . '</td><td>' . ($x['opens'] ?: '—') . '</td><td>' . $go($x['seen'], 'viewed', $x['seen'] . ' of ' . link_folder_total($files, $rel)) . '</td><td>' . $go($x['opened'], 'opened', $x['opened']) . '</td><td>' . $go($x['down'], 'downloaded', $x['down']) . '</td><td>' . ($x['first'] ? link_time_html($x['first']) : '—') . '</td><td>' . ($x['last'] ? link_time_html($x['last']) : '—') . '</td></tr>';
        }
        return $wrap($out . '</tbody></table></div><details class="fold sl-howto"><summary>How to read this</summary><p class="note">&ldquo;Opens&rdquo; is how many times the folder&rsquo;s page was opened. &ldquo;Media seen&rdquo; is photos and videos that were on their screen, even as a thumbnail; &ldquo;Opened&rdquo; is the ones shown large in the viewer or played; &ldquo;Downloaded&rdquo; is the ones whose original was downloaded. A lock marks a private folder. Press a number to see those photos and videos.</p></details>');
    }

    if ($tab === 'media') {
        $dirs = []; foreach (array_keys($m) as $rel) $dirs[link_item_folder($rel)] = true; foreach (array_keys($opens) as $d) $dirs[(string) $d] = true;
        $opts = [];
        if (isset($dirs[''])) $opts['/'] = ['Front page', 'Front page', 0];   // (the front page first, then the folders as a tree in the gallery's own order)
        $nested = false;
        foreach (folder_tree_rows($dir, array_values(array_filter(array_map('strval', array_keys($dirs)), function ($d) { return $d !== ''; }))) as $r) { $opts[$r['p']] = [$r['n'], link_folder_label($dir, $r['p']), $r['d']]; if ($r['d'] > 0) $nested = true; }
        $what = [];
        foreach (['viewed' => 'Most viewed', 'recent' => 'Most recent', 'photos' => 'Photos', 'videos' => 'Videos', 'private' => 'Private', 'opened' => 'Opened', 'downloaded' => 'Downloaded'] as $k => $t) $what[$k] = [$t, $t, 0];
        $bar = '<div class="sl-filterbar">' . link_pop_html('folders', true, 'All folders', 'folders', $opts, array_map(function ($x) { return $x === '' ? '/' : $x; }, $f['folders']), '', $nested ? 'A folder includes the folders inside it.' : '', $f['exact']) . link_pop_html('what', false, 'Most viewed', '', $what, [$f['what']], 'viewed') . '</div>';
        $kept = $m;
        if ($f['folders']) { $fs = array_flip($f['folders']); $ex = $f['exact']; $m = array_filter($m, function ($a, $rel) use ($fs, $ex) { return link_folder_selected(link_item_folder($rel), $fs, $ex); }, ARRAY_FILTER_USE_BOTH); }
        $wh = $f['what'];
        if ($wh === 'photos') $m = array_filter($m, function ($a, $rel) { return !is_video_file($rel); }, ARRAY_FILTER_USE_BOTH);
        elseif ($wh === 'videos') $m = array_filter($m, function ($a, $rel) { return is_video_file($rel); }, ARRAY_FILTER_USE_BOTH);
        elseif ($wh === 'opened') $m = array_filter($m, function ($a) { return $a['on'] + $a['pn'] > 0; });
        elseif ($wh === 'downloaded') $m = array_filter($m, function ($a) { return $a['dn'] > 0; });
        elseif ($wh === 'private') { $allItems = items_get_all_for_files($dir, array_keys($m)); $m = array_filter($m, function ($a, $rel) use ($dir, $allItems) { return file_is_hidden($dir, $rel, $allItems); }, ARRAY_FILTER_USE_BOTH); }
        if ($wh === 'recent') uasort($m, function ($a, $b) { return $b['last'] <=> $a['last']; });
        else uasort($m, function ($a, $b) { return ($b['on'] + $b['pn']) <=> ($a['on'] + $a['pn']) ?: $b['last'] <=> $a['last']; });
        if (!$m) return $wrap($bar . '<p class="muted sl-none">' . ($kept ? 'No photo or video matches that.' : 'Nothing has been looked at yet.') . '</p>');
        $shown = $f['all'] ? $m : array_slice($m, 0, 60, true);
        $inAll = items_get_all_for_files($dir, array_keys($shown));
        $out = '<div class="sl-grid">';
        foreach ($shown as $rel => $a) {
            $priv = file_is_hidden($dir, $rel, $inAll);
            $badge = ($a['pn'] > 0 ? 'played ×' . $a['pn'] : ($a['on'] > 0 ? '×' . $a['on'] : 'seen')) . ($a['dn'] > 0 ? ' · downloaded' : '');
            $out .= '<a class="sl-th" href="' . admin_find_href($rel) . '" title="' . $esc($rel) . '"><img src="' . $esc(img_url($rel, 'thumb')) . '" alt="" loading="lazy" decoding="async"><span class="sl-badge">' . $esc($badge) . '</span>' . ($priv ? '<span class="sl-lk">' . ui_icon('lock') . '</span>' : '') . '</a>';
        }
        $out .= '</div>';
        if (!$f['all'] && count($m) > 60) $out .= '<button type="button" class="secondary sl-more" data-more>Show all ' . count($m) . '</button>';
        return $wrap($bar . $out . '<p class="note">Showing ' . count($shown) . ' of ' . count($m) . ' photos and videos that were on their screen. A lock marks a private one; the badge is how many times it was opened (&ldquo;seen&rdquo;: only on screen). Press one to open it in the gallery.</p>');
    }

    // timeline
    [$events, $evTotal] = visits_load_events($db, $f, 300);
    if (!$events) return $wrap('<p class="muted sl-none">Nothing to show yet.</p>');
    $out = '<div class="sl-timeline">';
    foreach ($events as $e) {
        $k = $e['k']; $pic = '';
        $when = (int) $e['t1'] !== (int) $e['t'] ? link_time_html($e['t']) . ' – ' . link_time_html($e['t1']) : link_time_html($e['t']);
        if ($k === 'scroll') {
            $text = 'Scrolled through <b>' . $esc(($e['d'] ?? '') === '' ? 'the front page' : link_folder_label($dir, $e['d'])) . '</b> &middot; saw ' . (int) $e['n'] . ' photo' . ((int) $e['n'] === 1 ? '' : 's');
            $thumbs = ''; foreach ($e['s'] as $s) $thumbs .= link_pic_html($s);
            $more = (int) $e['n'] - count($e['s']);
            if ($thumbs !== '') $text .= '<div class="sl-strip">' . $thumbs . ($more > 0 ? '<span class="sl-more-n">+' . $more . ' more</span>' : '') . '</div>';
        }
        elseif ($k === 'opened' || $k === 'played' || $k === 'saved') {
            $verb = ['opened' => 'Opened', 'played' => 'Played', 'saved' => 'Downloaded the original of'][$k];
            $text = $verb . ' <b>' . $esc(basename((string) $e['f'])) . '</b>' . (file_is_unlisted($dir, (string) $e['f']) ? ' <span class="sl-lock" title="Not listed">' . ui_icon('lock') . ' Not listed</span>' : '') . ($k === 'opened' ? '<div class="muted">' . $esc(link_folder_label($dir, link_item_folder((string) $e['f']))) . '</div>' : '');
            $pic = link_pic_html((string) $e['f']);
        }
        elseif ($k === 'arrive') $text = 'Arrived at ' . visits_entry_html($dir, ['f' => (string) $e['f'], 'h' => $e['h']] + ($e['u'] ? ['u' => 1] : [])) . '<div class="muted">' . $esc(['typed' => 'an address typed or pasted, or a bookmark or app', 'link' => 'a link on another site', 'other' => 'a way the browser did not say'][$e['h']] ?? '') . '</div>';
        elseif ($k === 'folder') $text = 'Opened the folder <b>' . $esc(($e['f'] ?? '') === '' ? 'front page' : link_folder_label($dir, (string) $e['f'])) . '</b>';
        elseif ($k === 'zip') $text = 'Downloaded a ZIP of <b>' . $esc(($e['f'] ?? '') === '' ? 'the gallery' : link_folder_label($dir, (string) $e['f'])) . '</b> (' . (int) ($e['n'] ?? 0) . ' files)';
        elseif ($k === 'link') $text = $e['_src'] === PUBLIC_LOG_ID ? 'Visited the gallery' : 'Opened the link';
        elseif ($k === 'lockout') $text = ['signin' => 'Locked out of signing in: too many wrong access codes', 'setup' => 'Locked out of setting up: too many wrong setup codes', 'addr' => 'Locked out: too many addresses that did not work'][(string) $e['d']] ?? 'Locked out';
        else continue;
        $who = '<button type="button" class="linklike vt-ip" data-ip="' . $esc($e['_ip']) . '">' . $esc($e['_ip']) . '</button> &middot; ' . ($e['_src'] === LOCKOUT_LOG_ID ? 'paused for a minute' : ($e['_src'] === PUBLIC_LOG_ID ? 'public address' : 'via ' . $esc($labels[$e['_src']] ?? 'a deleted link')));
        $out .= '<div class="sl-ev"><span class="sl-evt">' . $when . '</span><div class="' . ($pic !== '' ? 'sl-evpic' : 'sl-evbody') . '">' . $pic . '<div>' . $text . '<div class="muted sl-evl">' . $who . '</div></div></div></div>';
    }
    $out .= '</div>' . ($evTotal > count($events) ? '<p class="note">Showing the latest ' . count($events) . ' of ' . number_format($evTotal) . ' lines. Narrow it with the choices above, or export the whole timeline.</p>' : '');
    return $wrap($out . '<details class="fold sl-howto"><summary>How to read this</summary><p class="note">Scrolling is one line per stretch. Opened, played and downloaded items get their own line. The Folders and Media views keep their own, exact tallies.</p></details>');
}

/** Is there anything for the Visitors tab to show? The log is usable here and share links or the public log are on, or something was logged before. */
function visits_tab_wanted() {
    static $w = null;
    if ($w !== null) return $w;
    if (!visitlog_driver_ok()) return $w = false;
    if (!ENABLE_SHARE_LINKS && !LOG_PUBLIC_VISITS && (!is_file(visitlog_path()) || visitlog_visitor_count() === 0)) return $w = false;
    return $w = visitlog_ready();
}

/** The Visitors tab: who opened the gallery without a link and who used each share link. The pills, search and views are the page's own; the table under them is a fragment (render_visits_fragment) fetched as they change. */
/** What the top of the Visitors panel shows besides its table: whether the log is usable, the numbers behind the pills (info), the summary line (HTML), the
 *  share links and how many public visitors there are. The panel draws from it, and asks for it again (as JSON, ?admin&visits=1&tab=info) when the window comes back. */
function visits_panel_data() {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $ok = visitlog_usable();
    if ($ok) visitlog_tx(function ($db) { visitlog_retention($db, time()); });
    $db = $ok ? visitlog_db() : null;
    $labels = visits_source_labels();
    $links = [];
    foreach ((array) (links_read()['links'] ?? []) as $l) if (is_array($l) && isset($l['id'], $l['label']) && !link_is_public_log($l)) $links[] = $l;
    $bySrc = []; $countries = []; $total = 0; $lockN = 0; $lockTrips = 0;
    if ($db !== null) {
        foreach (visits_query($db, 'SELECT src, COUNT(*) AS c, SUM(opens) AS o FROM visitors GROUP BY src')->fetchAll() as $r) {
            if ($r['src'] === LOCKOUT_LOG_ID) { $lockN = (int) $r['c']; $lockTrips = (int) $r['o']; }
            $bySrc[$r['src']] = (int) $r['c']; $total += (int) $r['c'];
        }
        foreach (visits_query($db, 'SELECT country, COUNT(*) AS c FROM visitors WHERE country <> \'\' GROUP BY country ORDER BY c DESC, country')->fetchAll() as $r) $countries[] = ['c' => $r['country'], 'name' => visit_country_name($r['country']), 'n' => (int) $r['c']];
    }
    $pubN = (int) ($bySrc[PUBLIC_LOG_ID] ?? 0); $linkN = $total - $pubN - $lockN;
    $info = ['total' => $total, 'pub' => $pubN, 'lock' => $lockN, 'links' => array_map(function ($l) use ($bySrc) { return ['id' => $l['id'], 'name' => $l['label'], 'n' => (int) ($bySrc[$l['id']] ?? 0)]; }, $links), 'countries' => $countries];
    $plural = function ($n, $one, $many) { return number_format($n) . ' ' . ($n === 1 ? $one : $many); };
    $span = LOG_KEEP_DAYS > 0 ? 'in the last ' . ($plural(LOG_KEEP_DAYS, 'day', 'days')) : 'so far';
    $summary = $plural($pubN, 'public visitor', 'public visitors') . ' and ' . $plural($linkN, 'visitor', 'visitors') . ' on share links ' . $span . ' &middot; ' . $plural(count($links), 'share', 'shares') . ($lockTrips > 0 ? ' &middot; ' . $plural($lockTrips, 'lockout', 'lockouts') : '');
    $info['unseen'] = $ok ? lockout_unseen_count() : 0;   // (lockouts since the tab was last opened: what the sidebar's red number counts)
    return ['ok' => $ok, 'info' => $info, 'summary' => $summary, 'links' => $links, 'pubN' => $pubN];
}

/** The Trim dialog's choice of which records: all, the public address, the lockouts, each link. */
function visits_trim_options(array $links) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $h = '<option value="all">All of them</option><option value="' . $esc(PUBLIC_LOG_ID) . '">The public address</option><option value="' . $esc(LOCKOUT_LOG_ID) . '">The lockouts</option>';
    foreach ($links as $l) $h .= '<option value="' . $esc($l['id']) . '">' . $esc($l['label']) . '</option>';
    return $h;
}

function render_admin_visits_panel($csrfToken, $dir, $files) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $d = visits_panel_data();
    $ok = $d['ok']; $info = $d['info']; $summary = $d['summary']; $links = $d['links']; $pubN = $d['pubN'];
    $csrf = $esc($csrfToken);
?>
    <section class="panel vt" id="vt-root" data-csrf="<?= $csrf ?>" data-info="<?= $esc(json_encode($info, JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
      <h2 class="section-title">Visitors</h2>
      <?php if (!$ok): ?>
        <div class="sl-off">The visitor log isn't available here. <?= $esc(visitlog_check()[1]) ?></div>
      <?php else: ?>
      <?php if (($proxyHint = client_ip_proxy_hint()) !== ''): ?>
        <div class="sl-warn"><?= ui_icon('warn') ?><div><strong>Visitors may all look like one address.</strong> <?= $esc($proxyHint) ?> It is the proxy setting in Settings (<em>Where visitors' IP addresses come from</em>).</div></div>
      <?php endif; ?>
      <?php if (!LOG_PUBLIC_VISITS): ?>
        <p class="muted vt-off">Logging visitors to the public address is off<?= $pubN > 0 ? ' (what was logged stays here until you trim it)' : '' ?>. <a href="#site" onclick="return adminGoTo('site', 'setting-LOG_PUBLIC_VISITS');">Turn it on in Settings</a>.</p>
      <?php endif; ?>
      <p class="muted vt-sum"><?= $summary ?></p>
      <div class="vt-top">
        <div class="vt-pills">
          <span class="vt-pw"><button type="button" class="vt-chip" data-pill="cc" aria-haspopup="true" aria-expanded="false"><span class="vt-l">All</span> &#9662; <em></em></button></span>
          <span class="vt-pw"><button type="button" class="vt-chip" data-pill="src" aria-haspopup="true" aria-expanded="false"><span class="vt-l">Sources</span> &#9662; <em></em></button></span>
        </div>
        <div class="vt-sw"><input type="search" class="vt-search" placeholder="Find a visitor" aria-label="Find a visitor" autocomplete="off" spellcheck="false" enterkeyhint="search"><button type="button" class="vt-sx" data-vt="clearq" aria-label="Clear the search" title="Clear the search" hidden>&times;</button></div>
        <div class="vt-menuwrap">
          <button type="button" class="vt-ic" data-vt="menu" title="Export or trim the log" aria-label="Export or trim the log" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 20h14"/></svg></button>
          <div class="vt-menu" hidden>
            <a href="#" data-export="visitors">Visitors<small>One line per IP: opens, first and last seen</small></a>
            <a href="#" data-export="items">What they looked at<small>One line per item: seen, opened, played, downloaded</small></a>
            <a href="#" data-export="events">Everything, in order<small>The timeline, one line per happening</small></a>
            <button type="button" data-vt="trim">Trim the log&hellip;</button>
            <span class="sl-menu-note">CSVs hold what the choices above show, with IP addresses, and open in your downloads.</span>
          </div>
        </div>
      </div>
      <div class="sl-segbar vt-views" role="group" aria-label="Part of the log"><button type="button" class="sl-segbtn on" data-view="visitors">Visitors</button><button type="button" class="sl-segbtn" data-view="folders">Folders</button><button type="button" class="sl-segbtn" data-view="media">Media</button><button type="button" class="sl-segbtn" data-view="timeline">Timeline</button></div>
      <div class="vt-note" role="status"></div>
      <div class="vt-body"><?= render_visits_fragment('visitors', visits_filters(), $dir, $files) ?></div>
      <div class="admin-modal" id="vt-modal" role="dialog" aria-modal="true" aria-labelledby="vt-modal-title" hidden>
        <div class="admin-modal-card">
          <h3 id="vt-modal-title">Trim the log</h3>
          <div class="sl-modal-body">
            <div class="sl-f"><span class="sl-k">Which records</span><select id="vt-trim-src"><?= visits_trim_options($links) ?></select></div>
            <div class="sl-f"><span class="sl-k">Remove what is older than</span><select id="vt-trim-age"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="pick">A date&hellip;</option></select> <input type="date" id="vt-trim-date" hidden></div>
            <p class="hint" id="vt-trim-out" role="status">Counting&hellip;</p>
            <p class="hint">Opens, views and the number of visitors stay. To keep the lines themselves, download them first: <a id="vt-trim-dl-events" href="#">the timeline</a> &middot; <a id="vt-trim-dl-items" href="#">what they looked at</a> &middot; <a id="vt-trim-dl-visitors" href="#">visitors</a> (CSV, with IP addresses).</p>
          </div>
          <div class="pm-btns"><button type="button" class="secondary" data-vt-modal="cancel">Cancel</button><button type="button" class="sl-danger-btn" data-vt-modal="ok">Trim</button></div>
        </div>
      </div>
      <div class="pv-toast" id="vt-toast" role="status" aria-live="polite" hidden></div>
      <?php endif; ?>
    </section>
<?php
}

/** The Trim the log dialog's request: what is older than a number of days or a date goes from one source's records or from all of them; apply=1 does it, otherwise it only counts. Answers JSON. */
function visits_trim_action() {
    $now = time();
    $bad = function ($text) { admin_json_exit(['ok' => false, 'text' => $text]); };
    if (!visitlog_usable()) $bad('The visitor log isn\'t available here: ' . visitlog_check()[1]);
    $id = (string) ($_POST['id'] ?? 'all');
    if ($id !== 'all' && !preg_match('/^[0-9a-f]{10}$/', $id)) $bad('Unknown source.');
    $before = (int) ($_POST['before'] ?? 0); $days = (int) ($_POST['days'] ?? 0);
    $cutoff = $before > 0 ? $before : $now - $days * 86400;
    if (($before <= 0 && $days <= 0) || $cutoff > $now) $bad('Pick how old a line has to be to go: a number of days, or a date in the past.');
    $apply = ($_POST['apply'] ?? '') === '1';
    $tot = link_log_trim($id === 'all' ? null : $id, $cutoff, $apply);
    $lines = $tot['events'] + $tot['items'] + $tot['folders'] + $tot['blocked'];
    $what = number_format($tot['events']) . ' line' . ($tot['events'] === 1 ? '' : 's') . ' of the timeline, ' . number_format($tot['visitors']) . ' visitor' . ($tot['visitors'] === 1 ? '' : 's') . ' and ' . number_format($tot['items'] + $tot['folders']) . ' record' . ($tot['items'] + $tot['folders'] === 1 ? '' : 's') . ' of what was looked at' . ($tot['blocked'] ? ', and ' . number_format($tot['blocked']) . ' turned-away ' . ($tot['blocked'] === 1 ? 'try' : 'tries') : '');
    $none = $lines + $tot['visitors'] === 0;
    admin_json_exit(['ok' => true, 'applied' => $apply, 'counts' => $tot, 'nothing' => $none,
        'text' => $none ? 'Nothing is that old: there is nothing to trim.' : ($apply ? 'Trimmed: ' : 'This would remove ') . $what . '.' . ($apply ? '' : ' Opens, views and the total number of visitors stay.')]);
}

/** CSV of the visitor log: what=visitors | items | events, for the sources, countries and search the Visitors tab has chosen. */
function visits_export($what, array $f, $dir) {
    if (!visitlog_usable()) { http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); echo 'The visitor log isn\'t available here.'; return; }
    $db = visitlog_db();
    if (!in_array($what, ['visitors', 'items', 'events'], true)) $what = 'items';
    $labels = visits_source_labels();
    [$w, $p] = visits_where($f, 'v');
    $head = ['visitors' => ['source', 'ip', 'where', 'browser', 'first_seen', 'last_seen', 'opens', 'views', 'came_in_on', 'came_in_at'], 'items' => ['source', 'ip', 'browser', 'folder', 'file', 'first_seen', 'opened', 'played', 'downloaded', 'last_activity'], 'events' => ['time', 'source', 'ip', 'what', 'folder', 'file', 'count']][$what];
    $iso = function ($t) { return $t ? gmdate('Y-m-d H:i:s', (int) $t) : ''; };
    header('Content-Type: text/csv; charset=UTF-8');
    shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
    header('Content-Disposition: attachment; filename="visitors-' . $what . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF" . implode(',', array_map('link_csv_cell', $head)) . "\r\n";
    $line = function (array $r) { echo implode(',', array_map('link_csv_cell', $r)) . "\r\n"; };
    $src = function ($s) use ($labels) { return $labels[$s] ?? 'A deleted link'; };
    if ($what === 'visitors') {
        $links = (array) (links_read()['links'] ?? []); $via = [];
        foreach (visits_query($db, 'SELECT src, vid, name FROM notes WHERE kind = \'via\'')->fetchAll() as $n) $via[$n['src']][$n['vid']][] = link_address_label($n['name'], link_address_is_custom($n['name']));
        foreach (visits_query($db, 'SELECT v.* FROM visitors v' . $w . ' ORDER BY v.last DESC', $p) as $r)
            $line([$src($r['src']), $r['ip'], $r['country'], $r['ua'], $iso($r['first']), $iso($r['last']), $r['opens'], $r['views'], visits_via_text($links[$r['src']] ?? null, $via[$r['src']][$r['vid']] ?? null, !empty($via[$r['src']]), $r['first']),
                   $r['entry_f'] === null ? '' : ($r['entry_u'] ? 'Not listed photo ' . basename((string) $r['entry_f']) : ($r['entry_f'] === '' ? 'Front page' : $r['entry_f']))]);
    } elseif ($what === 'items') {
        foreach (visits_query($db, 'SELECT i.*, v.ip, v.ua FROM items i JOIN visitors v ON v.src = i.src AND v.vid = i.vid' . $w . ' ORDER BY i.src, v.ip, i.item', $p) as $r)
            $line([$src($r['src']), $r['ip'], $r['ua'], link_item_folder($r['item']), basename($r['item']), $iso($r['sf']), (int) $r['opn'], (int) $r['pln'], (int) $r['dwn'], $iso(max((int) $r['sl'], (int) $r['opl'], (int) $r['pll'], (int) $r['dwl']))]);
    } else {
        foreach (visits_query($db, 'SELECT e.*, v.ip FROM events e JOIN visitors v ON v.src = e.src AND v.vid = e.vid' . $w . ' ORDER BY e.t, e.id', $p) as $e) {
            $k = $e['k']; $fl = (string) ($e['f'] ?? $e['d'] ?? '');
            if ($k === 'lockout') { $line([$iso($e['t']), $src($e['src']), $e['ip'], 'lockout: ' . (lockout_kind_names()[(string) $e['d']] ?? ''), '', '', 1]); continue; }
            $isDir = $k === 'scroll' || $k === 'folder' || $k === 'zip' || ($k === 'arrive' && !$e['u']);
            $line([$iso($e['t']), $src($e['src']), $e['ip'], $k === 'scroll' ? 'seen' : ($k === 'saved' ? 'downloaded' : ($k === 'arrive' ? 'arrived' : $k)), $isDir ? ($k === 'scroll' ? (string) $e['d'] : $fl) : link_item_folder($fl), $isDir ? '' : basename($fl), $e['n'] ?: 1]);
        }
    }
}

/** The fragments and downloads the Visitors tab and the Shares tab ask for: ?admin&visits=1 (the table under the choices), &visitsexport=visitors|items|events, and the link form's linkphotos and linkcover. */
function run_links_view_route($dir, $files) {
    header('Cache-Control: no-store');
    $str = function ($k) { return isset($_GET[$k]) && is_string($_GET[$k]) ? str_replace("\0", '', $_GET[$k]) : ''; };
    $data = links_read();
    if (isset($_GET['visitsexport'])) { visits_export($str('visitsexport'), visits_filters(), $dir); return; }
    if (isset($_GET['linkspanel'])) {   // the Shares panel on its own (the window came back): what the page draws for it, without the rest of the page
        header('Content-Type: text/html; charset=UTF-8');
        render_admin_links_panel(htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'), $dir, $files);
        return;
    }
    if (isset($_GET['visits']) && $str('tab') === 'info') {   // the panel's numbers again (the window came back): small, and not the whole page
        header('Content-Type: application/json; charset=UTF-8');
        $d = visits_panel_data();
        echo json_encode(['ok' => $d['ok'], 'info' => $d['info'], 'sum' => $d['summary'], 'trim' => visits_trim_options($d['links'])], JSON_HEX_TAG | JSON_HEX_AMP);
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    if (isset($_GET['visits'])) {
        $tab = in_array($str('tab'), ['visitors', 'folders', 'media', 'timeline'], true) ? $str('tab') : 'visitors';
        echo render_visits_fragment($tab, visits_filters(), $dir, $files);
        return;
    }
    if (isset($_GET['linkphotos'])) { echo render_link_photo_picker($str('linkphotos'), $files, $dir, $str('cover') === '1', link_draft_scope_from_request()); return; }
    if ($str('linkcover') === 'folders') {   // the folders that have public photos directly in them (among those the link covers), as a tree, for the preview photo dialog: the first one is where it opens
        header('Content-Type: application/json; charset=UTF-8');
        [, $public] = link_public_media($dir);
        $coverDraft = link_draft_scope_from_request();
        $public = array_values(array_filter($public, function ($f) use ($dir, $coverDraft) { return link_cover_in_scope(null, $dir, $f, $coverDraft); }));
        $count = [];
        foreach ($public as $f) if (!is_video_file($f)) { $d = link_item_folder($f); $count[$d] = ($count[$d] ?? 0) + 1; }
        $out = [];
        if (!empty($count[''])) $out[] = ['p' => '', 'l' => 'The front page', 'n' => 'The front page', 'd' => 0, 'c' => $count['']];
        foreach (folder_tree_rows($dir, array_values(array_filter(array_map('strval', array_keys($count)), function ($d) { return $d !== ''; }))) as $r) {
            if (!empty($count[$r['p']])) $out[] = ['p' => $r['p'], 'l' => link_folder_label($dir, $r['p']), 'n' => $r['n'], 'd' => $r['d'], 'c' => $count[$r['p']]];
        }
        echo json_encode(['folders' => $out]);
        return;
    }
    if (isset($_GET['linkcover'])) {   // the picture a link would use, for the form's preview and the settings line: ?admin&linkcover=1&id=ID&seed=SEED&file=CHOSEN
        header('Content-Type: application/json; charset=UTF-8');
        $rec = preg_match('/^[0-9a-f]{10}$/', $str('id')) ? ($data['links'][$str('id')] ?? null) : null;
        $asked = isset($_GET['file']) ? $str('file') : (is_array($rec) ? (string) ($rec['pictureFile'] ?? '') : '');
        $l = ['picture' => true, 'token' => is_array($rec) ? (string) ($rec['token'] ?? '') : '', 'pictureFile' => $asked,
              'pictureSeed' => preg_match('/^[0-9a-f]{8}$/', $str('seed')) ? $str('seed') : (is_array($rec) ? (string) ($rec['pictureSeed'] ?? '') : '')];
        $draft = link_draft_scope_from_request();   // (the form's unsaved choice of what the link covers)
        if ($draft === null && is_array($rec)) $l['scope'] = $rec['scope'] ?? null;
        $file = link_preview_picture($l, $dir, $draft);
        echo json_encode(['file' => $file, 'how' => $file === null ? 'none' : ($asked !== '' && $file === $asked ? 'chosen' : 'auto'), 'name' => $file === null ? '' : basename($file), 'img' => $file === null ? '' : img_url($file, 'thumb')]);
        return;
    }
    http_response_code(400);
}

/** The photos of one folder (not the ones inside its subfolders), as check boxes with thumbnails: the "Add photos" picker of the link form. */
function render_link_photo_picker($folder, $files, $dir = '', $cover = false, $draft = null) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $folder = trim(str_replace('\\', '/', $folder), '/');
    if ($folder !== '' && !in_array($folder, all_folder_paths($files), true)) return '<p class="muted">That folder isn\'t in the gallery.</p>';
    $list = [];
    foreach ($files as $f) {
        $slash = strrpos($f, '/');
        if (($slash === false ? '' : substr($f, 0, $slash)) === $folder && (!$cover || (link_picture_ok($f, $dir) && link_cover_in_scope(null, $dir, $f, $draft)))) $list[] = $f;   // (a cover: public photos only, and only ones the link covers)
    }
    natcasesort($list);
    if (!$list) return '<p class="muted">' . ($cover ? 'There are no public photos the link covers directly in this folder.' : 'There are no photos directly in this folder.') . '</p>';
    $out = '';
    foreach (array_slice(array_values($list), 0, 400) as $f) {
        $out .= '<label class="sl-pickphoto"><input type="' . ($cover ? 'radio' : 'checkbox') . '"' . ($cover ? ' name="sl-cover"' : '') . ' value="' . $esc($f) . '"><img src="?img=' . rawurlencode($f) . '&amp;size=thumb" alt="" loading="lazy"><span>' . $esc(basename($f)) . '</span></label>';
    }
    return $out . (count($list) > 400 ? '<p class="muted">Showing the first 400 of ' . count($list) . '.</p>' : '');
}

/** What an older version's visitor logs left beside index.php: [paths of the files, their size in bytes, whether the public address's stand-in record is still in the links file]. Only names owned_kind_of_name knows, regular files, no links. */
function visitlog_old_leftovers() {
    $files = []; $bytes = 0; $real = realpath(__DIR__) ?: __DIR__;
    foreach ((array) @scandir($real) as $name) {
        if (!is_string($name) || owned_kind_of_name($name, true) !== 'oldlogs') continue;
        $path = $real . '/' . $name;
        if (is_link($path) || !is_file($path)) continue;
        $files[] = $path; $bytes += (int) @filesize($path);
    }
    $rec = (array) (links_read()['links'] ?? []);
    return [$files, $bytes, isset($rec[PUBLIC_LOG_ID])];
}

/** Takes those leftovers away: the files (each checked again as the gallery's own) and the stand-in record. Returns how many files went, or null when something did not check out. */
function visitlog_old_leftovers_remove() {
    [$files, , $rec] = visitlog_old_leftovers();
    $real = realpath(__DIR__) ?: __DIR__;
    foreach ($files as $path) if (!owned_verify($path, $real, 'oldlogs', true)) return null;   // (nothing is deleted unless every one checks out)
    $n = 0;
    foreach ($files as $path) if (@unlink($path)) $n++;
    if ($rec) links_update(function (&$d) { if (isset($d['links'][PUBLIC_LOG_ID]) && link_is_public_log($d['links'][PUBLIC_LOG_ID])) unset($d['links'][PUBLIC_LOG_ID]); });
    return $n;
}

/** Remembers which version of this script last ran against this install (a small guarded data file beside index.php), so
 *  the health panel can say "upgraded from X on Y". Written only when the version changes; null if it can't be kept. */
function health_version_record($dir) {
    $path = rtrim($dir, '/') . '/.gallery-health.php';
    $data = json_file_read($path, ['version' => '', 'from' => '', 'at' => '', 'first' => '']);
    if (($data['version'] ?? '') === GALLERY_VERSION) return $data;
    $new = ['version' => GALLERY_VERSION, 'from' => (string) ($data['version'] ?? ''), 'at' => gmdate('Y-m-d'),
            'first' => ($data['first'] ?? '') !== '' ? (string) $data['first'] : gmdate('Y-m-d')];
    return @file_put_contents($path, data_file_encode($new), LOCK_EX) !== false ? $new : null;
}

/** The server-side half of the health panel: rows of [group, status (ok|warn|bad|info), label, detail], and nothing is
 *  changed by looking. Folders are sampled (the first 300 that hold media) so a huge gallery stays quick. */
function health_checks($dir, $files) {
    $rows = [];
    $add = function ($group, $status, $label, $detail = '', $link = null, $html = '') use (&$rows) { $rows[] = [$group, $status, $label, $detail, $link, $html]; };

    // --- this install
    $rec = health_version_record($dir);
    if ($rec === null) $add('This install', 'warn', 'Running ' . GALLERY_VERSION, 'The folder is not writable by the web server, so the version history can\'t be kept.');
    elseif (($rec['from'] ?? '') !== '') $add('This install', 'ok', 'Running ' . GALLERY_VERSION, (version_compare((string) $rec['from'], GALLERY_VERSION, '>') ? 'Went back from ' : 'Upgraded from ') . $rec['from'] . ' on ' . $rec['at'] . ' (first recorded ' . $rec['first'] . ').');
    else $add('This install', 'ok', 'Running ' . GALLERY_VERSION, 'First recorded ' . ($rec['first'] ?? $rec['at'] ?? '') . '; upgrades are noted here.');
    $add('This install', 'ok', 'New versions', '', null, update_status_html());
    $add('This install', PHP_VERSION_ID >= 80300 ? 'ok' : 'bad', 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80300 ? '' : 'This gallery needs PHP 8.3 or newer.');
    $hasGd = extension_loaded('gd'); $hasImagick = class_exists('Imagick');
    $add('This install', ($hasGd || $hasImagick) ? 'ok' : 'bad', 'Picture resizing: ' . ($hasImagick && $hasGd ? 'GD and Imagick' : ($hasImagick ? 'Imagick' : ($hasGd ? 'GD' : 'none')) ),
        ($hasGd || $hasImagick) ? '' : 'Neither GD nor Imagick is installed, so thumbnails can\'t be made.');
    $heic = 0; foreach ($files as $f) if (preg_match('/\.hei[cf]$/i', $f)) $heic++;
    if ($heic > 0 && !$hasImagick) $add('This install', 'warn', 'HEIC photos can\'t be shown', $heic . ' HEIC photo' . ($heic === 1 ? '' : 's') . ' found, and HEIC needs the Imagick extension (it isn\'t installed).');
    $add('This install', function_exists('exif_read_data') ? 'ok' : 'warn', 'Photo details (EXIF): ' . (function_exists('exif_read_data') ? 'available' : 'missing'),
        function_exists('exif_read_data') ? '' : 'Without the exif extension, a photo\'s location and date can\'t be read from the file.');

    // --- the visitor log
    [$vlOk, $vlWhy] = visitlog_check_quiet();
    if (!$vlOk) $add('This install', (LOG_PUBLIC_VISITS || ENABLE_SHARE_LINKS) ? 'warn' : 'info', 'Visitor log: needs SQLite', $vlWhy . ' Until then nothing is logged and the Visitors tab stays hidden.');
    elseif (is_file(visitlog_path())) {
        $vlSize = visitlog_size(); $vlOld = visitlog_oldest();
        $vlText = $vlSize >= 1048576 ? number_format($vlSize / 1048576, $vlSize >= 10485760 ? 0 : 1) . ' MB' : max(1, (int) ceil($vlSize / 1024)) . ' KB';
        if (LOG_WARN_MB > 0 && $vlSize > LOG_WARN_MB * 1048576)
            $add('This install', 'warn', 'The visitor records take ' . $vlText, 'More than the ' . number_format(LOG_WARN_MB) . ' MB you asked to be warned at. The oldest record is from ' . ($vlOld > 0 ? gmdate('j M Y', $vlOld) : 'an unknown date') . '. Trim the log in the Visitors tab, or set how long records are kept in Settings.', ['visits', 'vt-root', '→ Visitors']);
        else $add('This install', 'ok', 'Visitor log: ready', 'SQLite works here. ' . visitlog_visitor_count() . ' visitor' . (visitlog_visitor_count() === 1 ? '' : 's') . ' logged, taking ' . $vlText . '.' . ($vlOld > 0 ? ' The oldest record is from ' . gmdate('j M Y', $vlOld) . '.' : ''));
    }
    else $add('This install', 'ok', 'Visitor log: ready', 'SQLite works here. Nothing has been logged yet.');

    // --- settings and passwords
    $state = gallery_config_load();
    $cfgPath = gallery_config_path();
    if ($state['status'] === 'absent') $add('Settings and passwords', 'info', 'No settings file yet', 'Everything runs on the defaults; .gallery-config.php appears when you change something here.');
    elseif ($state['status'] === 'ok') $add('Settings and passwords', is_writable($cfgPath) ? 'ok' : 'warn', 'Settings file readable' . (is_writable($cfgPath) ? ' and writable' : ' but not writable'), is_writable($cfgPath) ? '' : 'Changes in Settings can\'t be saved until the web server may write .gallery-config.php.');
    else $add('Settings and passwords', 'bad', 'Settings file can\'t be read as settings', 'Fix or delete .gallery-config.php; until then the settings in index.php apply.');
    foreach (($GLOBALS['__gallery_config_problems'] ?? []) as $problem) $add('Settings and passwords', 'warn', 'Settings problem', $problem);
    $pwRow = function ($label, $value, $emptyStatus, $emptyDetail) use ($add) {
        if ($value === '') $add('Settings and passwords', $emptyStatus, $label . ': not set', $emptyDetail);
        elseif (password_is_hashed($value)) $add('Settings and passwords', 'ok', $label . ': stored as a hash', '');
        else $add('Settings and passwords', 'opt', $label . ': stored as plain text', 'It is changed to a hash by the next successful sign-in with it; nothing to do.');
    };
    $pwRow('Admin password', ADMIN_PASSWORD, 'warn', 'Without one the admin is not available (open ?admin to set one: it asks for the code in gallery-setup-code.php).');
    $pwRow('Private-view password', PRIVATE_VIEW_PASSWORD, 'info', 'Fine if you have no private items to share.');
    $add('Settings and passwords', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? 'ok' : 'info', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? 'Sign-in key present' : 'Sign-in key not made yet', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? '' : 'It is made the first time someone signs in.');

    // --- lockouts: how many times a connection was paused for too many wrong codes or addresses. A small count of times and kinds is kept whether or not anything is logged; with the Visitors tab there, the addresses are in it.
    $lo = lockout_counts(7);
    if ($lo['total'] > 0) {
        $loParts = []; foreach (lockout_kind_names() as $k => $loName) if ($lo[$k] > 0) $loParts[] = $loName . ' ' . $lo[$k];
        $loTab = visits_tab_wanted();
        $add('Settings and passwords', 'opt', number_format($lo['total']) . ' lockout' . ($lo['total'] === 1 ? '' : 's') . ' in the last 7 days',
            'A connection was paused for a minute after too many wrong codes or addresses (' . implode(', ', $loParts) . ').' . ($loTab ? '' : ' Who it was is not kept: the Visitors tab shows it once share links or the public log are on.'),
            null, $loTab ? '<a class="health-more" href="#visits" data-vt-go="lockouts">&rarr; Visitors</a>' : '');
    }

    // --- where the gallery's folders live
    $named = is_array(EXTRA_FOLDERS) ? EXTRA_FOLDERS : [];
    $roots = is_array(EXTRA_ROOTS) ? EXTRA_ROOTS : [];
    $extraBad = [];
    foreach ($named as $name => $path) if (!is_string($path) || !is_dir($path) || !is_readable($path)) $extraBad[] = (is_string($name) ? $name . ' = ' : '') . (is_string($path) ? $path : '?');
    foreach ($roots as $path) if (!is_string($path) || !is_dir($path) || !is_readable($path)) $extraBad[] = is_string($path) ? $path : '?';
    if ($named || $roots) {
        $total = count($named) + count($roots);
        $add('Folders and files', $extraBad ? 'bad' : 'ok', $extraBad ? count($extraBad) . ' of ' . $total . ' folders from elsewhere are missing or unreadable' : 'All ' . $total . ' folders from elsewhere on the server exist and can be read',
            $extraBad ? 'Missing: ' . implode(', ', array_slice($extraBad, 0, 5)) . '. Their photos are not shown until the path exists again.' : '');
    }
    $folders = [rtrim($dir, '/') => true];
    foreach ($files as $f) { $folders[containing_folder_real_path($dir, $f)] = true; if (count($folders) >= 300) break; }
    foreach (gallery_mounts() as $mountPath) if (is_string($mountPath) && is_dir($mountPath) && !is_link($mountPath)) $folders[rtrim($mountPath, '/')] = true;   // (a place from elsewhere that holds only folders is never reached by the line above, and its own title and settings are saved in it)
    $unwritable = []; $badData = []; $withData = 0; $entries = 0;
    foreach (array_keys($folders) as $folder) {
        if (!is_writable($folder)) $unwritable[] = $folder;
        $itemsFile = items_path($folder);
        if (!is_file($itemsFile)) continue;
        $withData++;
        [$state, $decoded] = json_file_load($itemsFile, []);
        if ($state === 'broken') { $badData[] = $folder; continue; }
        $entries += count($decoded['items'] ?? []);
    }
    $sampled = count($folders) >= 300 ? ' (the first 300 folders with media)' : '';
    $rel = function ($p) use ($dir) { $d = rtrim($dir, '/'); return $p === $d ? '(top folder)' : (strncmp($p, $d . '/', strlen($d) + 1) === 0 ? substr($p, strlen($d) + 1) : $p); };
    $add('Folders and files', $unwritable ? 'warn' : 'ok', $unwritable ? count($unwritable) . ' of ' . count($folders) . ' folders are not writable by the web server' . $sampled : 'All ' . count($folders) . ' folders are writable' . $sampled,
        $unwritable ? 'Captions, locations, privacy flags and cached thumbnails can\'t be saved in: ' . implode(', ', array_map($rel, array_slice($unwritable, 0, 5))) . (count($unwritable) > 5 ? ' and ' . (count($unwritable) - 5) . ' more' : '') . '. Photos still show.' : '');
    $add('Folders and files', $badData ? 'bad' : 'ok', $badData ? count($badData) . ' folder data file' . (count($badData) === 1 ? '' : 's') . ' can\'t be read' : 'Folder data files all read fine (' . $withData . ' folder' . ($withData === 1 ? '' : 's') . ', ' . $entries . ' photo' . ($entries === 1 ? '' : 's') . ' with saved details)',
        $badData ? 'Everything in them is hidden from visitors until the file is restored from a backup or deleted, and nothing is saved over them meanwhile: ' . implode(', ', array_map($rel, array_slice($badData, 0, 5))) : 'Wiping a folder wipes its captions, locations and private flags.');
    $videos = 0; foreach ($files as $f) if (is_video_file($f)) $videos++;
    $add('Folders and files', 'info', number_format(count($files) - $videos) . ' photo' . (count($files) - $videos === 1 ? '' : 's') . ($videos ? ' and ' . number_format($videos) . ' video' . ($videos === 1 ? '' : 's') : ''), 'in ' . number_format(count($folders)) . ' folder' . (count($folders) === 1 ? '' : 's') . $sampled . '.');

    if (!empty($GLOBALS['__scan_truncated'])) $add('Folders and files', 'warn', 'The folder scan stopped early', 'This gallery looks at no more than ' . number_format(SCAN_MAX_FOLDERS) . ' folders and ' . number_format(SCAN_MAX_FILES) . ' photos on one visit, and this folder has more, so the rest are left out. Move the gallery into a smaller folder, or the extra folders out of it.');

    // --- signed-in browsers: what looks wrong, and how big the histories have grown
    foreach (browser_rows() as $br) {
        foreach ($br['flags'] as $flagName => [$flagDetail, $flagAt, $flagLoud]) {
            $add('Signed-in browsers', $flagLoud, signin_flag_info($flagName)[0] . ': ' . ($br['kind'] === 'owner' ? 'you' : 'private view') . ' on ' . ($br['lab'] !== '' ? $br['lab'] : 'a device'),
                $flagDetail . ' (' . date('M j, g:i a', $flagAt) . '). Looks fine clears it, Sign out ends that browser.', ['site', 'browsers-row', '→ Browsers signed in']);
        }
    }
    $sgFiles = (array) @glob(__DIR__ . '/.gallery-signin-*.php'); $sgTotal = 0; $sgBig = 0; $sgBigId = '';
    foreach ($sgFiles as $sgF) { $sgSz = (int) @filesize($sgF); $sgTotal += $sgSz; if ($sgSz > $sgBig && preg_match('/-([0-9a-f]{12})\.php$/', $sgF, $sgM)) { $sgBig = $sgSz; $sgBigId = $sgM[1]; } }
    if ($sgFiles) {
        $sgSizes = (array) (signin_log_read()['sizes'] ?? []); krsort($sgSizes);
        $sgAgo = function ($days) use ($sgSizes) { $want = date('Y-m-d', time() - $days * 86400); foreach ($sgSizes as $day => $v) if ($day <= $want) return signin_size_text((int) ($v[0] ?? 0)); return 'not noted yet'; };
        $sgWho = ''; foreach (browser_rows() as $sgR) if ($sgR['id'] === $sgBigId) $sgWho = ' (' . ($sgR['kind'] === 'owner' ? 'you' : 'private view') . ' on ' . $sgR['lab'] . ')';
        $add('Signed-in browsers', 'info', 'Address history: ' . signin_size_text($sgTotal) . ' in ' . count($sgFiles) . ' file' . (count($sgFiles) === 1 ? '' : 's'),
            'The largest is ' . signin_size_text($sgBig) . $sgWho . '. A week ago the total was ' . $sgAgo(7) . ', a month ago ' . $sgAgo(30) . '. It is kept ' . BROWSER_HISTORY_DAYS . ' days and nothing else caps it; a history that grows fast is worth a look.', ['site', 'browsers-row', '→ Browsers signed in']);
    }

    // --- the country file
    if (COUNTRY_LOOKUP) {
        $gs = geo_state(); $gHave = is_file(geo_db_path());
        $add('Signed-in browsers', 'info', $gHave ? 'Country file: the ' . ($gs['ym'] !== '' ? date('F Y', (int) strtotime($gs['ym'] . '-01')) : 'current') . ' edition, ' . signin_size_text((int) @filesize(geo_db_path())) : 'Country file: not fetched yet',
            ($gHave ? 'IP geolocation by DB-IP (CC BY 4.0); fetched ' . date('M j', (int) ($gs['at'] ?: @filemtime(geo_db_path()))) . ', and it looks for a newer one every 7 days.' : 'It is fetched at the next visit, or press Refresh now in Settings.') . ($gs['error'] !== '' ? ' The last try (' . date('M j, g:i a', (int) $gs['triedAt']) . ') did not work: ' . $gs['error'] : ''), ['site', 'geo-row', '→ Country lookup']);
    }

    // --- videos
    if (ENABLE_VIDEO) {
        $ff = ffmpeg_binary();
        $add('Videos', $ff !== '' ? 'ok' : 'opt', 'ffmpeg ' . ($ff !== '' ? 'found' : 'not found'), $ff !== '' ? $ff . ' — real thumbnails, hover clips and scrubbing are available.' : 'Videos show the browser\'s own first frame; no hover clips or scrubbing.');
    }
    // --- media: what Prepare media looks after. Loud (amber) for what needs doing something about; quiet rows (shown under "Show all
    // checks") for what the Prepare button does by itself, or is only for information. Each lists its files, linked to their editors.
    if (ENABLE_THUMBNAIL_CACHE && $files) {
        $mr = media_readiness($dir, $files); $ma = media_attention($dir, $files);
        $go = ['site', 'media-panel', '→ Prepare media'];
        $n = function ($x, $one, $many = null) { return number_format($x) . ' ' . ($x === 1 ? $one : ($many ?? $one . 's')); };
        $rowFor = [
            'unreadable' => ['warn', function ($c) use ($n) { return $n($c, 'file') . ' couldn\'t be read'; }, 'No capture date or location could be read from these (a damaged file, or a kind the gallery can\'t read). They still show.'],
            'legacy' => ['opt', function ($c) use ($n) { return $n($c, 'old "nothing found" mark'); }, 'An earlier version looked at these and found no location; they are left alone. Tidy up and Read everything again deal with them.'],
            'videoFailed' => ['warn', function ($c) use ($n) { return $n($c, 'video') . ' ffmpeg couldn\'t make pictures for'; }, 'Not tried again for six hours.'],
            'unread' => ['info', function ($c) use ($n) { return $n($c, 'file') . ' not read yet'; }, 'Prepare media reads them (it happens by itself the first time a photo is shown).'],
            'videoMissing' => ['info', function ($c) use ($n) { return $n($c, 'video') . ' without pictures yet'; }, 'Prepare media makes them.'],
            'undated' => ['info', function ($c) use ($n) { return $n($c, 'file') . ' with no capture date'; }, 'They are ordered by when the file was changed. Nothing to fix: screenshots, downloads and some videos carry no date.'],
            'differs' => ['info', function ($c) use ($n) { return $n($c, 'saved location') . ' that differ from their file'; }, 'Yours, kept as they are.'],
        ];
        foreach ($ma['groups'] as $grp) {
            [$status, $labelFn, $detail] = $rowFor[$grp['key']];
            $add('Media', $status, $labelFn($grp['count']), $detail, $status === 'warn' ? $go : null, render_media_group_html($grp));
        }
        if ($mr['left'] > 0) $add('Media', 'info', $n($mr['left'], 'file') . ' not prepared yet', 'Thumbnails, what the files say about themselves and video pictures are made the first time a photo is shown; Prepare media does the rest in one go.', $go);
        elseif (!$ma['attention']) $add('Media', 'ok', 'All prepared', number_format($mr['total']) . ' file' . ($mr['total'] === 1 ? '' : 's') . ': thumbnails made, video pictures made and read from the files.', $go);
        if ($mr['videos'] > 0 && !$mr['videoWanted']) $add('Videos', 'info', 'Video pictures are switched off', 'Video thumbnails and video previews are off in Settings, so videos show the browser\'s own first frame.');
    }

    [$olFiles, $olBytes, $olRec] = visitlog_old_leftovers();
    if ($olFiles || $olRec) {
        $olText = $olBytes >= 1048576 ? number_format($olBytes / 1048576, 1) . ' MB' : max(1, (int) ceil($olBytes / 1024)) . ' KB';
        $add('Left behind', 'info', 'Older visitor logs are still on disk', ($olFiles ? count($olFiles) . ' file' . (count($olFiles) === 1 ? '' : 's') . ' (' . $olText . ')' : 'The public address\'s stand-in record in the links file') . ' from before 2.6.5. Nothing reads them now: the log lives in the new SQLite file. Remove them once you have seen the new log work.', null,
            '<div class="health-act"><button type="button" class="secondary" data-oldlogs-remove>Remove them</button></div>');
    }
    // --- what another gallery, a copy or a tidy-up left that nothing has acted on. Each can be set aside (it then sits among the quiet rows, and comes
    // back by itself if there is more of it), except a copied folder, which stays until it is dealt with.
    $aside = is_array(HEALTH_SET_ASIDE) ? HEALTH_SET_ASIDE : [];
    $leftBehind = function ($key, $amount, $status, $label, $detail, $link) use (&$add, $aside) {
        $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $isAside = isset($aside[$key]) && (int) $aside[$key] >= $amount;
        $btn = $isAside ? '<div class="health-act"><button type="button" class="secondary" data-aside-back="' . $esc($key) . '">Bring back</button></div>'
                        : '<div class="health-act"><button type="button" class="secondary" data-aside="' . $esc($key) . '">Set aside</button></div>';
        $add('Left behind', $isAside ? 'aside' : $status, $label . ($isAside ? ' (set aside)' : ''), $detail, $link, $btn);
    };
    if (AUTO_GROUP_BY_FOLDER) {
        $ev = moved_in_earlier_views($dir, $files);
        if ($ev['views'] > 0) $leftBehind('earlier-views', $ev['views'], 'warn', number_format($ev['views']) . ' earlier view' . ($ev['views'] === 1 ? ' is' : 's are') . ' waiting to be added',
            'Counted by another gallery, under the old addresses of ' . $ev['folders'] . ' folder' . ($ev['folders'] === 1 ? '' : 's') . '.' . (TRACK_VIEWS ? '' : ' Turn on Count views first.'), ['site', 'setting-TRACK_VIEWS', '→ Count views']);
        $lo = moved_in_leftovers($dir, $files);
        if ($lo['count'] > 0) $leftBehind('leftover-thumbs', $lo['count'], 'opt', number_format($lo['count']) . ' leftover thumbnail' . ($lo['count'] === 1 ? '' : 's') . ' (' . ($lo['bytes'] >= 1048576 ? round($lo['bytes'] / 1048576, 1) . ' MB' : max(1, (int) round($lo['bytes'] / 1024)) . ' KB') . ') from another gallery',
            'Pictures an older gallery made in folders elsewhere on the server. They only take up disk. Tidy leftover thumbnails removes them once no other gallery shows those folders.', ['site', 'media-panel', '→ Prepare media']);
        if (EXTRA_ROOTS && ($loose = count_loose_media_in_roots()) > 0) $leftBehind('loose-photos', $loose, 'warn', number_format($loose) . ' photo' . ($loose === 1 ? '' : 's') . ' lying directly in a place added without a name',
            'Only the folders inside such a place are shown, so these photos are not. Move them into a folder to show them, or give the place a name.', ['site', 'setting-EXTRA_FOLDERS', '→ Folders from elsewhere']);
        $copied = [];
        foreach (folder_id_map_folders($dir, $files) as $fid => $paths) {
            if (count($paths) < 2) continue;
            $unl = false; foreach ($paths as $pp) if (folder_is_unlisted($dir, $pp)) { $unl = true; break; }
            $copied[] = [$paths, $unl];
        }
        if ($copied) {
            $sample = implode('; ', array_map(function ($c) { return implode(' = ', array_slice($c[0], 0, 3)); }, array_slice($copied, 0, 3)));
            $anyUnl = (bool) array_filter($copied, function ($c) { return $c[1]; });
            $add('Left behind', 'warn', number_format(count($copied)) . ' hidden id' . (count($copied) === 1 ? ' is' : 's are') . ' carried by more than one folder (a copy)',
                $sample . '. A link to such a folder, ' . ($anyUnl ? 'and the photo addresses of a Not listed one, ' : '') . 'open nothing until it is told apart: remove the copy, or delete the .gallery-items.php inside the copy so it gets an id of its own (its captions and settings go with that file).');
        }
        $bk = media_tidy_backups($dir, $files);
        $bkN = is_array($bk) ? (isset($bk['count']) ? (int) $bk['count'] : count($bk)) : 0;
        if ($bkN > 0) $add('Left behind', 'info', number_format($bkN) . ' backup' . ($bkN === 1 ? '' : 's') . ' from Tidy up saved locations', 'Copies of folder data from before a tidy-up. They stay until you delete them in Prepare media, once you are sure.', ['site', 'media-panel', '→ Prepare media']);
    }
    return $rows;
}

