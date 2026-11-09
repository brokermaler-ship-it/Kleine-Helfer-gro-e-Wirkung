/* SCORM 1.2 – Adapter für Moodle: Status, Lernzeit und Quiz-Punktzahl.
   Ohne LMS (lokal geöffnet) bleibt das Skript vollständig nutzbar. */
(function () {
  var API = null, ok = false, start = Date.now(), read = false;

  /* Cross-Origin-Frames werfen beim Zugriff — still abfangen, sonst
     bricht der Adapter ab und es gibt keine Rückmeldung mehr. */
  function scan(win) {
    var n = 0;
    while (win && n++ < 12) {
      try {
        if (win.API) return win.API;
        if (win === win.parent) break;
        win = win.parent;
      } catch (e) { break; }
    }
    return null;
  }
  try { API = scan(window); } catch (e) { API = null; }
  if (!API) { try { if (window.opener) API = scan(window.opener); } catch (e) { API = null; } }
  function set(k, v) { try { API.LMSSetValue(k, String(v)); } catch (e) {} }
  function commit() { try { API.LMSCommit(''); } catch (e) {} }

  if (API) { try { ok = String(API.LMSInitialize('')) !== 'false'; } catch (e) { ok = false; } }
  if (!ok) return;

  /* Moodle-Kennung für das Tutor-Gespräch; tutor.js macht daraus ein Pseudonym. */
  try { window.LS_STUDENT_ID = String(API.LMSGetValue('cmi.core.student_id') || ''); } catch (e) {}

  var st = ''; try { st = API.LMSGetValue('cmi.core.lesson_status'); } catch (e) {}
  if (!st || st === 'not attempted' || st === '') set('cmi.core.lesson_status', 'incomplete');
  set('cmi.core.score.min', 0);
  set('cmi.core.score.max', 100);
  commit();

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function time(ms) {
    var s = Math.floor(ms / 1000);
    return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
  }

  /* Quiz-Ergebnis: Bestehensgrenze 70 % über alle Lernerfolgskontrollen. */
  window.addEventListener('ls-quiz-score', function (e) {
    var d = e.detail;
    if (!d.total) return;
    var pct = Math.round(d.right / d.total * 100);
    set('cmi.core.score.raw', pct);
    if (d.quizzesDone >= d.quizzes) set('cmi.core.lesson_status', pct >= 70 ? 'passed' : 'failed');
    commit();
  });

  /* Seitenende erreicht oder 3 Minuten gelesen: mindestens "completed". */
  function readDone() {
    if (read) return;
    read = true;
    var s = API.LMSGetValue('cmi.core.lesson_status');
    if (s !== 'passed' && s !== 'failed') { set('cmi.core.lesson_status', 'completed'); commit(); }
  }
  window.addEventListener('scroll', function () {
    var h = document.documentElement;
    if (h.scrollTop + window.innerHeight >= h.scrollHeight - 200) readDone();
  }, { passive: true });
  setTimeout(readDone, 3 * 60 * 1000);

  function finish() {
    set('cmi.core.session_time', time(Date.now() - start));
    commit();
    try { API.LMSFinish(''); } catch (e) {}
  }
  window.addEventListener('pagehide', finish);
  window.addEventListener('beforeunload', finish);
})();
