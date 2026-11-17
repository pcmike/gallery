</style>
</head>
<body>
<div class="wrap">
  <a class="mark" id="goback" href="./<?= htmlspecialchars(login_return_query(), ENT_QUOTES, 'UTF-8') ?>" aria-label="Back to the gallery"><?= LINK_ICON_GALLERY ?></a>
  <?php if ($blurb !== ''): ?><p><?= htmlspecialchars($blurb, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><div class="miss"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post" action="" autocomplete="off">
    <?php // Not a password field (a browser or manager offers to fill, and to save, whatever is typed into one): a text box
          // drawn as dots, named nothing a manager recognises, every common manager told to leave it alone, and read-only
          // until it is focused, which keeps iOS Safari's Autofill from picking it up when the page loads. Return sends it.
          // THE OWNER'S DECISION (2026-11-17, audit group 15, H-22): this page is made like the owner's other one (pcmike.net/dp/a): nothing on it
          // says what it is, so there is no label, no heading, no button and no aria- names, and a screen reader is not helped here on purpose.
          // An audit will flag it; it is not to be "fixed". ?>
    <input type="text" class="lens" id="lens" name="lens" readonly onfocus="this.removeAttribute('readonly');" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go" autofocus required
           data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
  </form>
</div>
<script>
