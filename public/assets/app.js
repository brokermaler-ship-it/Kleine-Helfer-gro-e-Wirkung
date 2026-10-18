// Eigene Skripte
(function () {
    'use strict';

    // Spurweite o. Ä. automatisch absenden
    document.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel.form) { sel.form.submit(); }
        });
    });

    // Nach dem Speichern eines Decoders zurück auf den passenden Tab (#digital)
    if (window.location.hash.length > 1) {
        var tab = document.getElementById('tab-' + window.location.hash.slice(1));
        if (tab && tab.getAttribute('data-bs-toggle') === 'tab') { tab.click(); }
    }

    // Live-Prüfung der Decoder-Adresse (Debounce 300 ms)
    document.querySelectorAll('form[data-adress-pruefung]').forEach(function (form) {
        var feld = form.querySelector('[name="adresse"]');
        var protokoll = form.querySelector('[name="protokoll"]');
        var warnung = form.querySelector('[data-adress-warnung]');
        var art = form.querySelector('[data-adress-art]');
        if (!feld || !protokoll || !warnung || !window.fetch) { return; }
        var timer = null;
        var zaehler = 0;

        function zeige(daten) {
            warnung.textContent = daten && daten.warnung ? daten.warnung : '';
            if (art) { art.textContent = daten && daten.adressart ? 'Adressart: ' + daten.adressart : ''; }
            var fehler = daten && daten.fehler ? daten.fehler : '';
            var meldung = form.querySelector('.invalid-feedback.live');
            if (fehler) {
                if (!meldung) {
                    meldung = document.createElement('div');
                    meldung.className = 'invalid-feedback d-block live';
                    feld.insertAdjacentElement('afterend', meldung);
                }
                meldung.textContent = fehler;
            } else if (meldung) {
                meldung.remove();
            }
        }

        function pruefe() {
            var nummer = ++zaehler;
            var query = new URLSearchParams({
                fahrzeug: form.getAttribute('data-fahrzeug') || '',
                spurweite: form.getAttribute('data-spurweite') || '',
                protokoll: protokoll.value,
                adresse: feld.value.trim()
            });
            fetch(form.getAttribute('data-adress-pruefung') + '?' + query.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (antwort) {
                return antwort.ok ? antwort.json() : null;
            }).then(function (daten) {
                if (nummer === zaehler) { zeige(daten); }
            }).catch(function () {
                if (nummer === zaehler) { zeige(null); }
            });
        }

        function geplant() {
            window.clearTimeout(timer);
            timer = window.setTimeout(pruefe, 300);
        }

        feld.addEventListener('input', geplant);
        protokoll.addEventListener('change', geplant);
        if (feld.value.trim() !== '') { pruefe(); }
    });
}());
