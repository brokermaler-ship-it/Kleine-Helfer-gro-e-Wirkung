/* Lernerfolgskontrolle – reines Vanilla-JS, funktioniert im Browser und im SCORM-Paket.
   Markup-Vertrag:
   <div class="ls-quiz">                     ein Quizblock
     <div class="ls-qz" data-answer="b" data-explain="…">   eine Frage
       <label><input type="radio|checkbox" value="a"> …</label>
     <button data-quiz-check>  <button data-quiz-reset>  <div data-quiz-result> */
(function () {
  var GREEN = '#1e7a3c', RED = '#b8262a';
  var EN = document.documentElement.lang === 'en';

  function fields(q) { return Array.prototype.slice.call(q.querySelectorAll('input')); }
  function picked(q) { return fields(q).filter(function (i) { return i.checked; }).map(function (i) { return i.value; }).sort().join(','); }
  function expected(q) { return (q.getAttribute('data-answer') || '').split(',').map(function (s) { return s.trim(); }).sort().join(','); }

  function feedback(q) {
    var box = q.querySelector('[data-qz-feedback]');
    if (!box) {
      box = document.createElement('div');
      box.setAttribute('data-qz-feedback', '');
      box.style.cssText = 'margin-top:10px;font-size:0.92em;padding:10px 12px;border:1px solid';
      q.appendChild(box);
    }
    return box;
  }

  function check(quiz) {
    var qs = Array.prototype.slice.call(quiz.querySelectorAll('.ls-qz')), right = 0;
    qs.forEach(function (q) {
      var ok = picked(q) !== '' && picked(q) === expected(q);
      if (ok) right++;
      var box = feedback(q);
      box.style.borderColor = ok ? GREEN : RED;
      box.style.color = ok ? GREEN : RED;
      box.style.background = ok ? 'rgba(47,109,79,0.07)' : 'rgba(156,59,59,0.06)';
      box.innerHTML = '<strong>' + (ok ? (EN ? 'Correct.' : 'Richtig.') : (EN ? 'Not quite.' : 'Noch nicht richtig.')) + '</strong> ' + (q.getAttribute('data-explain') || '');
      q.style.borderLeft = '3px solid ' + (ok ? GREEN : RED);
    });
    var res = quiz.querySelector('[data-quiz-result]');
    if (res) {
      var pct = Math.round(right / qs.length * 100);
      res.textContent = right + (EN ? ' of ' : ' von ') + qs.length + (EN ? ' correct (' : ' richtig (') + pct + ' %).';
      res.style.color = pct >= 70 ? GREEN : RED;
    }
    quiz.setAttribute('data-score-right', right);
    quiz.setAttribute('data-score-total', qs.length);
    report();
  }

  function reset(quiz) {
    quiz.querySelectorAll('input').forEach(function (i) { i.checked = false; });
    quiz.querySelectorAll('[data-qz-feedback]').forEach(function (b) { b.remove(); });
    quiz.querySelectorAll('.ls-qz').forEach(function (q) { q.style.borderLeft = '3px solid transparent'; });
    var res = quiz.querySelector('[data-quiz-result]');
    if (res) res.textContent = '';
    quiz.removeAttribute('data-score-right');
    quiz.removeAttribute('data-score-total');
    report();
  }

  function report() {
    var right = 0, total = 0, answered = 0, quizzes = document.querySelectorAll('.ls-quiz');
    quizzes.forEach(function (q) {
      total += q.querySelectorAll('.ls-qz').length;
      if (q.hasAttribute('data-score-right')) {
        right += parseInt(q.getAttribute('data-score-right'), 10);
        answered++;
      }
    });
    window.dispatchEvent(new CustomEvent('ls-quiz-score', {
      detail: { right: right, total: total, quizzesDone: answered, quizzes: quizzes.length }
    }));
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-quiz-check],[data-quiz-reset]') : null;
    if (!btn) return;
    var quiz = btn.closest('.ls-quiz');
    if (!quiz) return;
    e.preventDefault();
    if (btn.hasAttribute('data-quiz-check')) check(quiz); else reset(quiz);
  });
})();
