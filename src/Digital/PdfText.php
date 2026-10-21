<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Core\DomainFehler;
use Emc\Digital\Pdf\BegrenzterParser;
use Emc\Digital\Pdf\GrenzeUeberschritten;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\XObject\Form;

/**
 * Liest den Text eines PDFs aus dem Speicher (die Datei wird nie abgelegt), mit festen Grenzen für Größe, Seiten,
 * Objekte, Textmenge, Entpacken und Laufzeit. Jeder Fehler der Bibliothek wird zu einem DomainFehler mit deutscher
 * Meldung. Falls trotzdem ein Fatal Error (Speicher, Zeit) passiert, zeigt ein Notfall-Handler eine Fehlerseite.
 */
final class PdfText
{
    public const MAX_BYTES = 20 * 1048576;
    public const MAX_SEITEN = 150;
    public const MAX_TEXT = 2 * 1048576;
    /** Höchstens so viel entpackt die Bibliothek aus einem einzelnen Datenstrom. */
    public const DECODE_LIMIT = 32 * 1048576;
    /**
     * Höchstens so viel darf alles Entpackte zusammen ergeben (gezählt beim Entpacken in der Bibliothek). Sie entpackt
     * beim Einlesen alle Ströme auf einmal; ohne Summe könnte ein kleines PDF mit vielen „Zip-Bomben“ den Speicher sprengen.
     */
    public const DECODE_GESAMT = 96 * 1048576;
    /** Seiten, deren Inhalt samt aufgerufener Formulare (entpackt) größer ist, werden übersprungen. */
    public const MAX_SEITENINHALT = 4 * 1048576;
    /** Höchstzahl der Objekte (auch aus Objektströmen); jedes kostet die Bibliothek rund 1,5 KB Speicher. */
    public const MAX_OBJEKTE = 200000;
    /** Tiefste Verschachtelung von Formularen (Form-XObjects), die noch gelesen wird. */
    public const MAX_FORMTIEFE = 10;
    /** Speicher, den das Auslesen mindestens bekommt (falls der Webspace erlaubt, ihn anzuheben). */
    private const SPEICHER = 512 * 1048576;
    /** Kürzester sinnvoller Text (ohne Leerraum); darunter gilt das PDF als Scan. */
    private const MIN_TEXT = 20;

    private const NICHT_LESBAR = 'Das PDF konnte nicht gelesen werden.';

    private static bool $aktiv = false;
    private static bool $registriert = false;
    /** Reserve, die der Notfall-Handler freigibt, damit er selbst nach einem Speicherfehler noch arbeiten kann. */
    private static ?string $reserve = null;

    public function __construct(
        public readonly int $maxBytes = self::MAX_BYTES,
        public readonly int $maxSeiten = self::MAX_SEITEN,
        private int $zeitSekunden = 30,
        private bool $speicherAnheben = true,
    ) {
    }

    /** Einstellungen der Bibliothek: keine Bilddaten behalten, Entpacken je Datenstrom begrenzt. */
    public static function bibliotheksConfig(int $decodeLimit = self::DECODE_LIMIT): Config
    {
        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit($decodeLimit);
        return $config;
    }

    /** Größte Datei, die bei der tatsächlich verfügbaren Speichergrenze gelesen wird (für die Anzeige). */
    public function wirksameMaxBytes(): int
    {
        $vorher = self::iniGet('memory_limit');
        try {
            $this->speicherAnheben();
            return $this->grenzen()['datei'];
        } finally {
            self::iniSet('memory_limit', $vorher);
        }
    }

    /**
     * @return array{text:string, seiten:int, gelesen:int}
     * @throws DomainFehler
     */
    public function lies(string $inhalt): array
    {
        if (strlen($inhalt) > $this->maxBytes) {
            throw new DomainFehler(sprintf('Die Datei ist zu groß (höchstens %d MB).', max(1, intdiv($this->maxBytes, 1048576))));
        }
        if (!str_contains(substr($inhalt, 0, 1024), '%PDF-')) {
            throw new DomainFehler('Die Datei ist kein PDF.');
        }
        // Grenzen nur für das Auslesen; danach gilt wieder, was vorher eingestellt war (im CLI z. B. „unbegrenzt“).
        // set_time_limit startet den Zähler neu: nach dem Auslesen hat die Anfrage wieder ihre volle Laufzeit.
        $zeitVorher = (int) self::iniGet('max_execution_time');
        $speicherVorher = self::iniGet('memory_limit');
        self::zeitLimit($this->zeitSekunden);
        $this->speicherAnheben();
        if (!self::$registriert) {
            register_shutdown_function([self::class, 'beimBeenden']);
            self::$registriert = true;
        }
        self::$reserve = str_repeat("\0", 262144);
        self::$aktiv = true;
        try {
            $grenzen = $this->grenzen();
            if (strlen($inhalt) > $grenzen['datei']) {
                throw new DomainFehler(sprintf('Die Datei ist zu groß für diesen Webspace (höchstens %d MB).', max(1, intdiv($grenzen['datei'], 1048576))));
            }
            return $this->auslesen($inhalt, $grenzen);
        } finally {
            self::$aktiv = false;
            self::$reserve = null;
            self::zeitLimit($zeitVorher);
            self::iniSet('memory_limit', $speicherVorher);
        }
    }

