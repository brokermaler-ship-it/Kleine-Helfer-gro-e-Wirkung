/* Modulweise Freischaltung – zustandslos: es werden keine DOM-Knoten gecacht.
   Der Zustand (geöffnetes Modul, abgeschlossene Module) liegt in localStorage,
   paint() schreibt ihn immer wieder ins DOM – dadurch übersteht die Logik
   jedes Neu-Rendern. Klicks laufen über einen delegierten document-Listener.
   Freischaltung: Lernerfolgskontrolle des Vormoduls mit mindestens 70 %. */
(function () {
  var KEY = (document.documentElement.getAttribute('data-course') || 'kurs') + '-modulfortschritt', DONE = '#1e7a3c';
  var state = { done: {}, open: 1 };
  try {
    var raw = JSON.parse(localStorage.getItem(KEY));
    if (raw && typeof raw === 'object') {
      state.done = raw.done || {};
      state.open = raw.open || 1;
      /* Altformat {"m1":"done"} übernehmen. */
      Object.keys(raw).forEach(function (k) {
        if (/^m\d+$/.test(k) && raw[k] === 'done') state.done[k] = true;
      });
    }
  } catch (e) {}

  function save() { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} }
  function num(el) { return parseInt(el.getAttribute('data-module'), 10); }
  function unlocked(n) { return n === 1 || state.done['m' + (n - 1)] === true; }

  function paint() {
    var mods = document.querySelectorAll('.ls-module');
    if (!mods.length) return;
    /* Höchstes freigeschaltetes Modul öffnen, falls das gemerkte gesperrt ist. */
    if (!unlocked(state.open)) {
      var last = 1;
      for (var i = 0; i < mods.length; i++) { var k = num(mods[i]); if (unlocked(k)) last = k; }
      state.open = last;
    }
    Array.prototype.forEach.call(mods, function (el) {
      var n = num(el), free = unlocked(n), done = state.done['m' + n] === true;
      var open = free && state.open === n;
      var body = el.querySelector('[data-mod-body]');
      var lab = el.querySelector('[data-mod-state]');
      var arrow = el.querySelector('[data-mod-arrow]');
      var btn = el.querySelector('[data-mod-toggle]');
      if (body) body.style.display = open ? '' : 'none';
      el.style.opacity = free ? '1' : '0.55';
      if (btn) btn.style.cursor = free ? 'pointer' : 'not-allowed';
      if (lab) {
        lab.textContent = done ? '✓ abgeschlossen' : (free ? (open ? 'geöffnet' : 'öffnen') : 'gesperrt');
        lab.style.color = done ? DONE : 'var(--color-neutral-700)';
      }
      if (arrow) arrow.textContent = open ? '–' : '+';
      el.setAttribute('data-open', open ? 'yes' : 'no');
    });
  }

  function readQuizzes() {
    var changed = false;
    Array.prototype.forEach.call(document.querySelectorAll('.ls-module'), function (el) {
      var quiz = el.querySelector('.ls-quiz');
      if (!quiz || !quiz.hasAttribute('data-score-right')) return;
      var right = parseInt(quiz.getAttribute('data-score-right'), 10);
      var total = parseInt(quiz.getAttribute('data-score-total'), 10);
      if (total && right / total >= 0.7 && !state.done['m' + num(el)]) {
        state.done['m' + num(el)] = true;
        changed = true;
      }
    });
    if (changed) save();
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var btn = e.target.closest('[data-mod-toggle]');
    if (btn) {
      var el = btn.closest('.ls-module');
      if (!el) return;
      var n = num(el);
      if (!unlocked(n)) return;
      state.open = state.open === n ? 0 : n;
      save();
      paint();
      return;
    }
    var a = e.target.closest('a[href^="#"]');
    if (a) {
      var t = document.querySelector(a.getAttribute('href'));
      var host = t && t.closest ? t.closest('.ls-module') : null;
      if (host && unlocked(num(host))) { state.open = num(host); save(); paint(); }
    }
  });

  window.addEventListener('ls-quiz-score', function () { readQuizzes(); paint(); });
  window.addEventListener('beforeprint', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.ls-module'), function (el) {
      var b = el.querySelector('[data-mod-body]');
      if (b) b.style.display = '';
      el.style.opacity = '1';
    });
  });

  window.LSModules = { init: paint };
  paint();
  /* Nach jedem Neu-Rendern des Templates den Zustand wieder anwenden. */
  setInterval(paint, 400);
})();
