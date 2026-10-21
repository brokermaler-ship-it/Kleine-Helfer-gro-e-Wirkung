<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Auth\Benutzer;
use Emc\Auth\Berechtigung;
use Emc\Codes\Code;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Transaktion;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Angebote der Börse anlegen, ändern, freigeben und ihren Status setzen. Jede Methode läuft in einer eigenen
 * Transaktion READ COMMITTED und sperrt in der festen Reihenfolge boerse (nach id) → boerse_angebot →
 * magazin_sammlung → magazin_artikel (nach id). Erst nach den Sperren werden Status, Bestand und gebundene Mengen
 * gelesen. „verkauft“ setzt nur die Kasse.
 */
final class AngebotDienst
{
    public const OBJEKT = 'boerse_angebot';
    public const BOERSE_ABGESCHLOSSEN = 'Die Börse ist abgeschlossen und kann nicht mehr geändert werden.';
    public const VERKAUFT = 'Verkaufte Angebote lassen sich nicht mehr ändern.';
    public const NICHT_GEFUNDEN = 'Dieses Angebot gibt es nicht mehr.';
    public const BOERSE_FEHLT = 'Diese Börse gibt es nicht mehr.';
    public const SAMMLUNG_SCHON = 'Die Sammlung ist schon im Angebot.';
    public const SAMMLUNG_FEHLT = 'Diese Sammlung gibt es nicht mehr.';
    public const SAMMLUNG_OHNE_BESTAND = 'Die Sammlung hat keinen Bestand mehr.';
    public const AKTION_UNBEKANNT = 'Diese Aktion gibt es nicht.';
    public const AKTIONEN = ['zurueckziehen', 'reservieren', 'reservierung_aufheben', 'wieder_anbieten'];

    public function __construct(
        private \PDO $pdo,
        private AngebotRepository $rep,
        private MagazinRepository $magazin,
        private Aenderungsprotokoll $protokoll,
        private ?BoerseRepository $boersen = null,
    ) {
        $this->boersen ??= new BoerseRepository($pdo);
    }

    public static function freiImMagazin(int $frei): string
    {
        return 'Im Magazin sind nur noch ' . max(0, $frei) . ' Stück frei.';
    }

    /**
     * Legt ein Angebot an. Die Nummer gehört zum Jahr von $jetzt. Aus einem Magazinartikel: höchstens die freie Menge
     * (Bestand minus offene Angebote); aus einer Sammlung: Menge 1, nur wenn die Sammlung nicht schon offen angeboten
     * ist und keiner ihrer Artikel einzeln. Fotos des Artikels bzw. der Sammlung werden dem Angebot zugeordnet.
     *
     * @param array<string,mixed> $d vom AngebotValidator, dazu optional magazin_artikel_id, magazin_sammlung_id und
     *                               oeffentlich (gewünschter Wert: vorgeschlagen bzw. freigegeben)
     * @throws HttpFehler 403 für ein Vereinsangebot ohne Börsenwart
     * @throws DomainFehler Bestand, Sammlung, Börse abgeschlossen, Sperrkonflikt; nichts gespeichert
     */
    public function anlegen(array $d, Benutzer $b, \DateTimeImmutable $jetzt): int
    {
        $boersenwart = Berechtigung::istBoersenwart($b);
        $artikelId = isset($d['magazin_artikel_id']) ? (int) $d['magazin_artikel_id'] : null;
        $sammlungId = isset($d['magazin_sammlung_id']) ? (int) $d['magazin_sammlung_id'] : null;
        if ($artikelId !== null || $sammlungId !== null) {
            $d['herkunft'] = 'verein';
        }
        if (($d['herkunft'] ?? '') === 'verein' && !$boersenwart) {
            throw new HttpFehler(403);
        }
        if (!$boersenwart && (int) ($d['person_id'] ?? 0) !== $b->personId) {
            throw new HttpFehler(403);
        }
        if ($artikelId !== null && $sammlungId !== null) {
            throw new \InvalidArgumentException('Ein Angebot kommt aus einem Artikel oder aus einer Sammlung.');
        }
        if ($d['herkunft'] === 'verein') {
            $d['person_id'] = null;
            $d['erloes_spende'] = 0;
        }
        $wunsch = (string) ($d['oeffentlich'] ?? 'nein');
        $d['oeffentlich'] = $wunsch !== 'nein' && Freigabe::erlaubt('nein', $wunsch, $boersenwart, (string) $d['herkunft']) ? $wunsch : 'nein';

        return Transaktion::readCommitted($this->pdo, function () use ($d, $b, $jetzt, $artikelId, $sammlungId): int {
            if ($d['boerse_id'] !== null) {
                $this->sperreBoersen([(int) $d['boerse_id']]);
                $this->verlangeOffeneBoerse((int) $d['boerse_id']);
            }
            $artikelFotos = [];
            if ($sammlungId !== null) {
                $artikelFotos = $this->pruefeSammlung($sammlungId, null);
                $d['menge'] = 1;
            } elseif ($artikelId !== null) {
                $this->pruefeArtikel($artikelId, (int) $d['menge'], null);
                $artikelFotos = [$artikelId];
            }
            $d['jahr'] = (int) $jetzt->format('Y');
            $d['laufnummer'] = $this->rep->naechsteLaufnummer($d['jahr']);
            $d['magazin_artikel_id'] = $artikelId;
            $d['magazin_sammlung_id'] = $sammlungId;
            $id = $this->rep->anlegen($d, $b->id);
            if ($artikelFotos !== []) {
                $this->rep->uebernimmFotos($id, $artikelFotos);
            }
            $felder = array_intersect_key($d, array_flip([...AngebotRepository::FELDER, 'herkunft', 'magazin_artikel_id', 'magazin_sammlung_id', 'oeffentlich']));
            $felder['nummer'] = Angebotsnummer::formatiere($d['jahr'], $d['laufnummer']);
            $this->protokoll->schreibe($b->id, self::OBJEKT, $id, 'angelegt', Aenderungsprotokoll::diff([], $felder));
            return $id;
        });
    }