    /**
     * @param array{datei:int, budget:int, strom:int, objekte:int} $grenzen
     * @return array{text:string, seiten:int, gelesen:int}
     */
    private function auslesen(string $inhalt, array $grenzen): array
    {
        // Grobe Vorprüfung: „obj“ steht in „n 0 obj“ und „endobj“, also etwa zweimal je Objekt.
        if (substr_count($inhalt, 'obj') > 2 * $grenzen['objekte'] + 10) {
            error_log('PDF-Import: zu viele Objekte, abgelehnt.');
            throw new DomainFehler(self::NICHT_LESBAR);
        }
        try {
            $parser = new BegrenzterParser(self::bibliotheksConfig($grenzen['strom']), $grenzen['budget'], $grenzen['objekte']);
            $dokument = $parser->parseContent($inhalt);
            unset($inhalt);
            if ($parser->filter->ueber) {
                throw new GrenzeUeberschritten('Entpack-Budget überschritten');
            }
            $seiten = array_values($dokument->getPages());
        } catch (GrenzeUeberschritten $g) {
            error_log('PDF-Import: ' . $g->getMessage() . ', abgelehnt.');
            throw new DomainFehler(self::NICHT_LESBAR);
        } catch (\Throwable $t) {
            self::protokolliere($t);
            if (str_contains(strtolower($t->getMessage()), 'secured pdf file are currently not supported')) {
                throw new DomainFehler('Das PDF ist geschützt (verschlüsselt) und kann nicht gelesen werden.');
            }
            throw new DomainFehler(self::NICHT_LESBAR);
        }

        $texte = [];
        $laenge = 0;
        $gelesen = 0;
        foreach (array_slice($seiten, 0, max(0, $this->maxSeiten)) as $seite) {
            if ($laenge >= self::MAX_TEXT) {
                break;
            }
            $gelesen++;
            try {
                $aufwand = $seite instanceof Page ? self::seitenAufwand($seite) : null;
                if ($aufwand === null || $aufwand > self::MAX_SEITENINHALT) {
                    error_log('PDF-Import: Seite ' . $gelesen . ' übersprungen (zu großer Inhalt oder Formulare verschachtelt bzw. zyklisch).');
                    continue;
                }
                $text = (string) $seite->getText();
            } catch (\Throwable $t) {
                self::protokolliere($t);
                continue;
            }
            $rest = self::MAX_TEXT - $laenge;
            if (strlen($text) > $rest) {
                $text = mb_strcut($text, 0, $rest, 'UTF-8');
            }
            $texte[] = $text;
            $laenge += strlen($text) + 1;
        }
        $text = implode("\n", $texte);

        $ohneLeerraum = preg_replace('/\s+/u', '', $text) ?? (string) preg_replace('/\s+/', '', $text);
        if (mb_strlen($ohneLeerraum, 'UTF-8') < self::MIN_TEXT) {
            throw new DomainFehler('Das PDF enthält keinen lesbaren Text (vermutlich ein Scan). Texterkennung gibt es nicht – bitte die Tasten von Hand eintragen.');
        }
        return ['text' => $text, 'seiten' => count($seiten), 'gelesen' => $gelesen];
    }

    /**
     * Grenzen aus der tatsächlich geltenden Speichergrenze: Datei höchstens 1/25, alles Entpackte höchstens 1/5,
     * ein Strom höchstens 1/16 davon, Objekte je 2,5 KB (gemessen: rund 1,5 KB je Objekt).
     *
     * @return array{datei:int, budget:int, strom:int, objekte:int}
     */
    private function grenzen(): array
    {
        $speicher = self::bytes(self::iniGet('memory_limit'));
        if ($speicher <= 0) {
            $speicher = self::SPEICHER; // unbegrenzt: Werte wie bei 512 MB
        }
        return [
            'datei' => min($this->maxBytes, intdiv($speicher, 25)),
            'budget' => min(self::DECODE_GESAMT, intdiv($speicher, 5)),
            'strom' => min(self::DECODE_LIMIT, intdiv($speicher, 16)),
            'objekte' => min(self::MAX_OBJEKTE, intdiv($speicher, 2560)),
        ];
    }

