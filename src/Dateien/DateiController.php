<?php

declare(strict_types=1);

namespace Emc\Dateien;

use Emc\Anlagen\AnlageRepository;
use Emc\Auth\Auth;
use Emc\Auth\Berechtigung;
use Emc\Boerse\AngebotDienst;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\GraphFehler;
use Emc\OneDrive\Uebertragung;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\OneDrive\Graph;
use Emc\Reparatur\ReparaturRepository;
use Emc\Wartung\WartungController;
use Emc\Wartung\WartungRepository;

final class DateiController
{
    private const MAX_DATEIEN = 20;
    /** Anzeigename je Art eines Fotos an einem Vorgang (Protokolltext „… (Wartung vom 01.02.2026)“). */
    private const BEZUG_NAMEN = ['wartung' => 'Wartung', 'reparatur' => 'Reparatur'];

    public function __construct(
        private View $view,
        private DokumentRepository $dokumente,
        private UploadService $upload,
        private FahrzeugRepository $fahrzeuge,
        private AnlageRepository $anlagen,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
        private ?Uebertragung $uebertragung = null,
        private ?Graph $graph = null,
        private ?WartungRepository $wartungen = null,
        private ?ReparaturRepository $reparaturen = null,
        private ?AngebotDienst $angebote = null,
    ) {
    }

    public function hochladen(Request $r, array $p): Response
    {
        $fz = $this->ladeFahrzeug($p);
        Berechtigung::verlangeFahrzeugBearbeiten($this->auth->benutzer(), $fz);
        return $this->ladeHoch($r, 'fahrzeug', (int) $fz['id']);
    }

    public function hochladenAnlage(Request $r, array $p): Response
    {
        $anlage = $this->ladeAnlage($p);
        return $this->ladeHoch($r, 'anlage', (int) $anlage['id']);
    }

    public function titelbild(Request $r, array $p): Response
    {
        $fz = $this->ladeFahrzeug($p);
        Berechtigung::verlangeFahrzeugBearbeiten($this->auth->benutzer(), $fz);
        return $this->waehleTitelbild($r, 'fahrzeug', $fz, 'Bitte ein Foto dieses Fahrzeugs als Titelbild wählen.');
    }

    public function titelbildAnlage(Request $r, array $p): Response
    {
        return $this->waehleTitelbild($r, 'anlage', $this->ladeAnlage($p), 'Bitte ein Foto dieser Anlage als Titelbild wählen.');
    }

    /** Speichert die hochgeladenen Dateien am Objekt (Typ „fahrzeug“ oder „anlage“); das erste Foto wird Titelbild. */
    private function ladeHoch(Request $r, string $typ, int $objektId): Response
    {
        $dateien = self::normalisiere($r->file('datei'));
        if ($dateien === []) {
            $this->session->meldung('fehler', 'Es wurde keine Datei ausgewählt.');
            return $this->zurueck($typ, $objektId);
        }
        $titel = count($dateien) === 1 ? mb_substr(trim($r->text('titel')), 0, 200) : '';
        $kategorie = $r->text('kategorie', 'sonstiges');
        $benutzerId = $this->auth->benutzer()?->id;
        $erfolge = 0;
        foreach (array_slice($dateien, 0, self::MAX_DATEIEN) as $datei) {
            $name = is_string($datei['name'] ?? null) ? basename(str_replace('\\', '/', $datei['name'])) : '';
            try {
                $id = $this->upload->speichere(
                    $datei,
                    $titel,
                    $kategorie,
                    $benutzerId,
                    $typ === 'fahrzeug' ? $objektId : null,
                    $typ === 'anlage' ? $objektId : null
                );
            } catch (UploadFehler $f) {
                $this->session->meldung('fehler', ($name !== '' ? $name . ': ' : '') . $f->getMessage());
                continue;
            }
            $this->dokumente->zuordnen($id, $typ, $objektId);
            $dok = $this->dokumente->finde($id);
            $erfolge++;
            if ($dok !== null && $dok['kategorie'] === 'foto' && (int) $dok['hat_anzeige'] === 1) {
                $aktuell = $this->objekt($typ, $objektId);
                if ($aktuell !== null && $aktuell['titelbild_dokument_id'] === null) {
                    $this->setzeTitelbild($typ, $objektId, $id);
                }
            }
            $this->protokoll->schreibe($benutzerId, $typ, $objektId, 'geaendert', ['datei' => ['alt' => null, 'neu' => $dok['titel'] ?? $name]]);
        }
        if (count($dateien) > self::MAX_DATEIEN) {
            $this->session->meldung('warnung', 'Es werden höchstens ' . self::MAX_DATEIEN . ' Dateien auf einmal verarbeitet.');
        }
        if ($erfolge > 0) {
            $this->session->meldung('erfolg', $erfolge === 1 ? 'Die Datei wurde hochgeladen.' : $erfolge . ' Dateien wurden hochgeladen.');
        }
        return $this->zurueck($typ, $objektId);
    }

