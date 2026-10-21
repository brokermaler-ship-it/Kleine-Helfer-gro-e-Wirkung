<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

use Emc\Auth\Auth;
use Emc\Core\DomainFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

/**
 * „Fahrzeuge importieren“ (ab Fahrzeugwart, Route mit fahrzeug_bearbeiten): Datei hochladen → Vorschau → bestätigen.
 * Zwischen Vorschau und Bestätigung liegt die Datei unter <speicher>/import/<Zufallsname>.csv; die Sitzung merkt sich
 * nur Zufallsname, Dateiname und Prüfsumme. Beim Bestätigen wird die Datei neu geprüft (Spurweiten, frühere Importe).
 */
final class FahrzeugImportController
{
    private const SITZUNG = 'fahrzeug_import';
    /** Liegengebliebene Vorschau-Dateien (Browser geschlossen) werden nach einem Tag weggeräumt. */
    private const AUFRAEUMEN_NACH_S = 86400;

    public function __construct(
        private View $view,
        private FahrzeugImport $import,
        private Auth $auth,
        private Session $session,
        private string $speicherPfad,
    ) {
    }

    public function formular(Request $r, array $p = []): Response
    {
        $this->raeumeAuf();
        return $this->ohneSchemaZumUpdate(fn() => $this->html('fahrzeuge/import', [
            'titel' => 'Fahrzeuge importieren',
            'letzte' => $this->import->letzte(),
            'maxMb' => intdiv(FahrzeugCsvImport::MAX_BYTES, 1048576),
        ]));
    }

