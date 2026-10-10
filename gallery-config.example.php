<?php if (!defined('GALLERY_CONFIG_LOADING')) { http_response_code(404); exit; }
/**
 * Example settings file for the Drop-in Photo Gallery.
 *
 * To use it: copy this file next to index.php and name the copy
 *   .gallery-config.php
 * (with the leading dot), then un-comment and change the lines you want.
 * Anything you leave out keeps its default from index.php. The file survives
 * replacing index.php with a newer version, so your settings do too.
 *
 * Only the names below are settings. A name that isn't (a typo), a value of
 * the wrong kind, or a file that can't be read is ignored and reported in the
 * gallery's setup notices; it never breaks the site. This file holds your
 * passwords in plain text, so don't put it in git or leave it where it can be
 * downloaded — the first line makes a direct request for it answer 404.
 */
return [
    // --- What gets shown ---------------------------------------------------
    // 'AUTO_GROUP_BY_FOLDER'   => true,        // default false: show subfolders, each as a group (see README: Folders)
    // 'FOLDER_DISPLAY_MODE'    => 'overview',  // 'overview' (one cover card per folder) or 'inline'
    // 'ENABLE_VIDEO'           => true,        // default false: show .mov / .mp4 files
    // 'AUTO_GROUP_BY_FILENAME' => true,        // default false: group photos named alpha_01.jpg, alpha_02.jpg
    // 'PHOTO_SORT_ORDER'       => 'filename',  // 'filename' or 'mtime'

    // --- Thumbnails and counts ---------------------------------------------
    // 'ENABLE_THUMBNAIL_CACHE'    => true,     // default true: small thumbnails in .gallery-cache (false = never write)
    // 'TRACK_VIEWS'               => true,     // default false: count views (writes a stats file)
    // 'SHOW_VIEW_COUNTS_PUBLICLY' => true,     // default false: show the counts to visitors

    // --- Locations and the map ---------------------------------------------
    // 'ENABLE_PHOTO_LOCATION'    => true,      // default false: allow photo locations to be shown (per-folder switch in the admin)
    // 'ENABLE_MAP'               => true,      // default false: the trip map (loads Leaflet and OpenStreetMap tiles)
    // 'MAP_CLUSTER_RADIUS_MILES' => 50,        // how close photos must be to share one pin

    // --- Passwords ---------------------------------------------------------
    // 'ADMIN_PASSWORD'        => 'a-long-random-string',   // empty = the admin does not exist
    // 'PRIVATE_VIEW_PASSWORD' => 'something-to-share',     // empty = no private view

    // --- Server setup (file only: not editable in the admin) ----------------
    // 'EXTRA_FOLDERS'                => ['OldTrip2020' => '/mnt/archive/trip-2020'],
    // 'NGINX_ACCEL_REDIRECT_PREFIX'  => '/kb',   // only once the matching nginx config is in place (see README)
];
