</style>
</head>
<body>
<div class="wrap">
  <a class="mark" id="goback" href="./<?= htmlspecialchars(login_return_query(), ENT_QUOTES, 'UTF-8') ?>" aria-label="Back to the gallery"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><path d="M12 14.6v2.8"/></svg></a>
  <?php if ($blurb !== ''): ?><p><?= htmlspecialchars($blurb, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post" action="" autocomplete="off">
    <?php // Not a password field (a browser or manager offers to fill, and to save, whatever is typed into one): a text box
          // drawn as dots, named nothing a manager recognises, every common manager told to leave it alone, and read-only
          // until it is focused, which keeps iOS Safari's Autofill from picking it up when the page loads. Return sends it. ?>
    <input type="text" class="gate" id="gate" name="access_code" readonly onfocus="this.removeAttribute('readonly');" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go" autofocus required
           data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
  </form>
</div>
<script>