    /** @param array<string,mixed> $objekt */
    private function waehleTitelbild(Request $r, string $typ, array $objekt, string $fehler): Response
    {
        $objektId = (int) $objekt['id'];
        $dokId = $r->ganzzahl('dokument_id', 1, 2147483647);
        $dok = $dokId === null ? null : $this->dokumente->finde($dokId);
        $dateiTyp = $dok === null ? null : DateiTyp::fuerEndung((string) $dok['endung']);
        if ($dok === null || $dateiTyp === null || !$dateiTyp['bild'] || !$this->dokumente->istZugeordnet((int) $dok['id'], $typ, $objektId)) {
            $this->session->meldung('fehler', $fehler);
            return $this->zurueck($typ, $objektId);
        }
        $this->setzeTitelbild($typ, $objektId, (int) $dok['id']);
        $this->protokoll->schreibe($this->auth->benutzer()?->id, $typ, $objektId, 'geaendert', ['titelbild' => ['alt' => $objekt['titelbild_dokument_id'], 'neu' => (int) $dok['id']]]);
        $this->session->meldung('erfolg', 'Das Titelbild wurde geändert.');
        return $this->zurueck($typ, $objektId);
    }

    /** @return ?array<string,mixed> */
    private function objekt(string $typ, int $id): ?array
    {
        return $typ === 'anlage' ? $this->anlagen->finde($id) : $this->fahrzeuge->finde($id);
    }

    private function setzeTitelbild(string $typ, int $id, int $dokumentId): void
    {
        if ($typ === 'anlage') {
            $this->anlagen->setzeTitelbild($id, $dokumentId);
            return;
        }
        $this->dokumente->setzeTitelbild($id, $dokumentId);
    }

