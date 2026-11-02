  (function () {
    var box = document.getElementById('gate');
    // Where the browser can't draw text as dots, showing what is typed would be worse than a manager's pop-up: use a real password box.
    if (!(window.CSS && CSS.supports && (CSS.supports('-webkit-text-security', 'disc') || CSS.supports('text-security', 'disc')))) {
      var pw = document.createElement('input');
      pw.type = 'password'; pw.name = box.name; pw.id = box.id; pw.className = box.className; pw.required = true; pw.autocomplete = 'current-password';
      box.parentNode.replaceChild(pw, box); pw.focus();
    }
  })();
  // The lock drawing is the way back, and really goes back to where the visitor was (same scroll position, same open photo)
  // when they came from a page of this site; otherwise its link takes them to that page.
  document.getElementById('goback').addEventListener('click', function (e) {
    try {
      var ref = document.referrer ? new URL(document.referrer) : null;
      if (ref && ref.origin === location.origin && history.length > 1 && !/[?&](reveal|admin)\b/.test(ref.search)) { e.preventDefault(); history.back(); }
    } catch (x) {}
  });