    /**
     * Ändert ein Angebot (Stand-Schutz). Ändert ein Mitglied ein freigegebenes Angebot (öffentlich sichtbare Felder),
     * fällt es auf „vorgeschlagen“ zurück; der Börsenwart ändert ohne Rückfall.
     *
     * @param array<string,mixed> $d vom AngebotValidator
     * @return bool true bei Rückfall auf „vorgeschlagen“
     * @throws HttpFehler 403 ohne Recht am Angebot
     * @throws \Emc\Fahrzeuge\KonfliktFehler bei veraltetem Stand
     */
    public function aendern(int $id, array $d, string $stand, Benutzer $b): bool
    {
        return Transaktion::readCommitted($this->pdo, function () use ($id, $d, $stand, $b): bool {
            $alt = $this->sperreAngebot($id, $d['boerse_id'] === null ? [] : [(int) $d['boerse_id']]);
            if (!Berechtigung::darfAngebotBearbeiten($b, $alt)) {
                throw new HttpFehler(403);
            }
            $this->verlangeAenderbar($alt);
            $boersenwart = Berechtigung::istBoersenwart($b);
            if ($d['boerse_id'] !== null && (int) $d['boerse_id'] !== (int) ($alt['boerse_id'] ?? 0)) {
                $this->verlangeOffeneBoerse((int) $d['boerse_id']);
            }
            // Herkunft und Magazinbezug bleiben; die Person wählt nur der Börsenwart (bei Mitgliedsangeboten).
            if ($alt['herkunft'] === 'verein') {
                $d['person_id'] = null;
                $d['erloes_spende'] = 0;
            } elseif (!$boersenwart || ($d['person_id'] ?? null) === null) {
                $d['person_id'] = $alt['person_id'] === null ? null : (int) $alt['person_id'];
            }
            if ($alt['magazin_sammlung_id'] !== null) {
                $d['menge'] = 1;
            } elseif ($alt['magazin_artikel_id'] !== null && in_array($alt['status'], AngebotRepository::OFFEN, true) && (int) $d['menge'] > (int) $alt['menge']) {
                $this->pruefeArtikel((int) $alt['magazin_artikel_id'], (int) $d['menge'], $id);
            }
            $this->rep->aktualisieren($id, $d, $stand);
            $neu = Freigabe::nachAenderung((string) $alt['oeffentlich'], $boersenwart, Freigabe::inhaltGeaendert($alt, $d));
            $vergleich = array_intersect_key($alt, array_flip(AngebotRepository::FELDER));
            $diff = Aenderungsprotokoll::diff($vergleich, array_intersect_key($d, array_flip(AngebotRepository::FELDER)));
            if ($neu !== $alt['oeffentlich']) {
                $this->rep->setzeOeffentlich($id, $neu);
                $diff['oeffentlich'] = ['alt' => $alt['oeffentlich'], 'neu' => $neu];
            }
            if ($diff !== []) {
                $this->protokoll->schreibe($b->id, self::OBJEKT, $id, 'geaendert', $diff);
            }
            return $neu !== $alt['oeffentlich'];
        });
    }