    public function hochladen(Request $r, array $p = []): Response
    {
        return $this->ohneSchemaZumUpdate(function () use ($r): Response {
            $this->verwirfVorschau();
            $datei = $r->file('datei');
            $meldung = self::dateiFehler($datei);
            if ($meldung !== null) {
                $this->session->meldung('fehler', $meldung);
                return Response::redirect(url('/fahrzeuge/import'));
            }
            $inhalt = file_get_contents((string) $datei['tmp_name']);
            if ($inhalt === false) {
                $this->session->meldung('fehler', 'Die Datei konnte nicht gelesen werden.');
                return Response::redirect(url('/fahrzeuge/import'));
            }
            $name = FahrzeugCsvImport::dateiname((string) ($datei['name'] ?? ''));
            $ergebnis = $this->import->pruefe($inhalt, $name);
            if ($ergebnis->istImportierbar()) {
                $this->raeumeAuf();
                $token = bin2hex(random_bytes(16));
                $verzeichnis = $this->verzeichnis();
                if ((!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0750, true) && !is_dir($verzeichnis))
                    || file_put_contents($this->pfad($token), $inhalt) === false) {
                    $this->session->meldung('fehler', 'Die Datei konnte nicht zwischengespeichert werden. Bitte die Schreibrechte des Ordners storage prüfen.');
                    return Response::redirect(url('/fahrzeuge/import'));
                }
                $this->session->setze(self::SITZUNG, ['datei' => $token, 'name' => $name, 'hash' => FahrzeugImport::hash($inhalt)]);
            }
            return $this->html('fahrzeuge/import_vorschau', [
                'titel' => 'Import-Vorschau',
                'dateiname' => $name,
                'ergebnis' => $ergebnis,
                'hash' => FahrzeugImport::hash($inhalt),
            ]);
        });
    }

    public function bestaetigen(Request $r, array $p = []): Response
    {
        $vorschau = $this->session->hole(self::SITZUNG);
        $token = is_array($vorschau) ? ($vorschau['datei'] ?? null) : null;
        $inhalt = is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token) === 1 && is_file($this->pfad($token))
            ? file_get_contents($this->pfad($token))
            : false;
        if ($inhalt === false || !hash_equals((string) ($vorschau['hash'] ?? ''), FahrzeugImport::hash($inhalt))) {
            $this->verwirfVorschau();
            $this->session->meldung('fehler', 'Es liegt keine Vorschau vor. Bitte die Datei erneut hochladen.');
            return Response::redirect(url('/fahrzeuge/import'));
        }
        // Zwei Tabs: das Formular nennt die Prüfsumme der angezeigten Datei. Passt sie nicht zur aktuellen Vorschau
        // (inzwischen wurde eine andere Datei hochgeladen), wird nichts importiert; die neuere Vorschau bleibt.
        $angezeigt = $r->text('hash');
        if ($angezeigt === '' || !hash_equals((string) $vorschau['hash'], $angezeigt)) {
            $this->session->meldung('fehler', 'Die Vorschau ist nicht mehr aktuell (inzwischen wurde eine andere Datei hochgeladen). Bitte erneut hochladen.');
            return Response::redirect(url('/fahrzeuge/import'));
        }
        // Ab hier ist die Vorschau verbraucht, ob der Import gelingt oder nicht (Doppelklick legt nichts doppelt an).
        $this->verwirfVorschau();
        try {
            $anzahl = $this->ohneSchemaZumUpdate(fn() => $this->import->importiere(
                $inhalt,
                (string) ($vorschau['name'] ?? ''),
                $this->auth->benutzer()?->id,
                new \DateTimeImmutable()
            ));
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return Response::redirect(url('/fahrzeuge/import'));
        }
        if ($anzahl instanceof Response) {
            return $anzahl;
        }
        $this->session->meldung('erfolg', $anzahl === 1 ? '1 Fahrzeug importiert.' : $anzahl . ' Fahrzeuge importiert.');
        return Response::redirect(url('/fahrzeuge', ['ungeprueft' => '1']));
    }

    public function abbrechen(Request $r, array $p = []): Response
    {
        $this->verwirfVorschau();
        $this->session->meldung('info', 'Der Import wurde abgebrochen.');
        return Response::redirect(url('/fahrzeuge'));
    }

    public function vorlage(Request $r, array $p = []): Response
    {
        return new Response(FahrzeugCsvImport::VORLAGE, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="fahrzeuge-vorlage.csv"',
        ]);
    }

    /**
     * Fehlen Tabelle oder Spalte aus Migration 020, obwohl die Version eingetragen ist (abgebrochenes Update): zum
     * Update statt Fehler 500. Steht die Migration aus, leitet schon App weiter.
     *
     * @template T
     * @param callable(): T $arbeit
     * @return T|Response
     */
    private function ohneSchemaZumUpdate(callable $arbeit): mixed
    {
        try {
            return $arbeit();
        } catch (\PDOException $e) {
            if (!in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                throw $e;
            }
            $this->session->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen.');
            return Response::redirect(url('/admin/update'));
        }
    }

    private function verwirfVorschau(): void
    {
        $vorschau = $this->session->hole(self::SITZUNG);
        $token = is_array($vorschau) ? ($vorschau['datei'] ?? null) : null;
        if (is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token) === 1 && is_file($this->pfad($token))) {
            @unlink($this->pfad($token));
        }
        $this->session->entferne(self::SITZUNG);
    }

    private function raeumeAuf(): void
    {
        foreach (glob($this->verzeichnis() . '/*.csv') ?: [] as $alt) {
            $zeit = @filemtime($alt);
            if ($zeit !== false && $zeit < time() - self::AUFRAEUMEN_NACH_S) {
                @unlink($alt);
            }
        }
    }

    private function verzeichnis(): string
    {
        return rtrim($this->speicherPfad, '/') . '/import';
    }

    private function pfad(string $token): string
    {
        return $this->verzeichnis() . '/' . $token . '.csv';
    }

    private static function dateiFehler(mixed $datei): ?string
    {
        $zuGross = 'Die Datei ist zu groß (höchstens ' . intdiv(FahrzeugCsvImport::MAX_BYTES, 1048576) . ' MB).';
        if (!is_array($datei) || !isset($datei['error']) || $datei['error'] === UPLOAD_ERR_NO_FILE) {
            return 'Bitte eine CSV-Datei auswählen.';
        }
        if ($datei['error'] === UPLOAD_ERR_INI_SIZE || $datei['error'] === UPLOAD_ERR_FORM_SIZE) {
            return $zuGross;
        }
        $tmp = $datei['tmp_name'] ?? null;
        // Im CLI gibt es keine echten Uploads (Tests); dort entfällt die Herkunftsprüfung.
        if ($datei['error'] !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli')) {
            return 'Die Datei konnte nicht hochgeladen werden.';
        }
        $groesse = filesize($tmp);
        if ($groesse === false || $groesse === 0) {
            return 'Die Datei ist leer.';
        }
        return $groesse > FahrzeugCsvImport::MAX_BYTES ? $zuGross : null;
    }

    /** @param array<string,mixed> $daten */
    private function html(string $template, array $daten): Response
    {
        return new Response($this->view->render($template, $daten), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
