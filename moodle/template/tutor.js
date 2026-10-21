/* Lerngespräch: öffnet den Voice-Tutor in einem neuen Fenster.
   Statt der Moodle-Kennung geht nur ein Pseudonym (M- plus Prüfsumme) an den Tutor.
   Ohne Moodle (lokal geöffnet) wird ein zufälliges Pseudonym im Browser gemerkt. */
(function () {
  var root = document.documentElement;
  var url = root.getAttribute('data-tutor-url') || 'http://localhost:8000';
  var moduleId = root.getAttribute('data-module-id') || '';
  var KEY = (root.getAttribute('data-course') || 'kurs') + '-pseudonym';

  function fnv(text) {
    var h = 0x811c9dc5;
    for (var i = 0; i < text.length; i++) {
      h ^= text.charCodeAt(i);
      h = Math.imul(h, 0x01000193) >>> 0;
    }
    return ('0000000' + h.toString(16)).slice(-8);
  }

  function pseudonym() {
    if (window.LS_STUDENT_ID) return 'M-' + fnv(moduleId + ':' + window.LS_STUDENT_ID);
    var p = null;
    try { p = localStorage.getItem(KEY); } catch (e) {}
    if (!p) {
      p = 'M-' + fnv(String(Math.random()) + Date.now());
      try { localStorage.setItem(KEY, p); } catch (e) {}
    }
    return p;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-tutor]') : null;
    if (!btn) return;
    var q = '?kennung=' + encodeURIComponent(pseudonym()) + '&modul=' + encodeURIComponent(moduleId) +
      '&tutor=' + encodeURIComponent(btn.getAttribute('data-tutor')) + '&direkt=1';
    var win = window.open(url + '/' + q, 'voice-tutor', 'width=1100,height=800');
    var status = document.querySelector('[data-tutor-status]');
    if (status) {
      status.textContent = win
        ? 'Das Gespräch läuft im neuen Fenster. Bitte das Mikrofon dort erlauben.'
        : 'Das Fenster wurde blockiert. Bitte Pop-ups für diese Seite erlauben und erneut klicken.';
    }
  });

  /* Gesamtergebnis im Abschluss anzeigen. */
  window.addEventListener('ls-quiz-score', function (e) {
    var d = e.detail, box = document.querySelector('[data-total]');
    if (!box || !d.total) return;
    var pct = Math.round(d.right / d.total * 100);
    box.textContent = d.quizzesDone < d.quizzes
      ? 'Bisher ' + d.right + ' von ' + d.total + ' Fragen richtig. Es fehlen noch ' + (d.quizzes - d.quizzesDone) + ' Lernerfolgskontrollen.'
      : 'Gesamtergebnis: ' + d.right + ' von ' + d.total + ' richtig (' + pct + ' %). ' + (pct >= 70 ? 'Bestanden.' : 'Noch nicht bestanden, wiederhole die Module mit den meisten Fehlern.');
    box.className = pct >= 70 && d.quizzesDone >= d.quizzes ? 'hsl-ok' : '';
  });
})();
