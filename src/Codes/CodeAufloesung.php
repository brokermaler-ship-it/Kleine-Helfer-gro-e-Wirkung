<?php

declare(strict_types=1);

namespace Emc\Codes;

use Emc\Anlagen\AnlageRepository;
use Emc\Fahrzeuge\FahrzeugFilter;
use Emc\Fahrzeuge\FahrzeugRepository;

/** Löst eine Eingabe im Feld „Code“ auf: F<n>/A<n> direkt, reine Zahl als Digitaladresse, sonst Textsuche. */
final class CodeAufloesung
{
    public const NICHT_GEFUNDEN = 'Kein Fahrzeug und keine Anlage mit diesem Code.';
    public const NICHTS = 'Nichts gefunden.';

    public function __construct(private FahrzeugRepository $fahrzeuge, private AnlageRepository $anlagen)
    {
    }

    /** @param string $zusatz wird an jedes Ziel gehängt (z. B. „/defekt“) */
    public function loese(string $eingabe, string $zusatz = ''): CodeErgebnis
    {
        $roh = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $eingabe));
        $roh = mb_substr($roh, 0, 100);
        if (Code::normalisiere($roh) === '') {
            return new CodeErgebnis(CodeErgebnis::LEER);
        }

        $code = Code::zerlege($roh);
        if ($code !== null) {
            $objekt = $code['typ'] === 'fahrzeug'
                ? $this->fahrzeuge->findeNachCodeNummer($code['nummer'])
                : $this->anlagen->findeNachNummer($code['nummer']);
            if ($objekt !== null) {
                $basis = $code['typ'] === 'fahrzeug' ? '/fahrzeuge/' : '/anlagen/';
                return new CodeErgebnis(CodeErgebnis::DIREKT, $basis . (int) $objekt['id'] . $zusatz);
            }
            return $this->suche($roh, self::NICHT_GEFUNDEN);
        }

        $adresse = Code::istAdresse($roh);
        if ($adresse !== null) {
            $treffer = $this->fahrzeuge->nachAdresse($adresse);
            if (count($treffer) === 1) {
                return new CodeErgebnis(CodeErgebnis::DIREKT, '/fahrzeuge/' . (int) $treffer[0]['id'] . $zusatz);
            }
            if ($treffer !== []) {
                return new CodeErgebnis(CodeErgebnis::AUSWAHL, null, $treffer);
            }
            return $this->suche($roh, 'Kein Fahrzeug mit der Digitaladresse ' . $adresse . '.');
        }

        return $this->suche($roh, null);
    }

    private function suche(string $text, ?string $meldung): CodeErgebnis
    {
        $fahrzeuge = $this->fahrzeuge->suche(new FahrzeugFilter(text: $text));
        $anlagen = $this->anlagen->alle(null, $text);
        if ($meldung === null && $fahrzeuge === [] && $anlagen === []) {
            $meldung = self::NICHTS;
        }
        return new CodeErgebnis(CodeErgebnis::SUCHE, null, $fahrzeuge, $anlagen, $meldung);
    }
}
