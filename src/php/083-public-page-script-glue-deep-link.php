<?php endif; ?>
  // ?photo=<filename> deep link: open straight to that photo on load.
  if (initialPhoto) {
    const idx = files.indexOf(initialPhoto);
    if (idx !== -1) {
      openLightbox(idx);
      if (lbEdit && /[?&]edit=/.test(location.search)) lbEdit.open();   // (the owner's link from the admin: that photo, with its editor)
    }
  }
</script>

</body>
</html>
