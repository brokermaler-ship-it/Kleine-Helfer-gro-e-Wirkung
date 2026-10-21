<?php

declare(strict_types=1);

namespace Emc\OneDrive;

use Emc\Betrieb\Einstellungen;
use Emc\Dateien\DokumentRepository;

/** Arbeitet die OneDrive-Warteschlange ab: Hochladen, lokales Original entfernen, Löschen. */
final class Uebertragung
{
    /** Standard-Zeitbudget je Web-Anfrage in Sekunden: danach werden keine neuen Übertragungen mehr begonnen. */
    public const ZEITBUDGET_WEB = 25.0;
    /** Wie viele andere fällige Aufträge bei einem Upload bzw. Löschen nebenbei mit erledigt werden. */
    public const NEBENBEI = 2;

    private \Closure $sekundenUhr;
    private float $start;

    public function __construct(
        private Auftraege $auftraege,
        private Graph $graph,
        private TokenSpeicher $tokens,
        private Einstellungen $einstellungen,
        private DokumentRepository $dokumente,
        private \PDO $pdo,
        private string $speicherPfad,
        private bool $originaleBehalten = false,
        private ?float $zeitbudget = null,
        ?\Closure $sekundenUhr = null,
    ) {
        $this->sekundenUhr = $sekundenUhr ?? static fn(): float => microtime(true);
        $this->start = ($this->sekundenUhr)();
    }

    /**
     * Setzt das Zeitbudget (Sekunden ab jetzt; null = unbegrenzt, z. B. im Cronjob). Ist es aufgebraucht,
     * beginnt die Übertragung keinen weiteren Auftrag; die übrigen bleiben in der Warteschlange.
     */
    public function setzeZeitbudget(?float $sekunden): void
    {
        $this->zeitbudget = $sekunden;
        $this->start = ($this->sekundenUhr)();
    }

    /** Ist im Zeitbudget noch Platz für einen weiteren Auftrag? */
    public function zeitUebrig(): bool
    {
        return $this->zeitbudget === null || (($this->sekundenUhr)() - $this->start) < $this->zeitbudget;
    }

    /** Sind Client-ID, Client-Secret und Schlüssel gesetzt? Erst dann dürfen Tresor/TokenSpeicher gebaut werden. */
    public static function konfiguriert(array $config): bool
    {
        foreach (['onedrive_client_id', 'onedrive_client_secret', 'app_schluessel'] as $k) {
            if (!is_string($config[$k] ?? null) || trim($config[$k]) === '') {
                return false;
            }
        }
        return self::rueckrufUrl($config) !== null;
    }