    /**
     * Prüft vor dem Hochladen eines Fotos: Recht am Angebot, nicht verkauft, Börse nicht abgeschlossen.
     *
     * @return array<string,mixed> das Angebot (aus AngebotRepository::finde)
     * @throws HttpFehler 403 bzw. 404
     * @throws DomainFehler verkauft oder Börse abgeschlossen
     */
    public function pruefeAenderbar(int $id, Benutzer $b): array
    {
        $a = $this->rep->finde($id) ?? throw new HttpFehler(404);
        if (!Berechtigung::darfAngebotBearbeiten($b, $a)) {
            throw new HttpFehler(403);
        }
        $this->verlangeAenderbar($a);
        return $a;
    }

    /** @return ?array<string,mixed> Angebot aus AngebotRepository::finde (für Rechteprüfungen außerhalb der Börse) */
    public function angebot(int $id): ?array
    {
        return $this->rep->finde($id);
    }

    /**
     * Löst ein geteiltes Foto nur vom Angebot (das Dokument bleibt am Magazinartikel). War es das Titelbild, rückt das
     * älteste verbliebene Foto nach. Rückfall der Freigabe (durch ein Mitglied) in derselben Transaktion.
     *
     * @return bool true bei Rückfall auf „vorgeschlagen“
     * @throws HttpFehler 403 ohne Recht am Angebot
     * @throws DomainFehler verkauft, Börse abgeschlossen, Sperrkonflikt; nichts geändert
     */
    public function loeseFoto(int $id, int $dokumentId, Benutzer $b): bool
    {
        return Transaktion::readCommitted($this->pdo, function () use ($id, $dokumentId, $b): bool {
            $a = $this->sperreAngebot($id, []);
            if (!Berechtigung::darfAngebotBearbeiten($b, $a)) {
                throw new HttpFehler(403);
            }
            $this->verlangeAenderbar($a);
            $rueckfall = $this->rueckfallNachFoto($a, $b);
            $titel = $this->rep->loeseFoto($id, $dokumentId, (int) ($a['titelbild_dokument_id'] ?? 0) === $dokumentId);
            if ($titel !== null) {
                $this->protokoll->schreibe($b->id, self::OBJEKT, $id, 'geaendert', ['datei' => ['alt' => $titel, 'neu' => null]]);
            }
            return $rueckfall;
        });
    }

    /**
     * VOR dem Hinzufügen oder Löschen eines Fotos aufrufen: durch ein Mitglied fällt ein freigegebenes Angebot auf
     * „vorgeschlagen“ zurück, und der Stand (geaendert_am) ändert sich – eine Freigabe auf einer vorher geöffneten Seite
     * scheitert dann am Stand-Schutz. So bleibt kein ungeprüftes Foto an einem freigegebenen Angebot, auch wenn das
     * Speichern danach scheitert (dann ist der Rückfall harmlos). Prüft Recht und Änderbarkeit unter der Sperre.
     *
     * @return bool true bei Rückfall
     * @throws HttpFehler 403 ohne Recht am Angebot
     * @throws DomainFehler verkauft, Börse abgeschlossen, Sperrkonflikt
     */
    public function fotoGeaendert(int $id, Benutzer $b): bool
    {
        return Transaktion::readCommitted($this->pdo, function () use ($id, $b): bool {
            $a = $this->sperreAngebot($id, []);
            if (!Berechtigung::darfAngebotBearbeiten($b, $a)) {
                throw new HttpFehler(403);
            }
            $this->verlangeAenderbar($a);
            return $this->rueckfallNachFoto($a, $b);
        });
    }

