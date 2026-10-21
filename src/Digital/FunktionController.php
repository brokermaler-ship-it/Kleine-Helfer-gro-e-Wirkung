<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Auth\Auth;
use Emc\Auth\Berechtigung;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Protokoll\Aenderungsprotokoll;

final class FunktionController
{
    private const MAX_CSV_BYTES = 1048576;

    public function __construct(
        private View $view,
        private DecoderRepository $decoder,
        private FahrzeugRepository $fahrzeuge,
        private FunktionRepository $funktionen,
        private FunktionsvorlageRepository $vorlagen,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function zeigen(Request $r, array $p): Response
    {
        $dec = $this->ladeDecoder($p);
        return $this->seite($dec, $this->funktionen->fuerDecoder((int) $dec['id']), []);
    }

    public function speichern(Request $r, array $p): Response
    {
        $dec = $this->ladeBearbeitbarenDecoder($p);
        $beschreibung = $r->post('beschreibung');
        $moment = $r->post('moment');
        $beschreibung = is_array($beschreibung) ? $beschreibung : [];
        $moment = is_array($moment) ? $moment : [];
        $neu = [];
        $anzeige = [];
        $fehler = [];
        for ($t = 0; $t <= FunktionRepository::MAX_TASTE; $t++) {
            $text = $beschreibung[$t] ?? '';
            $text = is_string($text) ? trim($text) : '';
            $art = ($moment[$t] ?? '') === '1' ? 'moment' : 'dauer';
            if (mb_strlen($text) > 100) {
                $fehler[$t] = 'Die Beschreibung darf höchstens 100 Zeichen lang sein.';
            }
            if ($text !== '') {
                $neu[$t] = ['taste' => $t, 'beschreibung' => $text, 'schaltart' => $art];
            }
            $anzeige[$t] = ['taste' => $t, 'beschreibung' => $text, 'schaltart' => $art];
        }
        if ($fehler !== []) {
            return $this->seite($dec, $anzeige, $fehler, 422);
        }
        $this->uebernimm($dec, $neu);
        $this->session->meldung('erfolg', 'Die Funktionen wurden gespeichert.');
        return Response::redirect($this->ziel($dec));
    }

    public function csvHochladen(Request $r, array $p): Response
    {
        $dec = $this->ladeBearbeitbarenDecoder($p);
        $meldung = $this->dateiFehler($r->file('datei'));
        if ($meldung !== null) {
            $this->session->meldung('fehler', $meldung);
            return Response::redirect($this->ziel($dec));
        }
        $datei = $r->file('datei');
        $inhalt = file_get_contents((string) $datei['tmp_name']);
        if ($inhalt === false) {
            $this->session->meldung('fehler', 'Die Datei konnte nicht gelesen werden.');
            return Response::redirect($this->ziel($dec));
        }
        $ergebnis = FunktionCsvImport::parse($inhalt);
        if ($ergebnis->zeilen === [] && $ergebnis->fehler === []) {
            $this->session->meldung('fehler', 'Die Datei enthält keine Funktionen.');
            return Response::redirect($this->ziel($dec));
        }
        $this->session->setze('csv_vorschau_' . $dec['id'], ['zeilen' => $ergebnis->zeilen, 'fehler' => $ergebnis->fehler]);
        return $this->html('digital/csv_vorschau', [
            'titel' => 'CSV-Vorschau',
            'decoder' => $dec,
            'fahrzeug' => $this->ladeFahrzeug($dec),
            'ergebnis' => $ergebnis,
            'vorhanden' => count($this->funktionen->fuerDecoder((int) $dec['id'])),
        ]);
    }

    public function csvUebernehmen(Request $r, array $p): Response
    {
        $dec = $this->ladeBearbeitbarenDecoder($p);
        $schluessel = 'csv_vorschau_' . $dec['id'];
        $vorschau = $this->session->hole($schluessel);
        if (!is_array($vorschau) || !isset($vorschau['zeilen'], $vorschau['fehler'])) {
            $this->session->meldung('fehler', 'Es liegt keine CSV-Vorschau vor. Bitte die Datei erneut hochladen.');
            return Response::redirect($this->ziel($dec));
        }
        if ($vorschau['fehler'] !== []) {
            $this->session->meldung('fehler', 'Die CSV-Datei enthält Fehler und kann nicht übernommen werden.');
            return Response::redirect($this->ziel($dec));
        }
        $this->session->entferne($schluessel);
        $neu = [];
        foreach ($vorschau['zeilen'] as $z) {
            $neu[(int) $z['taste']] = $z;
        }
        $this->uebernimm($dec, $neu);
        $this->session->meldung('erfolg', 'Die Funktionen wurden aus der CSV-Datei übernommen.');
        return Response::redirect($this->ziel($dec));
    }

    public function csvVorlage(Request $r, array $p = []): Response
    {
        return new Response(FunktionCsvImport::VORLAGE, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="funktionen-vorlage.csv"',
        ]);
    }