    /**
     * Entpackte Größe, die getText() für eine Seite verarbeitet: Inhalt plus jeder Aufruf („/Name Do“) eines Formulars
     * mit dessen Größe (rekursiv, Formulare rufen über ihre eigenen Ressourcen auf). null bei Zyklus oder zu tiefer
     * Verschachtelung.
     */
    private static function seitenAufwand(Page $seite): ?int
    {
        $inhalt = $seite->get('Contents');
        $teile = $inhalt instanceof ElementArray ? $inhalt->getContent() : [$inhalt];
        $teile = array_filter(is_array($teile) ? $teile : [], static fn($t): bool => $t instanceof PDFObject);
        $groesse = 0;
        foreach ($teile as $teil) {
            $groesse += strlen((string) $teil->getContent()) + 1;
        }
        if ($groesse > self::MAX_SEITENINHALT) {
            return $groesse; // ohne Kopie, die Seite wird übersprungen
        }
        $daten = '';
        foreach ($teile as $teil) {
            $daten .= (string) $teil->getContent() . "\n";
        }
        $memo = [];
        return self::aufwand($seite, $daten, 0, [], $memo);
    }

    /**
     * @param array<int,true> $pfad Formulare auf dem aktuellen Aufrufweg (Zykluserkennung)
     * @param array<int,?int> $memo bereits berechnete Formulare
     */
    private static function aufwand(Page $traeger, string $inhalt, int $tiefe, array $pfad, array &$memo): ?int
    {
        if ($tiefe > self::MAX_FORMTIEFE) {
            return null;
        }
        $summe = strlen($inhalt);
        if (preg_match_all('#/([^\s/\[\]()<>{}%]+)\s*Do(?![A-Za-z0-9_])#', $inhalt, $treffer) < 1) {
            return $summe;
        }
        $tabelle = $traeger->getXObjects();
        foreach (array_count_values($treffer[1]) as $name => $anzahl) {
            $form = $tabelle[(string) $name] ?? null;
            if (!$form instanceof Form) {
                continue; // Bilder und Unbekanntes liest getText() nicht
            }
            $schluessel = spl_object_id($form);
            if (isset($pfad[$schluessel])) {
                return null;
            }
            if (!array_key_exists($schluessel, $memo)) {
                $memo[$schluessel] = self::aufwand($form, (string) $form->getContent(), $tiefe + 1, $pfad + [$schluessel => true], $memo);
            }
            if ($memo[$schluessel] === null) {
                return null;
            }
            $summe += $anzahl * $memo[$schluessel];
            if ($summe > self::MAX_SEITENINHALT) {
                return $summe;
            }
        }
        return $summe;
    }

    /** Notfall-Handler (register_shutdown_function): Fatal Error während des Auslesens → verständliche Fehlerseite. */
    public static function beimBeenden(): void
    {
        $aktiv = self::$aktiv;
        self::$reserve = null;
        self::$aktiv = false;
        $html = self::notfallSeite(error_get_last(), $aktiv);
        if ($html === null) {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
    }

    /** @param array{type?:int, message?:string}|null $fehler error_get_last() */
    public static function notfallSeite(?array $fehler, bool $aktiv): ?string
    {
        $fatal = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!$aktiv || $fehler === null || !in_array($fehler['type'] ?? 0, $fatal, true)) {
            return null;
        }
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>PDF-Import</title></head><body style="font-family:sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem">'
            . '<h1>PDF-Import</h1><p>Das PDF ist zu groß oder zu aufwendig zum Auslesen.</p>'
            . '<p>Bitte im Browser mit „Zurück“ zur Funktionsseite gehen und die Tasten von Hand oder per CSV eintragen.</p>'
            . '</body></html>';
    }

    private function speicherAnheben(): void
    {
        if (!$this->speicherAnheben) {
            return;
        }
        $bytes = self::bytes(self::iniGet('memory_limit'));
        if ($bytes > 0 && $bytes < self::SPEICHER) {
            self::iniSet('memory_limit', (string) self::SPEICHER);
        }
    }

    /** php.ini-Größe in Bytes; -1 (oder leer) = unbegrenzt. */
    private static function bytes(string $roh): int
    {
        $roh = trim($roh);
        if ($roh === '' || $roh === '-1') {
            return -1;
        }
        $zahl = (int) $roh;
        return match (strtolower(substr($roh, -1))) {
            'g' => $zahl * 1073741824,
            'm' => $zahl * 1048576,
            'k' => $zahl * 1024,
            default => $zahl,
        };
    }

    private static function zeitLimit(int $sekunden): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit($sekunden);
        }
    }

    private static function iniGet(string $name): string
    {
        return function_exists('ini_get') ? (string) ini_get($name) : '';
    }

    private static function iniSet(string $name, string $wert): void
    {
        if ($wert !== '' && function_exists('ini_set')) {
            @ini_set($name, $wert);
        }
    }

    private static function protokolliere(\Throwable $t): void
    {
        // Nur Klasse und (gekürzte) Meldung, nie Inhalt der Datei.
        $meldung = (string) preg_replace('/[^\x20-\x7E]/', '?', mb_strcut($t->getMessage(), 0, 200));
        error_log('PDF-Import: ' . $t::class . ': ' . $meldung);
    }
}