    /** @param array<string,mixed> $a gesperrtes Angebot */
    private function rueckfallNachFoto(array $a, Benutzer $b): bool
    {
        if (Berechtigung::istBoersenwart($b)) {
            return false;
        }
        $id = (int) $a['id'];
        $this->rep->beruehre($id);
        if ($a['oeffentlich'] !== 'freigegeben') {
            return false;
        }
        $this->rep->setzeOeffentlich($id, 'vorgeschlagen');
        $this->protokoll->schreibe($b->id, self::OBJEKT, $id, 'geaendert', ['oeffentlich' => ['alt' => 'freigegeben', 'neu' => 'vorgeschlagen']]);
        return true;
    }

    /**
     * Veröffentlichung: Mitglied schlägt vor bzw. zieht zurück, der Börsenwart gibt frei oder nimmt heraus.
     *
     * @param ?string $stand Stand (geaendert_am), den der Börsenwart beim Freigeben gesehen hat; bei „freigegeben“ muss
     *                       er stimmen, sonst KonfliktFehler (null: ohne Prüfung, nur für Dienste/Beispieldaten)
     * @throws HttpFehler 403, wenn der Übergang nicht erlaubt ist
     * @throws KonfliktFehler Freigabe auf veraltetem Stand
     */
    public function setzeOeffentlich(int $id, string $nach, Benutzer $b, ?string $stand = null): void
    {
        Transaktion::readCommitted($this->pdo, function () use ($id, $nach, $b, $stand): void {
            $a = $this->sperreAngebot($id, []);
            $boersenwart = Berechtigung::istBoersenwart($b);
            if (!Berechtigung::darfAngebotBearbeiten($b, $a) || !Freigabe::erlaubt((string) $a['oeffentlich'], $nach, $boersenwart, (string) $a['herkunft'])) {
                throw new HttpFehler(403);
            }
            $this->verlangeAenderbar($a);
            if ($nach === 'freigegeben' && $stand !== null && $stand !== (string) $a['geaendert_am']) {
                // Inzwischen geändert (Text, Preis, Fotos): nichts Ungesehenes freigeben.
                throw new KonfliktFehler(AngebotRepository::KONFLIKT);
            }
            $this->rep->setzeOeffentlich($id, $nach);
            $this->protokoll->schreibe($b->id, self::OBJEKT, $id, 'geaendert', ['oeffentlich' => ['alt' => $a['oeffentlich'], 'neu' => $nach]]);
        });
    }