    public function alsVorlage(Request $r, array $p): Response
    {
        $dec = $this->ladeBearbeitbarenDecoder($p);
        $name = trim($r->text('name'));
        if ($name === '' || mb_strlen($name) > 100) {
            $this->session->meldung('fehler', 'Bitte einen Vorlagennamen mit höchstens 100 Zeichen eingeben.');
            return Response::redirect($this->ziel($dec));
        }
        if ($this->funktionen->fuerDecoder((int) $dec['id']) === []) {
            $this->session->meldung('fehler', 'Dieser Decoder hat noch keine Funktionen, die als Vorlage gespeichert werden könnten.');
            return Response::redirect($this->ziel($dec));
        }
        try {
            $this->vorlagen->ausDecoder((int) $dec['id'], $name, $dec['typ'] ?? null);
        } catch (DomainFehler $f) {
            $this->session->meldung('fehler', $f->getMessage());
            return Response::redirect($this->ziel($dec));
        }
        $this->session->meldung('erfolg', 'Die Vorlage „' . $name . '“ wurde gespeichert.');
        return Response::redirect($this->ziel($dec));
    }

    public function vorlageAnwenden(Request $r, array $p): Response
    {
        $dec = $this->ladeBearbeitbarenDecoder($p);
        $id = $r->ganzzahl('vorlage_id', 1, 2147483647);
        $vorlage = $id === null ? null : $this->vorlagen->finde($id);
        if ($vorlage === null) {
            $this->session->meldung('fehler', 'Bitte eine Vorlage wählen.');
            return Response::redirect($this->ziel($dec));
        }
        $alt = $this->funktionen->fuerDecoder((int) $dec['id']);
        $this->vorlagen->aufDecoderAnwenden((int) $vorlage['id'], (int) $dec['id']);
        $neu = $this->funktionen->fuerDecoder((int) $dec['id']);
        $this->protokolliere($dec, $alt, $neu);
        $this->session->meldung('erfolg', 'Die Vorlage „' . $vorlage['name'] . '“ wurde angewendet.');
        return Response::redirect($this->ziel($dec));
    }

    /** @param array<string,mixed> $dec @param array<int,array<string,mixed>> $neu */
    private function uebernimm(array $dec, array $neu): void
    {
        $alt = $this->funktionen->fuerDecoder((int) $dec['id']);
        $this->funktionen->ersetzen((int) $dec['id'], array_values($neu));
        $this->protokolliere($dec, $alt, $this->funktionen->fuerDecoder((int) $dec['id']));
    }

    /** @param array<string,mixed> $dec */
    private function protokolliere(array $dec, array $alt, array $neu): void
    {
        if ($alt === $neu) {
            return;
        }
        $this->protokoll->schreibe(
            $this->auth->benutzer()?->id,
            'decoder',
            (int) $dec['id'],
            'geaendert',
            ['funktionen' => ['alt' => count($alt), 'neu' => count($neu)]]
        );
    }

    /** @param mixed $datei */
    private function dateiFehler(mixed $datei): ?string
    {
        if (!is_array($datei) || !isset($datei['error'])) {
            return 'Bitte eine CSV-Datei auswählen.';
        }
        $fehler = $datei['error'];
        if ($fehler === UPLOAD_ERR_NO_FILE) {
            return 'Bitte eine CSV-Datei auswählen.';
        }
        if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
            return 'Die Datei ist zu groß (höchstens 1 MB).';
        }
        $tmp = $datei['tmp_name'] ?? null;
        // Im CLI gibt es keine echten Uploads (Tests); dort entfällt die Herkunftsprüfung.
        if ($fehler !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli')) {
            return 'Die Datei konnte nicht hochgeladen werden.';
        }
        $groesse = filesize($tmp);
        if ($groesse === false || $groesse === 0) {
            return 'Die Datei ist leer.';
        }
        if ($groesse > self::MAX_CSV_BYTES) {
            return 'Die Datei ist zu groß (höchstens 1 MB).';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $dec
     * @param array<int,array<string,mixed>> $werte
     * @param array<int,string> $fehler
     */
    private function seite(array $dec, array $werte, array $fehler, int $status = 200): Response
    {
        return $this->html('digital/funktionen', [
            'titel' => 'Funktionen: ' . $dec['bezeichnung'],
            'decoder' => $dec,
            'fahrzeug' => $this->ladeFahrzeug($dec),
            'funktionen' => $werte,
            'fehler' => $fehler,
            'vorlagen' => $this->vorlagen->alle(),
        ], $status);
    }

    /** @param array<string,mixed> $daten */
    private function html(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed> $dec */
    private function ziel(array $dec): string
    {
        return url('/decoder/' . $dec['id'] . '/funktionen');
    }

    /** @return array<string,mixed> */
    private function ladeFahrzeug(array $dec): array
    {
        return $this->fahrzeuge->finde((int) $dec['fahrzeug_id']) ?? throw new HttpFehler(404);
    }

    /**
     * Decoder laden und prüfen, ob der Benutzer das zugehörige Fahrzeug bearbeiten darf (sonst 403).
     *
     * @return array<string,mixed>
     */
    private function ladeBearbeitbarenDecoder(array $p): array
    {
        $dec = $this->ladeDecoder($p);
        Berechtigung::verlangeFahrzeugBearbeiten($this->auth->benutzer(), $this->ladeFahrzeug($dec));
        return $dec;
    }

    /** @return array<string,mixed> */
    private function ladeDecoder(array $p): array
    {
        $roh = (string) ($p['id'] ?? '');
        $id = preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
        return ($id > 0 ? $this->decoder->finde($id) : null) ?? throw new HttpFehler(404);
    }
}