    /**
     * Einzige Quelle der OAuth-Rückruf-URL (Container und Verwaltungsseite): oeffentliche_url (nur https, aus der config.php,
     * nie aus dem Host-Header) + basis_url (Pfadpräfix) + Rückrufpfad. Null, solange oeffentliche_url fehlt oder nicht https ist.
     */
    public static function rueckrufUrl(array $config): ?string
    {
        $oeffentlich = rtrim(trim((string) ($config['oeffentliche_url'] ?? '')), '/');
        $label = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
        if (preg_match('#^https://(' . $label . '(?:\.' . $label . ')*)(?::(\d{1,5}))?$#i', $oeffentlich, $m) !== 1
            || (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 65535))
        ) {
            return null;
        }
        $oeffentlich = 'https://' . substr($oeffentlich, 8);
        return $oeffentlich . rtrim((string) ($config['basis_url'] ?? ''), '/') . '/admin/onedrive/rueckruf';
    }

    /** Verbunden und Zielordner gewählt. */
    public function aktiv(): bool
    {
        return $this->tokens->status() === TokenSpeicher::STATUS_VERBUNDEN && $this->ziel() !== null;
    }

    /**
     * Arbeitet fällige Aufträge ab. Jeder Auftrag wird einzeln beansprucht bzw. seine Sperre vor der Bearbeitung
     * verlängert, damit ein langsamer Lauf keinen Auftrag bearbeitet, den inzwischen ein anderer Lauf übernommen hat.
     *
     * @param ?int $auftragId nur diesen Auftrag bearbeiten
     * @param ?string $art nur Aufträge dieser Art ('hochladen' oder 'loeschen')
     * @return array{erledigt:int,fehler:int}
     */
    public function verarbeite(int $max = 5, ?\DateTimeImmutable $jetzt = null, ?int $auftragId = null, ?string $art = null): array
    {
        $uhr = static fn(): \DateTimeImmutable => $jetzt ?? new \DateTimeImmutable();
        $ergebnis = ['erledigt' => 0, 'fehler' => 0];
        if (!$this->aktiv()) {
            return $ergebnis;
        }
        $ziel = $this->ziel();
        $this->auftraege->raeumeAuf($uhr());
        for ($n = 0; $n < max(1, $max); $n++) {
            if (!$this->zeitUebrig()) {
                break;
            }
            $a = $this->auftraege->beanspruche(1, $uhr(), $auftragId, $art)[0] ?? null;
            if ($a === null) {
                break;
            }
            $r = $this->bearbeite($a, $ziel, $uhr);
            if ($r === null) {
                return $ergebnis;
            }
            $ergebnis[$r ? 'erledigt' : 'fehler']++;
            if ($auftragId !== null) {
                break;
            }
        }
        return $ergebnis;
    }

    /**
     * @param array<string,mixed> $a
     * @param array{drive_id:string,item_id:string,name:string} $ziel
     * @return ?bool true erledigt, false Fehler, null Verbindung abgelaufen (Lauf abbrechen)
     */
    private function bearbeite(array $a, array $ziel, \Closure $uhr): ?bool
    {
        $id = (int) $a['id'];
        $token = (string) $a['sperr_token'];
        try {
            if ($a['art'] === 'loeschen') {
                if (is_string($a['item_id'] ?? null) && $a['item_id'] !== '') {
                    // Laufwerk der Datei selbst; nur bei alten Aufträgen ohne Angabe das des aktuellen Zielordners.
                    $drive = is_string($a['drive_id'] ?? null) && $a['drive_id'] !== '' ? $a['drive_id'] : $ziel['drive_id'];
                    $this->graph->loeschen($drive, $a['item_id']);
                }
            } else {
                $this->hochladen((int) $a['dokument_id'], $ziel);
            }
            $this->auftraege->erledigt($id, $token);
            return true;
        } catch (GraphFehler $e) {
            if ($e->istAbgelaufen()) {
                $this->tokens->setzeStatus(TokenSpeicher::STATUS_ABGELAUFEN);
                $this->auftraege->freigeben($id, 'Verbindung abgelaufen', 600, $uhr(), $token);
                return null;
            }
            if ($e->istTokenFehler() && !$e->wiederholbar()) {
                // z. B. invalid_client (abgelaufenes Client-Secret): betrifft alle Aufträge, nicht die einzelne Datei.
                $this->einstellungen->setze('onedrive_letzter_fehler', mb_substr($e->getMessage(), 0, 300));
                $this->tokens->setzeStatus(TokenSpeicher::STATUS_ABGELAUFEN);
                $this->auftraege->freigeben($id, 'Anmeldung bei OneDrive abgelehnt', 600, $uhr(), $token);
                return null;
            }
            if (self::dauerhaft($e)) {
                $this->auftraege->dauerhaftFehlgeschlagen($id, $e->getMessage(), $token);
            } else {
                $this->auftraege->fehlgeschlagen($id, $e->getMessage(), $e->warteSekunden(), $uhr(), $token);
            }
            return false;
        } catch (\Throwable $t) {
            $this->auftraege->fehlgeschlagen($id, $t::class . ': ' . $t->getMessage(), null, $uhr(), $token);
            return false;
        }
    }

    /** Fehler, die sich durch Wiederholen nicht beheben (Ordner gesperrt/fehlt, Datei zu groß, Speicher voll …). */
    private static function dauerhaft(GraphFehler $e): bool
    {
        $s = $e->httpStatus;
        if ($s === 507) {
            return true;
        }
        return $s >= 400 && $s < 500 && !in_array($s, [401, 408, 409, 423, 429], true);
    }

    /** Zielordner gewählt: Neuuploads werden dann eingereiht, auch wenn die Verbindung gerade abgelaufen ist. */
    public function bereit(): bool
    {
        return $this->ziel() !== null;
    }

    /**
     * Reiht ein frisch gespeichertes Dokument ein und versucht diesen Auftrag sofort (falls verbunden und im
     * Zeitbudget). Gelingt er, werden nebenbei bis zu zwei weitere fällige Aufträge erledigt – so holt die
     * Anwendung auch ohne Cronjob liegen gebliebene Übertragungen beim nächsten Aufruf nach.
     */
    public function einreihen(int $dokumentId): void
    {
        if (!$this->bereit()) {
            return;
        }
        $id = $this->auftraege->neu('hochladen', $dokumentId, null);
        if (!$this->aktiv()) {
            return;
        }
        $r = $this->verarbeite(1, null, $id);
        if ($r['erledigt'] === 1) {
            $this->verarbeite(self::NEBENBEI);
        }
    }

    /**
     * Nach dem Löschen (Datei oder Fahrzeug): zuerst Lösch-Aufträge, dann weitere fällige Aufträge, alles im
     * Zeitbudget. Fehler bleiben unsichtbar (nur Log), die Aufträge werden später wiederholt.
     */
    public function nebenbei(int $loeschen = 3): void
    {
        try {
            if (!$this->aktiv()) {
                return;
            }
            $r = $this->verarbeite($loeschen, null, null, 'loeschen');
            if ($r['fehler'] === 0) {
                $this->verarbeite(self::NEBENBEI);
            }
        } catch (\Throwable $t) {
            error_log('OneDrive nebenbei: ' . $t::class);
        }
    }

    /** Setzt fehlgeschlagene Lösch-Aufträge zurück (z. B. nach Behebung der Ursache). */
    public function loeschFehlerZuruecksetzen(?\DateTimeImmutable $jetzt = null): int
    {
        return $this->auftraege->loeschFehlerZuruecksetzen($jetzt);
    }

    /** Legt für alle lokalen Dokumente ohne offenen Auftrag einen Hochlade-Auftrag an. */
    public function umzug(?\DateTimeImmutable $jetzt = null): int
    {
        if (!$this->aktiv()) {
            return 0;
        }
        $jetzt ??= new \DateTimeImmutable();
        $st = $this->pdo->prepare(
            "INSERT INTO onedrive_auftrag (art, dokument_id, naechster_versuch) SELECT 'hochladen', d.id, ? FROM dokument d "
            . "WHERE d.ablage = 'lokal' AND NOT EXISTS (SELECT 1 FROM onedrive_auftrag a WHERE a.dokument_id = d.id AND a.art = 'hochladen' AND a.status = 'wartet') "
            . "AND NOT EXISTS (SELECT 1 FROM dokument_zuordnung z WHERE z.dokument_id = d.id AND z.objekt_typ = 'boerse_anfrage')"
        );
        $st->execute([$jetzt->format('Y-m-d H:i:s')]);
        return $st->rowCount();
    }

    /**
     * Objekt (Fahrzeug bzw. Anlage) der ersten Wartung bzw. Reparatur, der das Dokument zugeordnet ist.
     *
     * @param string $typ wartung oder reparatur (feste Tabellennamen)
     * @return ?array{objekt_typ:string, objekt_id:int|string}
     */
    private function objektVon(int $dokumentId, string $typ): ?array
    {
        $tabelle = match ($typ) {
            'wartung' => 'wartung',
            'reparatur' => 'reparatur',
        };
        foreach ($this->dokumente->objekte($dokumentId, $typ) as $id) {
            $st = $this->pdo->prepare("SELECT objekt_typ, objekt_id FROM $tabelle WHERE id = ?");
            $st->execute([$id]);
            $z = $st->fetch(\PDO::FETCH_ASSOC);
            return $z === false ? null : $z;
        }
        return null;
    }

    /** @param array{drive_id:string,item_id:string,name:string} $ziel */
    private function hochladen(int $dokumentId, array $ziel): void
    {
        $dok = $this->dokumente->finde($dokumentId);
        if ($dok === null || $dok['ablage'] === 'onedrive') {
            return;
        }
        // Fotos aus öffentlichen Börsenanfragen gehen nie nach OneDrive (auch nicht, wenn sie inzwischen zusätzlich an
        // einem Magazinartikel hängen); sie bleiben lokal und werden mit der Anfrage gelöscht. Vor der Dateiprüfung:
        // eine fehlende Datei einer Anfrage ist kein Fehler des Auftrags.
        if ($this->dokumente->objekte($dokumentId, 'boerse_anfrage') !== []) {
            return;
        }
        $basis = $this->dokumente->absoluterPfad((string) $dok['pfad']);
        if ($basis === null || !is_file($basis)) {
            throw new \RuntimeException('Die lokale Datei fehlt.');
        }
        $fahrzeugIds = $this->dokumente->objekte($dokumentId, 'fahrzeug');
        $anlageIds = $this->dokumente->objekte($dokumentId, 'anlage');
        $unterordner = '';
        if ($fahrzeugIds === [] && $anlageIds === []) {
            // Foto einer Wartung bzw. Reparatur: landet im Ordner des Objekts (Unterordner „Wartung“ bzw. „Reparatur“).
            foreach (['wartung' => 'Wartung', 'reparatur' => 'Reparatur'] as $typ => $ordner) {
                $bezug = $this->objektVon($dokumentId, $typ);
                if ($bezug !== null) {
                    $unterordner = $ordner;
                    if ($bezug['objekt_typ'] === 'fahrzeug') {
                        $fahrzeugIds = [(int) $bezug['objekt_id']];
                    } else {
                        $anlageIds = [(int) $bezug['objekt_id']];
                    }
                    break;
                }
            }
        }
        $pfad = null;
        if ($fahrzeugIds === [] && $anlageIds === []) {
            // Foto eines Magazinartikels: Ordner „Magazin/M<id> <Bezeichnung>“.
            foreach ($this->dokumente->objekte($dokumentId, 'magazin_artikel') as $artikelId) {
                $st = $this->pdo->prepare('SELECT id, bezeichnung FROM magazin_artikel WHERE id = ?');
                $st->execute([$artikelId]);
                $artikel = $st->fetch(\PDO::FETCH_ASSOC);
                if ($artikel !== false) {
                    $pfad = Pfadbildner::fuerMagazin($dok, $artikel);
                }
                break;
            }
        }
        $fahrzeug = null;
        foreach ($fahrzeugIds as $fzId) {
            $st = $this->pdo->prepare('SELECT inventarnummer, bezeichnung FROM fahrzeug WHERE id = ?');
            $st->execute([$fzId]);
            $fahrzeug = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            break;
        }
        $anlage = null;
        if ($fahrzeug === null) {
            foreach ($anlageIds as $anlageId) {
                $st = $this->pdo->prepare('SELECT nummer, name FROM anlage WHERE id = ?');
                $st->execute([$anlageId]);
                $anlage = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                break;
            }
        }
        $e = $this->graph->hochladen($ziel['drive_id'], $ziel['item_id'], $pfad ?? Pfadbildner::fuerDokument($dok, $fahrzeug, $anlage, $unterordner), $basis);

        // Erst vermerken, dann das Original entfernen: bricht etwas dazwischen ab, bleibt die Datei erreichbar.
        $st = $this->pdo->prepare("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = ?, onedrive_drive_id = ?, onedrive_pfad = ?, original_lokal = 1 WHERE id = ? AND ablage = 'lokal'");
        $st->execute([$e['item_id'], $ziel['drive_id'], mb_substr($e['pfad'], 0, 500), $dokumentId]);
        if ($st->rowCount() === 0) {
            // Dokument wurde inzwischen gelöscht oder von einem anderen Lauf übertragen: unser Duplikat entfernen.
            $jetzt = $this->dokumente->finde($dokumentId);
            if ($jetzt === null || (string) ($jetzt['onedrive_item_id'] ?? '') !== $e['item_id']) {
                $this->graph->loeschen($ziel['drive_id'], $e['item_id']);
            }
            return;
        }
        if (!$this->originaleBehalten) {
            // Erst „nicht mehr lokal“ vermerken, dann löschen; scheitert das Löschen, wieder zurücksetzen.
            $this->pdo->prepare('UPDATE dokument SET original_lokal = 0 WHERE id = ?')->execute([$dokumentId]);
            if (!@unlink($basis) && is_file($basis)) {
                $this->pdo->prepare('UPDATE dokument SET original_lokal = 1 WHERE id = ?')->execute([$dokumentId]);
            }
        }
    }

    /**
     * Laufwerk, in dem die Datei eines Dokuments liegt: das beim Hochladen gemerkte, sonst (ältere Einträge)
     * das des aktuellen Zielordners.
     *
     * @param array<string,mixed> $dok
     */
    public function laufwerkFuer(array $dok): ?string
    {
        $d = $dok['onedrive_drive_id'] ?? null;
        if (is_string($d) && $d !== '') {
            return $d;
        }
        return $this->ziel()['drive_id'] ?? null;
    }

    /**
     * Schreibt für Dokumente und Lösch-Aufträge ohne gemerktes Laufwerk das des aktuellen Zielordners fest.
     * Vor jedem Wechsel des Zielordners aufrufen, damit ältere Einträge nicht dem neuen Ordner „folgen“.
     */
    public function laufwerkFestschreiben(): void
    {
        $drive = $this->ziel()['drive_id'] ?? null;
        if ($drive === null) {
            return;
        }
        $this->pdo->prepare("UPDATE dokument SET onedrive_drive_id = ? WHERE ablage = 'onedrive' AND (onedrive_drive_id IS NULL OR onedrive_drive_id = '')")->execute([$drive]);
        $this->pdo->prepare("UPDATE onedrive_auftrag SET drive_id = ? WHERE art = 'loeschen' AND status IN ('wartet', 'fehler') AND (drive_id IS NULL OR drive_id = '')")->execute([$drive]);
    }

    /**
     * Wie viele übertragene Dateien liegen in einem anderen Laufwerk als $driveId? (Für die Warnung bei der Ordnerwahl.)
     */
    public function dateienAusserhalb(string $driveId): int
    {
        $aktuell = $this->ziel()['drive_id'] ?? '';
        try {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM dokument WHERE ablage = 'onedrive' AND COALESCE(NULLIF(onedrive_drive_id, ''), ?) <> ?");
            $st->execute([$aktuell, $driveId]);
            return (int) $st->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /** @return ?array{drive_id:string,item_id:string,name:string} */
    public function ziel(): ?array
    {
        $roh = $this->einstellungen->hole('onedrive_ziel');
        $d = $roh === null || $roh === '' ? null : json_decode($roh, true);
        if (!is_array($d) || !is_string($d['drive_id'] ?? null) || !is_string($d['item_id'] ?? null) || $d['drive_id'] === '' || $d['item_id'] === '') {
            return null;
        }
        return ['drive_id' => $d['drive_id'], 'item_id' => $d['item_id'], 'name' => (string) ($d['name'] ?? '')];
    }
}