    /**
     * Statuswechsel von Hand: zurueckziehen (Mitglied eigenes / Börsenwart), reservieren und reservierung_aufheben
     * (Börsenwart), wieder_anbieten (Börsenwart, zurueck → angeboten, optional in eine andere Börse: $neueBoerse '' oder
     * „dauerhaft“ = dauerhaft, sonst ID). „verkauft“ setzt nur die Kasse.
     *
     * @throws HttpFehler 403 ohne Recht
     * @throws DomainFehler unpassender Status, Börse abgeschlossen, Bestand; nichts geändert
     */
    public function setzeStatus(int $id, string $aktion, Benutzer $b, ?string $neueBoerse = null): void
    {
        if (!in_array($aktion, self::AKTIONEN, true)) {
            throw new DomainFehler(self::AKTION_UNBEKANNT);
        }
        $boersenwart = Berechtigung::istBoersenwart($b);
        if ($aktion !== 'zurueckziehen' && !$boersenwart) {
            throw new HttpFehler(403);
        }
        $zielBoerse = false; // false: Börse bleibt
        if ($aktion === 'wieder_anbieten' && $neueBoerse !== null) {
            $neueBoerse = trim($neueBoerse);
            if ($neueBoerse === '' || $neueBoerse === 'dauerhaft') {
                $zielBoerse = null;
            } elseif (preg_match('/^\d{1,10}$/', $neueBoerse) === 1 && (int) $neueBoerse > 0) {
                $zielBoerse = (int) $neueBoerse;
            } else {
                throw new DomainFehler(AngebotValidator::KEINE_BOERSE);
            }
        }
        Transaktion::readCommitted($this->pdo, function () use ($id, $aktion, $b, $zielBoerse): void {
            $a = $this->sperreAngebot($id, is_int($zielBoerse) ? [$zielBoerse] : []);
            if (!Berechtigung::darfAngebotBearbeiten($b, $a)) {
                throw new HttpFehler(403);
            }
            if ($a['status'] === 'verkauft') {
                throw new DomainFehler(self::VERKAUFT);
            }
            $alt = (string) $a['status'];
            $boerseNeu = $a['boerse_id'] === null ? null : (int) $a['boerse_id'];
            switch ($aktion) {
                case 'zurueckziehen':
                    $this->verlangeAenderbar($a);
                    if (!in_array($alt, AngebotRepository::OFFEN, true)) {
                        throw new DomainFehler('Das Angebot ist schon zurück.');
                    }
                    $neu = 'zurueck';
                    break;
                case 'reservieren':
                    $this->verlangeAenderbar($a);
                    if ($alt !== 'angeboten') {
                        throw new DomainFehler('Nur angebotene Stücke lassen sich reservieren.');
                    }
                    $neu = 'reserviert';
                    break;
                case 'reservierung_aufheben':
                    $this->verlangeAenderbar($a);
                    if ($alt !== 'reserviert') {
                        throw new DomainFehler('Das Angebot ist nicht reserviert.');
                    }
                    $neu = 'angeboten';
                    break;
                default: // wieder_anbieten
                    if ($alt !== 'zurueck') {
                        throw new DomainFehler('Nur zurückgegebene Angebote lassen sich wieder anbieten.');
                    }
                    if ($zielBoerse !== false) {
                        $boerseNeu = $zielBoerse;
                    }
                    if ($boerseNeu !== null) {
                        $this->verlangeOffeneBoerse($boerseNeu, true);
                    }
                    if ($a['magazin_sammlung_id'] !== null) {
                        $this->pruefeSammlung((int) $a['magazin_sammlung_id'], $id);
                    } elseif ($a['magazin_artikel_id'] !== null) {
                        $this->pruefeArtikel((int) $a['magazin_artikel_id'], (int) $a['menge'], $id);
                    }
                    $neu = 'angeboten';
            }
            $boerseGeaendert = $boerseNeu !== ($a['boerse_id'] === null ? null : (int) $a['boerse_id']);
            $this->rep->setzeStatus($id, $neu, $boerseNeu, $boerseGeaendert);
            $diff = ['status' => ['alt' => $alt, 'neu' => $neu]];
            if ($boerseGeaendert) {
                $diff['boerse_id'] = ['alt' => $a['boerse_id'], 'neu' => $boerseNeu];
            }
            $this->protokoll->schreibe($b->id, self::OBJEKT, $id, 'geaendert', $diff);
        });
    }

    // ---- Hilfen (nur in der laufenden Transaktion) ------------------------------------------------------------------

    /**
     * Sperrt die Börse(n) des Angebots und weitere (nach id aufsteigend), danach das Angebot selbst.
     *
     * @param list<int> $weitereBoersen
     * @return array<string,mixed> das gesperrte Angebot
     */
    private function sperreAngebot(int $id, array $weitereBoersen): array
    {
        $boerse = $this->rep->boerseVon($id);
        $this->sperreBoersen([...($boerse === null ? [] : [$boerse]), ...$weitereBoersen]);
        $a = $this->rep->sperre($id) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
        if (($a['boerse_id'] === null ? null : (int) $a['boerse_id']) !== $boerse) {
            // Inzwischen in eine andere Börse verschoben: deren Sperre fehlt – lieber noch einmal versuchen lassen.
            throw new DomainFehler(Transaktion::SPERRKONFLIKT);
        }
        $a['boerse_status'] = $boerse === null ? null : ($this->gesperrteBoersen[$boerse]['status'] ?? null);
        return $a;
    }