    public function loeschen(Request $r, array $p): Response
    {
        $dok = $this->ladeDokument($p);
        $angebotIds = $this->dokumente->objekte((int) $dok['id'], 'boerse_angebot');
        // Von der Seite eines Angebots: ein geteiltes Foto (z. B. vom Magazinartikel übernommen) nur vom Angebot lösen.
        $nurAngebot = $r->ganzzahl('angebot_id', 1, 4294967295);
        if ($nurAngebot !== null && in_array($nurAngebot, $angebotIds, true) && count($this->dokumente->zuordnungen((int) $dok['id'])) > 1) {
            return $this->vomAngebotLoesen($dok, $nurAngebot);
        }
        $bezuege = $this->bezuege((int) $dok['id']);
        $this->verlangeLoeschrecht($dok, $bezuege);
        $benutzer = $this->auth->benutzer();
        // Fotos verkaufter Angebote und von Angeboten einer abgeschlossenen Börse bleiben (für alle).
        foreach ($angebotIds as $angebotId) {
            try {
                if ($benutzer !== null && $this->angebote !== null) {
                    $this->angebote->pruefeAenderbar($angebotId, $benutzer);
                }
            } catch (DomainFehler $e) {
                $magazin = $this->dokumente->objekte((int) $dok['id'], 'magazin_artikel');
                if ($nurAngebot === null && $magazin !== [] && Berechtigung::istBoersenwart($benutzer)) {
                    // Von der Magazinseite: das Foto bleibt am verkauften Angebot, nur vom Magazinartikel lösen.
                    return $this->vomMagazinLoesen($dok, $magazin);
                }
                $this->session->meldung('fehler', $e->getMessage());
                return $this->zurueck('boerse_angebot', $angebotId);
            } catch (HttpFehler) {
                // Recht am Angebot: hat verlangeLoeschrecht schon geprüft (Börsenwart oder geteiltes Dokument).
            }
        }
        $objekte = [
            'fahrzeug' => $this->dokumente->objekte((int) $dok['id'], 'fahrzeug'),
            'anlage' => $this->dokumente->objekte((int) $dok['id'], 'anlage'),
            'magazin_artikel' => $this->dokumente->objekte((int) $dok['id'], 'magazin_artikel'),
            'boerse_angebot' => $angebotIds,
        ];
        if ($nurAngebot !== null && in_array($nurAngebot, $angebotIds, true)) {
            // Zurück auf die Seite, von der gelöscht wurde.
            $objekte = ['boerse_angebot' => [$nurAngebot]] + $objekte;
        }
        try {
            // Rückfall der Freigabe vor dem Löschen (ein Mitglied ändert die Fotos eines freigegebenen Angebots).
            $rueckfall = false;
            foreach ($angebotIds as $angebotId) {
                try {
                    if ($benutzer !== null && $this->angebote?->fotoGeaendert($angebotId, $benutzer) === true) {
                        $rueckfall = true;
                    }
                } catch (HttpFehler) {
                    // Kein Recht am Angebot, aber an der Datei (geteiltes Dokument, z. B. Bearbeiter): kein Rückfall nötig.
                }
            }
            $this->dokumente->loeschen((int) $dok['id']);
        } catch (DomainFehler $e) {
            // Sperrkonflikt (z. B. eine Kasse schließt gerade ab): nichts gelöscht, Meldung statt 500.
            $this->session->meldung('fehler', $e->getMessage());
            return $this->zielNachLoeschen($objekte, $bezuege);
        }
        $this->raeumeOneDriveAuf();
        if ($rueckfall) {
            $this->session->meldung('warnung', 'Weil das Angebot öffentlich war, muss der Börsenwart es erneut freigeben.');
        }
        foreach ($objekte as $typ => $ids) {
            foreach ($ids as $objektId) {
                $this->protokoll->schreibe($this->auth->benutzer()?->id, $typ, $objektId, 'geaendert', ['datei' => ['alt' => $dok['titel'], 'neu' => null]]);
            }
        }
        // Foto einer Wartung bzw. Reparatur: Protokoll am Fahrzeug bzw. an der Anlage.
        foreach ($bezuege as $b) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, $b['typ'], (int) $b['objekt']['id'], 'geaendert', [
                'datei' => ['alt' => $dok['titel'] . ' (' . self::BEZUG_NAMEN[$b['art']] . ' vom ' . date('d.m.Y', (int) strtotime($b['datum'])) . ')', 'neu' => null],
            ]);
        }
        $this->session->meldung('erfolg', 'Die Datei wurde gelöscht.');
        return $this->zielNachLoeschen($objekte, $bezuege);
    }

    /**
     * Zurück auf die Seite des ersten betroffenen Objekts bzw. der Wartung/Reparatur.
     *
     * @param array<string, list<int>> $objekte
     * @param list<array{art:string, id:int, typ:string, objekt:array<string,mixed>, datum:string}> $bezuege
     */
    private function zielNachLoeschen(array $objekte, array $bezuege): Response
    {
        foreach ($objekte as $typ => $ids) {
            if (isset($ids[0])) {
                return $this->zurueck($typ, $ids[0]);
            }
        }
        if (isset($bezuege[0])) {
            $b = $bezuege[0];
            // Wartungsfotos stehen im Wartungsblock des Objekts, Reparaturfotos auf der Seite der Reparatur.
            return Response::redirect($b['art'] === 'wartung'
                ? WartungController::objektUrl($b['typ'], (int) $b['objekt']['id'])
                : url('/reparaturen/' . $b['id']));
        }
        return Response::redirect(url('/fahrzeuge'));
    }

    /**
     * Wartungen und Reparaturen, denen das Dokument (als Foto) zugeordnet ist, samt Objekt und Datum (Wartungsdatum bzw.
     * Meldedatum).
     *
     * @return list<array{art:string, id:int, typ:string, objekt:array<string,mixed>, datum:string}>
     */
    private function bezuege(int $dokumentId): array
    {
        $ergebnis = [];
        foreach (['wartung', 'reparatur'] as $art) {
            foreach ($this->dokumente->objekte($dokumentId, $art) as $id) {
                $vorgang = $art === 'wartung' ? $this->wartungen?->finde($id) : $this->reparaturen?->finde($id);
                if ($vorgang === null) {
                    continue;
                }
                $typ = (string) $vorgang['objekt_typ'];
                $objekt = $typ === 'anlage' ? $this->anlagen->finde((int) $vorgang['objekt_id']) : $this->fahrzeuge->finde((int) $vorgang['objekt_id']);
                if ($objekt !== null) {
                    $datum = (string) ($art === 'wartung' ? $vorgang['datum'] : $vorgang['gemeldet_am']);
                    $ergebnis[] = ['art' => $art, 'id' => $id, 'typ' => $typ, 'objekt' => $objekt, 'datum' => $datum];
                }
            }
        }
        return $ergebnis;
    }

    /**
     * Löschregel für Dateien (die Route kennt keinen Fahrzeugkontext, daher wird er aus den Zuordnungen abgeleitet):
     * Bearbeiter und Admin (Dateien bearbeiten) dürfen jede Datei löschen. Alle anderen nur eine Datei mit genau einer
     * Zuordnung: an einem Fahrzeug, das sie bearbeiten dürfen, als Foto einer Wartung bzw. Reparatur mit demselben
     * Recht wie beim Eintragen der Wartung bzw. Bearbeiten der Reparatur, als Foto eines Magazinartikels als
     * Börsenwart oder als Foto eines Angebots mit Berechtigung::darfAngebotBearbeiten (Börsenwart; Mitglied am eigenen,
     * noch angebotenen Angebot). Ein Foto, das nur an Magazinartikeln und Angeboten hängt (aus dem Magazin übernommen),
     * löscht auch der Börsenwart. Andere geteilte Dokumente bleiben Bearbeitern vorbehalten.
     *
     * @param array<string,mixed> $dok
     * @param list<array{art:string, id:int, typ:string, objekt:array<string,mixed>, datum:string}> $bezuege
     */
    private function verlangeLoeschrecht(array $dok, array $bezuege = []): void
    {
        $benutzer = $this->auth->benutzer();
        if (Berechtigung::darf($benutzer, Berechtigung::DATEI_BEARBEITEN)) {
            return;
        }
        $zuordnungen = $this->dokumente->zuordnungen((int) $dok['id']);
        $art = count($zuordnungen) === 1 ? $zuordnungen[0]['objekt_typ'] : null;
        if ($art === 'wartung' || $art === 'reparatur') {
            $b = $bezuege[0] ?? throw new HttpFehler(403);
            $erlaubt = $art === 'wartung'
                ? Berechtigung::darfWartungEintragen($benutzer, $b['typ'], $b['objekt'])
                : Berechtigung::darfReparaturBearbeiten($benutzer, $b['typ'], $b['objekt']);
            if (!$erlaubt) {
                throw new HttpFehler(403);
            }
            return;
        }
        if ($art === 'magazin_artikel') {
            // Fotos eines Magazinartikels pflegt der Börsenwart (wie das Hochladen).
            Berechtigung::verlangeBoersenwart($benutzer);
            return;
        }
        if ($art === 'boerse_angebot') {
            // Foto eines Angebots: der Börsenwart oder das Mitglied an seinem eigenen Angebot, solange es angeboten ist.
            $angebot = $this->angebote?->angebot($zuordnungen[0]['objekt_id']);
            if ($angebot === null || !Berechtigung::darfAngebotBearbeiten($benutzer, $angebot)) {
                throw new HttpFehler(403);
            }
            return;
        }
        if ($zuordnungen !== [] && Berechtigung::istBoersenwart($benutzer)
            && array_diff(array_column($zuordnungen, 'objekt_typ'), ['magazin_artikel', 'boerse_angebot']) === []
        ) {
            // Vom Magazinartikel ins Angebot übernommenes Foto: Magazin und Börse pflegt der Börsenwart.
            return;
        }
        if ($art !== 'fahrzeug') {
            throw new HttpFehler(403);
        }
        $fz = $this->fahrzeuge->finde($zuordnungen[0]['objekt_id']) ?? throw new HttpFehler(403);
        Berechtigung::verlangeFahrzeugBearbeiten($benutzer, $fz);
    }

    public function ausliefern(Request $r, array $p): Response
    {
        $dok = $this->ladeDokument($p);
        $variante = $p['variante'] ?? null;
        if ($variante !== null && $variante !== 'anzeige' && $variante !== 'vorschau') {
            throw new HttpFehler(404);
        }
        $basis = $this->dokumente->absoluterPfad((string) $dok['pfad']);
        if ($basis === null) {
            throw new HttpFehler(404);
        }
        $typ = DateiTyp::fuerEndung((string) $dok['endung']);
        $datei = $basis;
        $mime = (string) $dok['mime'];
        $inline = $typ !== null && $typ['anzeige'] === 'inline';
        $istVariante = false;
        if ($variante !== null && $typ !== null && $typ['bild'] && is_file($basis . '.' . $variante . '.jpg')) {
            $datei = $basis . '.' . $variante . '.jpg';
            $mime = 'image/jpeg';
            $inline = true;
            $istVariante = true;
        }
        $remote = !$istVariante && !is_file($basis) && ($dok['ablage'] ?? 'lokal') === 'onedrive' && (string) ($dok['onedrive_item_id'] ?? '') !== '';
        if (!$istVariante && !$remote && !is_file($basis)) {
            throw new HttpFehler(404);
        }
        $name = (string) $dok['original_name'];
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '_') ?: 'datei';
        $header = [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];
        if ($mime !== 'application/pdf') {
            $header['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }
        if ($remote) {
            return $this->ausOneDrive($dok, $header);
        }
        return Response::datei($datei, $header);
    }

    /**
     * Streamt das Original aus OneDrive (nur über die App, nie per Freigabelink).
     *
     * @param array<string,mixed> $dok
     * @param array<string,string> $header
     */
    private function ausOneDrive(array $dok, array $header): Response
    {
        $temp = tempnam(sys_get_temp_dir(), 'emc_od');
        if ($temp !== false) {
            // Bricht der Browser während der Ausgabe ab, bleibt sonst die Temp-Datei liegen.
            register_shutdown_function(static function () use ($temp): void {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            });
        }
        try {
            // Laufwerk der Datei selbst (beim Hochladen gemerkt), nicht zwingend das des aktuellen Zielordners.
            $drive = $this->uebertragung?->laufwerkFuer($dok);
            $item = (string) ($dok['onedrive_item_id'] ?? '');
            if ($temp === false || $this->graph === null || $drive === null || $item === '') {
                throw new GraphFehler('OneDrive ist nicht eingerichtet.');
            }
            $this->graph->inhaltNachDatei($drive, $item, $temp);
            return Response::datei($temp, $header, true);
        } catch (\Throwable $t) {
            if ($temp !== false) {
                @unlink($temp);
            }
            error_log('OneDrive-Original nicht abrufbar: ' . $t::class);
            // Wird als normale Fehlerseite (503) im Layout dargestellt.
            throw new HttpFehler(503);
        }
    }

    /** Führt offene Lösch- und weitere fällige Aufträge nebenbei aus; Fehler bleiben unsichtbar, Aufträge werden später wiederholt. */
    private function raeumeOneDriveAuf(): void
    {
        $this->uebertragung?->nebenbei();
    }

    /**
     * Vereinheitlicht $_FILES-Einträge (einzeln oder `datei[]`) zu einer Liste; leere Auswahl entfällt.
     *
     * @return list<array<string,mixed>>
     */
    public static function normalisiere(mixed $f): array
    {
        if (!is_array($f) || !isset($f['error'])) {
            return [];
        }
        if (!is_array($f['error'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $liste = [];
        foreach ($f['error'] as $i => $fehler) {
            if (is_array($fehler) || $fehler === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $liste[] = [
                'name' => $f['name'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $fehler,
                'size' => $f['size'][$i] ?? 0,
            ];
        }
        return $liste;
    }

    /**
     * Löst ein geteiltes Foto nur vom Angebot (das Dokument bleibt z. B. am Magazinartikel).
     *
     * @param array<string,mixed> $dok
     */
    private function vomAngebotLoesen(array $dok, int $angebotId): Response
    {
        $benutzer = $this->auth->benutzer() ?? throw new HttpFehler(403);
        if ($this->angebote === null) {
            throw new HttpFehler(404);
        }
        try {
            // Recht, Änderbarkeit, Lösen und Rückfall der Freigabe in einer Transaktion.
            $rueckfall = $this->angebote->loeseFoto($angebotId, (int) $dok['id'], $benutzer);
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return $this->zurueck('boerse_angebot', $angebotId);
        }
        $this->session->meldung('erfolg', 'Das Foto wurde vom Angebot entfernt.');
        if ($rueckfall) {
            $this->session->meldung('warnung', 'Weil das Angebot öffentlich war, muss der Börsenwart es erneut freigeben.');
        }
        return $this->zurueck('boerse_angebot', $angebotId);
    }

    /**
     * Foto, das auch an einem verkauften Angebot (bzw. einer abgeschlossenen Börse) hängt: bleibt dort und wird nur von
     * den Magazinartikeln gelöst (Titelbild rückt nach).
     *
     * @param array<string,mixed> $dok
     * @param list<int> $artikelIds
     */
    private function vomMagazinLoesen(array $dok, array $artikelIds): Response
    {
        foreach ($artikelIds as $artikelId) {
            $this->dokumente->loeseZuordnung((int) $dok['id'], 'magazin_artikel', $artikelId);
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'magazin_artikel', $artikelId, 'geaendert', ['datei' => ['alt' => $dok['titel'], 'neu' => null]]);
        }
        $this->session->meldung('warnung', 'Das Foto gehört auch zu einem verkauften Angebot und bleibt dort; vom Magazinartikel wurde es entfernt.');
        return $this->zurueck('magazin_artikel', $artikelIds[0]);
    }

    private function zurueck(string $typ, int $objektId): Response
    {
        if ($typ === 'boerse_angebot') {
            return Response::redirect(url('/angebote/' . $objektId) . '#fotos');
        }
        if ($typ === 'magazin_artikel') {
            return Response::redirect(url('/magazin/' . $objektId) . '#fotos');
        }
        return Response::redirect(url(($typ === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . $objektId) . '#dateien');
    }

    private static function id(array $p): int
    {
        $id = preg_match('/^\d{1,10}$/', (string) ($p['id'] ?? '')) === 1 ? (int) $p['id'] : 0;
        return $id > 0 && $id <= 4294967295 ? $id : 0;
    }

    /** @return array<string,mixed> */
    private function ladeFahrzeug(array $p): array
    {
        $id = self::id($p);
        $fz = $id > 0 ? $this->fahrzeuge->finde($id) : null;
        if ($fz === null) {
            throw new HttpFehler(404);
        }
        return $fz;
    }

    /**
     * Anlage zur Route; die Route verlangt bereits ANLAGE_BEARBEITEN, hier wird es zur Sicherheit erneut geprüft.
     *
     * @return array<string,mixed>
     */
    private function ladeAnlage(array $p): array
    {
        if (!Berechtigung::darf($this->auth->benutzer(), Berechtigung::ANLAGE_BEARBEITEN)) {
            throw new HttpFehler(403);
        }
        $id = self::id($p);
        $anlage = $id > 0 ? $this->anlagen->finde($id) : null;
        if ($anlage === null) {
            throw new HttpFehler(404);
        }
        return $anlage;
    }

    /** @return array<string,mixed> */
    private function ladeDokument(array $p): array
    {
        $id = self::id($p);
        $dok = $id > 0 ? $this->dokumente->finde($id) : null;
        if ($dok === null) {
            throw new HttpFehler(404);
        }
        return $dok;
    }
}
