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

    // Reparatur: weitere Ersatzteilzeile aus der Vorlage (ohne JavaScript bleibt die eine leere Zeile)
    document.querySelectorAll('[data-teil-hinzu]').forEach(function (knopf) {
        var liste = knopf.parentNode.querySelector('[data-teile]');
        var vorlage = document.getElementById('teil-vorlage');
        if (!liste || !vorlage || !('content' in vorlage)) { return; }
        knopf.hidden = false;
        knopf.addEventListener('click', function () {
            var n = String(parseInt(liste.getAttribute('data-naechster') || '0', 10));
            liste.setAttribute('data-naechster', String(Number(n) + 1));
            var zeile = vorlage.content.cloneNode(true);
            zeile.querySelectorAll('[name], [id], [for]').forEach(function (el) {
                ['name', 'id', 'for'].forEach(function (attr) {
                    var wert = el.getAttribute(attr);
                    if (wert) { el.setAttribute(attr, wert.split('__N__').join(n)); }
                });
            });
            liste.appendChild(zeile);
            var erstes = liste.lastElementChild && liste.lastElementChild.querySelector('input');
            if (erstes) { erstes.focus(); }
        });
    });

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

// Fotos vor dem Hochladen verkleinern (längste Seite 2000 px, JPEG 85 %) und die Größe prüfen.
// Das Neukodieren entfernt EXIF/GPS und wandelt HEIC um, wo der Browser es lesen kann. Geht etwas schief,
// bleiben die Originale; ohne JavaScript prüft nur der Server.
(function () {
    'use strict';
    var MAX_SEITE = 2000;
    var QUALITAET = 0.85;

    function mb(bytes) {
        return Math.max(1, Math.floor(bytes / 1048576));
    }

    function verkleinere(datei) {
        if (!/^image\//.test(datei.type) && !/\.(heic|heif)$/i.test(datei.name)) { return Promise.resolve(datei); }
        if (typeof createImageBitmap !== 'function' || typeof File !== 'function') { return Promise.resolve(datei); }
        return createImageBitmap(datei, { imageOrientation: 'from-image' }).then(function (bild) {
            var faktor = Math.min(1, MAX_SEITE / Math.max(bild.width, bild.height));
            var leinwand = document.createElement('canvas');
            leinwand.width = Math.max(1, Math.round(bild.width * faktor));
            leinwand.height = Math.max(1, Math.round(bild.height * faktor));
            var ctx = leinwand.getContext('2d');
            if (!ctx) { return datei; }
            ctx.fillStyle = '#fff'; // durchsichtige PNG-Flächen werden weiß statt schwarz
            ctx.fillRect(0, 0, leinwand.width, leinwand.height);
            ctx.drawImage(bild, 0, 0, leinwand.width, leinwand.height);
            if (bild.close) { bild.close(); }
            return new Promise(function (fertig) {
                leinwand.toBlob(function (blob) {
                    if (!blob) { fertig(datei); return; }
                    var name = datei.name.replace(/\.[^.\/\\]*$/, '') + '.jpg';
                    fertig(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', QUALITAET);
            });
        }).catch(function () { return datei; });
    }

    function knoepfe(form) {
        return form.querySelectorAll('[data-senden], button[type="submit"]');
    }

    document.querySelectorAll('input[type="file"][data-foto-feld]').forEach(function (feld) {
        var form = feld.form;
        if (!form) { return; }
        var hinweis = feld.parentNode.querySelector('[data-foto-fehler]');
        var laeuft = null; // Promise, solange Fotos vorbereitet werden
        var fehler = '';

        function zeige(text) {
            fehler = text;
            if (hinweis) { hinweis.textContent = text; hinweis.hidden = text === ''; }
            feld.classList.toggle('is-invalid', text !== '');
        }

        function pruefe() {
            var maxDatei = parseInt(feld.getAttribute('data-max-datei') || '0', 10);
            var maxGesamt = parseInt(feld.getAttribute('data-max-gesamt') || '0', 10);
            var summe = 0;
            for (var i = 0; i < feld.files.length; i++) {
                var f = feld.files[i];
                summe += f.size;
                if (maxDatei > 0 && f.size > maxDatei) {
                    zeige(f.name + ' ist zu groß (höchstens ' + mb(maxDatei) + ' MB je Foto). Bitte ein kleineres Foto auswählen.');
                    return;
                }
            }
            if (maxGesamt > 0 && summe > maxGesamt) {
                zeige('Die Fotos sind zusammen zu groß (' + (Math.round(summe / 104857.6) / 10).toString().replace('.', ',')
                    + ' MB, höchstens ' + mb(maxGesamt) + ' MB). Bitte weniger oder kleinere Fotos auswählen – dein Text bleibt erhalten.');
                return;
            }
            zeige('');
        }

        feld.addEventListener('change', function () {
            var original = Array.prototype.slice.call(feld.files);
            if (original.length === 0 || typeof DataTransfer !== 'function') { pruefe(); return; }
            zeige('');
            feld.setAttribute('aria-busy', 'true');
            laeuft = Promise.all(original.map(verkleinere)).then(function (neu) {
                try {
                    var dt = new DataTransfer();
                    neu.forEach(function (f) { dt.items.add(f); });
                    feld.files = dt.files;
                } catch (e) { /* Originale bleiben */ }
            }).catch(function () { /* Originale bleiben */ }).then(function () {
                laeuft = null;
                feld.removeAttribute('aria-busy');
                pruefe();
            });
        });

        form.addEventListener('submit', function (ereignis) {
            if (laeuft) {
                ereignis.preventDefault();
                laeuft.then(function () { if (fehler === '') { form.requestSubmit ? form.requestSubmit() : form.submit(); } });
                return;
            }
            pruefe();
            if (fehler !== '') {
                ereignis.preventDefault();
                if (hinweis) { hinweis.scrollIntoView({ block: 'center' }); }
            }
        });
    });

    // Schutz vor Doppelsenden: nach dem Absenden ist die Schaltfläche gesperrt („Wird gesendet …“).
    document.querySelectorAll('form').forEach(function (form) {
        if (!form.querySelector('[data-senden]')) { return; }
        form.addEventListener('submit', function (ereignis) {
            if (ereignis.defaultPrevented) { return; }
            knoepfe(form).forEach(function (k) {
                if (k.hasAttribute('data-senden')) {
                    k.setAttribute('data-text', k.textContent);
                    k.textContent = 'Wird gesendet …';
                }
                k.disabled = true;
            });
        });
    });
    // Zurück-Taste (Seite aus dem Cache): Schaltflächen wieder freigeben.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-senden][data-text]').forEach(function (k) {
            k.textContent = k.getAttribute('data-text');
            k.removeAttribute('data-text');
            k.disabled = false;
        });
    });
})();
// Magazin: Felder für Literatur (Autor/Verlag, Jahr, ISBN) nur bei der Kategorie mit dem Schlüssel „literatur“ zeigen.
// Ohne JavaScript bleiben sie sichtbar; der Server speichert sie bei anderen Kategorien nicht.
(function () {
    'use strict';
    document.querySelectorAll('select[data-literatur-wahl]').forEach(function (wahl) {
        var form = wahl.form;
        var bereich = form ? form.querySelector('[data-nur-literatur]') : null;
        if (!bereich) { return; }
        var hinweis = bereich.querySelector('[data-nur-literatur-hinweis]');
        function anpassen() {
            var option = wahl.options[wahl.selectedIndex];
            bereich.hidden = !(option && option.getAttribute('data-schluessel') === 'literatur');
            if (hinweis) { hinweis.hidden = true; }
        }
        wahl.addEventListener('change', anpassen);
        anpassen();
    });
    // Kasse: Summe beim Tippen der Verkaufspreise mitrechnen (nur Anzeige, in ganzen Cent; verbindlich rechnet der Server)
    document.querySelectorAll('[data-kasse-korb]').forEach(function (form) {
        var summe = form.querySelector('[data-kasse-summe]');
        var felder = form.querySelectorAll('[data-kasse-preis]');
        if (!summe || !felder.length) { return; }
        function cent(text) {
            var m = /^(\d{1,6})(?:[.,](\d{1,2}))?$/.exec(text.trim());
            if (!m) { return null; }
            return parseInt(m[1], 10) * 100 + parseInt(((m[2] || '') + '00').slice(0, 2), 10);
        }
        function anzeige(c) {
            var euro = String(Math.floor(c / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return euro + ',' + String(c % 100 + 100).slice(1) + ' \u20ac';
        }
        function rechne() {
            var gesamt = 0;
            for (var i = 0; i < felder.length; i++) {
                var c = cent(felder[i].value);
                felder[i].classList.toggle('is-invalid', c === null);
                if (c === null) { summe.textContent = '–'; return; }
                gesamt += c;
            }
            summe.textContent = anzeige(gesamt);
        }
        felder.forEach(function (f) { f.addEventListener('input', rechne); });
        // 0,00 € beim Abschluss nachfragen (der Server verlangt nullpreis_ok=1)
        form.addEventListener('submit', function (e) {
            var knopf = e.submitter;
            if (!knopf || !knopf.hasAttribute('data-kasse-abschluss')) { return; }
            var haken = form.querySelector('[data-nullpreis-ok]');
            if (haken && haken.checked) { return; }
            var null0 = Array.prototype.some.call(felder, function (f) { return cent(f.value) === 0; });
            if (!null0) { return; }
            if (!window.confirm('Ein Stück hat den Verkaufspreis 0,00 €. Trotzdem so verkaufen?')) { e.preventDefault(); return; }
            var feld = document.createElement('input');
            feld.type = 'hidden'; feld.name = 'nullpreis_ok'; feld.value = '1';
            form.appendChild(feld);
        });
    });

    // Formulare mit data-senden nur einmal absenden (Doppeltipp am Handy); Knöpfe erst nach dem Absenden sperren, damit
    // Name und Wert des gedrückten Knopfes mitgehen. Zurück-Taste (bfcache) gibt sie wieder frei.
    document.querySelectorAll('form[data-senden]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) { return; }
            if (form.getAttribute('data-gesendet') === '1') { e.preventDefault(); return; }
            form.setAttribute('data-gesendet', '1');
            window.setTimeout(function () {
                form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
            }, 0);
        });
    });
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) { return; }
        document.querySelectorAll('form[data-senden]').forEach(function (form) {
            form.removeAttribute('data-gesendet');
            form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = false; });
        });
    });
})();
