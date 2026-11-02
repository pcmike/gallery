</script>
</body>
</html>
<?php
}

/**
 * First-run setup. With no admin password there is nothing to sign in to, so ?admin offers a one-time page to choose one. Whoever could
 * open that page first would set the password, so the page asks for a code that only someone who can read the gallery's folder can
 * know: the first request makes the file gallery-setup-code.php beside index.php (a guard line, then the code), and the page asks for the
 * code in it. Open that file with a file manager or over FTP, type the code, choose the password. A wrong code is throttled like a wrong
 * password, and the file is deleted once a password exists. There is no time window to race, so it is not "do it within five minutes".
 */
function admin_setup_code_path() {
    return __DIR__ . '/gallery-setup-code.php';
}

/** A code as typed: capitals and digits only, so "k7qp x2mv" and "K7QP-X2MV" are the same. */
function admin_setup_code_normalize($text) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $text));
}

/** The code the file holds, normalised, or '' when there is no file or no code in it. */
function admin_setup_code_read() {
    $raw = @file_get_contents(admin_setup_code_path());
    if (!is_string($raw) || !preg_match('/^Setup code:\s*([A-Z0-9-]{8,12})\s*$/m', data_file_strip_guard($raw), $m)) return '';
    return admin_setup_code_normalize($m[1]);
}

/** The code, making the file when it isn't there (an existing file is never overwritten: two first visitors share one code). '' when the
 *  folder can't be written to, which also means no password could be saved here, so there is nothing for a code to protect. */
function admin_setup_code_ensure() {
    $code = admin_setup_code_read();
    if ($code !== '' || !is_writable(__DIR__)) return $code;
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // (no 0/O or 1/I to mistake for each other)
    $chars = '';
    for ($i = 0; $i < 8; $i++) $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $fp = @fopen(admin_setup_code_path(), 'x');
    if ($fp) {
        fwrite($fp, data_file_guard_line() . 'Setup code: ' . substr($chars, 0, 4) . '-' . substr($chars, 4) . "\n"
            . "Type this code on the gallery's \"Set up your gallery\" page, then choose your password. This file is deleted when the password is set.\n"
            . "Keep it to yourself: whoever has the code can set the admin password.\n");
        fclose($fp);
    }
    return admin_setup_code_read();
}

/** Sets the first admin password, once, under a lock so two simultaneous
 *  visitors can't both win. Returns ['ok' => true] or ['error' => text,
 *  'manual' => settings-file text to create by hand (when it can't be
 *  written)]. Only the settings file is touched; any settings already in it
 *  are kept. */
function admin_setup_claim($password) {
    $lock = @fopen(__FILE__, 'r');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $path = gallery_config_path();
        $raw = is_file($path) ? @file_get_contents($path) : '';
        if ($raw === false) return ['error' => 'The settings file exists but can\'t be read, so nothing was changed.'];
        [$status, $cfg] = gallery_config_parse((string) $raw);
        if ($status !== 'ok') return ['error' => 'The settings file (.gallery-config.php) exists but isn\'t valid, and saving would overwrite it. Fix or delete that file, then try again.'];
        if (isset($cfg['ADMIN_PASSWORD']) && is_string($cfg['ADMIN_PASSWORD']) && $cfg['ADMIN_PASSWORD'] !== '') {
            return ['error' => 'An admin password has just been set by someone else. If that wasn\'t you, delete .gallery-config.php and set it again.', 'taken' => true];
        }
        $stored = password_for_storage($password);
        $cfg['ADMIN_PASSWORD'] = $stored;
        $content = gallery_config_render($cfg);
        if ($content === null) return ['error' => 'That password has characters that can\'t be stored. Try a different one.'];
        $manual = ['error' => 'The gallery\'s folder isn\'t writable by the web server, so the password couldn\'t be saved. Create a file named .gallery-config.php next to index.php containing exactly the text below, then reload this page.', 'manual' => $content];
        if (!is_writable(__DIR__)) return $manual;
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return $manual; }
        @chmod($tmp, 0600);
        [$roundStatus, $roundTrip] = gallery_config_parse((string) @file_get_contents($tmp));
        if ($roundStatus !== 'ok' || ($roundTrip['ADMIN_PASSWORD'] ?? null) !== $stored) { @unlink($tmp); return ['error' => 'The settings file didn\'t read back correctly, so it was not installed.']; }
        if (!@rename($tmp, $path)) { @unlink($tmp); return $manual; }
        return ['ok' => true, 'stored' => $stored];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function render_setup_page($error = '', $manual = '', $needCode = true) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Set up your gallery</title>
<style>