    /** @var array<int, array<string,mixed>> in dieser Transaktion gesperrte Börsen */
    private array $gesperrteBoersen = [];

    /** @param list<int> $ids */
    private function sperreBoersen(array $ids): void
    {
        $this->gesperrteBoersen = [];
        $ids = array_values(array_unique($ids));
        sort($ids);
        foreach ($ids as $id) {
            $z = $this->boersen->sperre($id);
            if ($z !== null) {
                $this->gesperrteBoersen[$id] = $z;
            }
        }
    }

    /** Börse gesperrt, vorhanden und nicht abgeschlossen. */
    private function verlangeOffeneBoerse(int $id, bool $mitHinweis = false): void
    {
        $z = $this->gesperrteBoersen[$id] ?? throw new DomainFehler(self::BOERSE_FEHLT);
        if ($z['status'] === 'abgeschlossen') {
            throw new DomainFehler($mitHinweis ? 'Die Börse ist abgeschlossen. Bitte eine offene Börse wählen oder „dauerhaft“.' : self::BOERSE_ABGESCHLOSSEN);
        }
    }

    /** @param array<string,mixed> $a Angebot (mit boerse_status) */
    private function verlangeAenderbar(array $a): void
    {
        if ($a['status'] === 'verkauft') {
            throw new DomainFehler(self::VERKAUFT);
        }
        if (($a['boerse_status'] ?? null) === 'abgeschlossen') {
            throw new DomainFehler(self::BOERSE_ABGESCHLOSSEN);
        }
    }

    /**
     * Sperrt Sammlung (falls der Artikel in einer ist) und Artikel; prüft die freie Menge.
     *
     * @param ?int $ohneAngebot dieses Angebot nicht als gebunden zählen
     */
    private function pruefeArtikel(int $artikelId, int $menge, ?int $ohneAngebot): void
    {
        $sammlung = $this->magazin->sammlungVonArtikel($artikelId);
        if ($sammlung !== null) {
            $this->magazin->sperreSammlung($sammlung['id']);
        }
        $this->magazin->sperreArtikel($artikelId) ?? throw new DomainFehler(MagazinDienst::NICHT_GEFUNDEN);
        $sammlung = $this->magazin->sammlungVonArtikel($artikelId);
        if ($sammlung !== null && $this->rep->offenFuerSammlung($sammlung['id'])) {
            throw new DomainFehler(Code::magazin($artikelId) . ' gehört zur Sammlung „' . $sammlung['name'] . '“, die schon im Angebot ist.');
        }
        $frei = $this->magazin->bestand($artikelId) - $this->rep->offeneMengeFuerArtikel($artikelId, $ohneAngebot);
        if ($menge > $frei) {
            throw new DomainFehler(self::freiImMagazin($frei));
        }
    }

    /**
     * Sperrt die Sammlung und ihre Artikel (nach id); die Sammlung darf nicht schon offen angeboten sein, kein Artikel
     * einzeln, und es muss Bestand geben.
     *
     * @return list<int> Artikel der Sammlung
     */
    private function pruefeSammlung(int $sammlungId, ?int $ohneAngebot): array
    {
        $this->magazin->sperreSammlung($sammlungId) ?? throw new DomainFehler(self::SAMMLUNG_FEHLT);
        if ($this->rep->offenFuerSammlung($sammlungId, $ohneAngebot)) {
            throw new DomainFehler(self::SAMMLUNG_SCHON);
        }
        $artikelIds = $this->magazin->artikelDerSammlung($sammlungId);
        if ($artikelIds === []) {
            throw new DomainFehler(MagazinDienst::SAMMLUNG_LEER);
        }
        $bestand = 0;
        foreach ($artikelIds as $artikelId) {
            $this->magazin->sperreArtikel($artikelId);
            if ($this->rep->offeneMengeFuerArtikel($artikelId) > 0) {
                throw new DomainFehler(Code::magazin($artikelId) . ' ist schon einzeln im Angebot.');
            }
            $bestand += $this->magazin->bestand($artikelId);
        }
        if ($bestand <= 0) {
            throw new DomainFehler(self::SAMMLUNG_OHNE_BESTAND);
        }
        return $artikelIds;
    }
}
