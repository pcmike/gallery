</script>
</body>
</html>
<?php
}

/** A plain-English refusal page for ?admin or ?reveal over an insecure
 *  connection — HTTP Basic Auth credentials are plaintext-equivalent
 *  without TLS, so this fails closed rather than just documenting the
 *  risk. Explains itself and points toward a free certificate, rather
 *  than a bare error that teaches a visitor nothing about what to do
 *  next. */
function render_https_required_page() {
    http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HTTPS required</title>
<style>
