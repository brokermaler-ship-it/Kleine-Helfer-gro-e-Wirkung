<?php
/** „Defekt melden“ ohne Objekt: erst Fahrzeug oder Anlage wählen (weiter über das Feld „Code“ mit fuer=defekt). */
?>
<div class="emc-meldung mx-auto">
    <h1 class="h3 mb-3">Defekt melden</h1>
    <p>Woran ist etwas kaputt? Gib den Code vom Etikett ein (z. B. <strong>F12</strong> oder <strong>A3</strong>),
        die Digitaladresse oder einen Namen.</p>
    <form method="get" action="<?= e(url('/code')) ?>" role="search" class="mb-3">
        <input type="hidden" name="fuer" value="defekt">
        <label for="defekt-q" class="form-label fw-semibold">Code, Digitaladresse oder Name</label>
        <input type="search" class="form-control form-control-lg mb-2" id="defekt-q" name="q" required
               autocapitalize="characters" autocomplete="off" spellcheck="false" placeholder="z. B. F12">
        <button type="submit" class="btn btn-danger btn-lg w-100">Weiter</button>
    </form>
    <p class="text-muted small">An einer Anlage geht es noch schneller: QR-Code auf dem Etikett scannen und dort „Defekt melden“ wählen.</p>
    <p><a href="<?= e(url('/meine-meldungen')) ?>">Meine Meldungen</a></p>
</div>
