</style>
</head>
<body>
<div class="wrap">
  <h1>Set up your gallery</h1>
  <p>Choose the password that will unlock this gallery's admin: captions, folder settings, notes and the settings. You'll be signed in straight away.</p>
  <?php if ($needCode): ?>
  <div class="warn" id="setup-warn">
    <p class="first">First, show that you can reach this server's files.</p>
    <p class="rest">The gallery has made a small text file named <code>gallery-setup-code.php</code> in the same folder as <code>index.php</code>. Open it with your host's file manager or an FTP program and type the code on its second line below. Anyone else who opens this page sees the same box and cannot get past it. The file is deleted when the password is set.</p>
  </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($manual !== ''): ?><textarea readonly rows="8" onfocus="this.select()"><?= htmlspecialchars($manual, ENT_QUOTES, 'UTF-8') ?></textarea><?php endif; ?>
  <form method="post" action="">
    <?php if ($needCode): ?>
    <label for="setup_code">Code from the file (like K7QP-X2MV)</label>
    <input type="text" id="setup_code" name="setup_code" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus required maxlength="20" style="text-transform:uppercase; letter-spacing:.08em">
    <?php endif; ?>
    <label for="setup_password">Admin password (8 or more characters, with letters and numbers)</label>
    <input type="password" id="setup_password" name="setup_password" autocomplete="new-password"<?= $needCode ? '' : ' autofocus' ?> required minlength="8">
    <label for="setup_password2">Type it again</label>
    <input type="password" id="setup_password2" name="setup_password2" autocomplete="new-password" required minlength="8">
    <button type="submit">Set password and sign in</button>
  </form>
</div>
