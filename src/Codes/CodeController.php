<?php

declare(strict_types=1);

namespace Emc\Codes;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\View;

/** Feld „Code“ in der Kopfzeile (GET /code?q=…) und Kurzlink /c/<Code> (z. B. aus dem QR-Code an der Anlage). */
final class CodeController
{
    public function __construct(private View $view, private CodeAufloesung $aufloesung)
    {
    }

    /** Mit fuer=defekt (Seite „Defekt melden“) führen Ziel und Links zum Formular „Defekt melden“ des Objekts. */
    public function suche(Request $r): Response
    {
        return $this->zeige($r->text('q'), $r->text('fuer') === 'defekt' ? '/defekt' : '');
    }

    /** @param array<string,string> $p */
    public function kurzlink(Request $r, array $p): Response
    {
        return $this->zeige((string) ($p['code'] ?? ''));
    }

    private function zeige(string $eingabe, string $zusatz = ''): Response
    {
        $ergebnis = $this->aufloesung->loese($eingabe, $zusatz);
        if ($ergebnis->art === CodeErgebnis::LEER) {
            return Response::redirect(url($zusatz === '/defekt' ? '/defekt' : '/'));
        }
        if ($ergebnis->art === CodeErgebnis::DIREKT && $ergebnis->ziel !== null) {
            // Das Ziel baut CodeAufloesung selbst aus IDs (/fahrzeuge/<id>…), nie aus der Eingabe.
            return Response::redirect(url($ergebnis->ziel));
        }
        $q = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $eingabe));
        $html = $this->view->render('codes/ergebnis', [
            'titel' => 'Code',
            'q' => mb_substr($q, 0, 100),
            'ergebnis' => $ergebnis,
            'adresse' => $ergebnis->art === CodeErgebnis::AUSWAHL ? Code::istAdresse($q) : null,
            'zusatz' => $zusatz,
            'fuerDefekt' => $zusatz === '/defekt',
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
