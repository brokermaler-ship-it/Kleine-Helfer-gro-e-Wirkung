<?php

declare(strict_types=1);

namespace Emc\Magazin;

use Emc\Codes\Code;
use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturValidator;

/**
 * Alle Schreibungen, die den Bestand eines Magazinartikels ändern (Eingang, Ausgang, Gegenbuchung, Umlagerung), und
 * Änderungen an Sammlungen. Jede läuft in einer eigenen Transaktion READ COMMITTED (Transaktion::readCommitted) und
 * sperrt zuerst das Elternobjekt: Sammlung vor Artikeln, Artikel nach id aufsteigend. Erst nach der Sperre wird der
 * Bestand gelesen – so sieht jede Prüfung die Buchungen, die andere inzwischen festgeschrieben haben, und der Bestand
 * wird nie negativ. Die *InLaufenderTransaktion-Methoden sind für Aufrufer mit eigener Transaktion (Kasse, Storno,
 * Übergabe vom Abstellgleis) und sperren den Artikel selbst.
 */
final class MagazinDienst
{
    public const OBJEKT_ARTIKEL = 'magazin_artikel';
    public const OBJEKT_SAMMLUNG = 'magazin_sammlung';
    public const NICHT_GEGENBUCHBAR = 'Diese Buchung lässt sich nicht gegenbuchen.';
    public const NICHT_GEFUNDEN = 'Diesen Artikel gibt es nicht mehr.';
    /** Alle Ausgangsarten der Datenbank (das Formular bietet verkauft_boerse nicht an). */
    private const AUSGANG_ARTEN = ['gegen_spende', 'verkauft_boerse', 'verschrottet', 'ausgeliehen', 'sonstiges'];

    public function __construct(
        private \PDO $pdo,
        private MagazinRepository $rep,
        private Aenderungsprotokoll $protokoll,
    ) {
    }

    /**
     * Legt den Artikel und seine erste Eingangsbuchung in einer Transaktion an.
     *
     * @param array<string,mixed> $artikel Felder aus MagazinRepository::FELDER und lagerort (geprüft vom ArtikelValidator)
     * @param array<string,mixed> $eingang datum, menge, herkunft, freitext, betrag (geprüft vom BuchungValidator)
     */
    public function legeAnMitEingang(array $artikel, array $eingang, ?int $benutzerId): int
    {
        return Transaktion::readCommitted($this->pdo, fn(): int => $this->legeAnMitEingangInLaufenderTransaktion($artikel, $eingang, $benutzerId));
    }

    /**
     * Wie legeAnMitEingang, aber in der Transaktion des Aufrufers (z. B. Übernahme einer Börsenanfrage).
     *
     * @param array<string,mixed> $artikel
     * @param array<string,mixed> $eingang
     */
    public function legeAnMitEingangInLaufenderTransaktion(array $artikel, array $eingang, ?int $benutzerId): int
    {
        Transaktion::verlangeLaufende($this->pdo);
        $menge = (int) ($eingang['menge'] ?? 0);
        if ($menge < 1) {
            throw new DomainFehler(BuchungValidator::KEINE_MENGE);
        }
        $id = $this->rep->anlegen($artikel, $benutzerId);
        $this->rep->sperreArtikel($id);
        $felder = array_intersect_key($artikel, array_flip([...MagazinRepository::FELDER, 'lagerort', 'fahrzeug_id']));
        $this->protokoll->schreibe($benutzerId, self::OBJEKT_ARTIKEL, $id, 'angelegt', Aenderungsprotokoll::diff([], $felder));
        $this->schreibeBuchung($id, [
            'art' => 'eingang',
            'datum' => $eingang['datum'] ?? date('Y-m-d'),
            'menge' => $menge,
            'herkunft' => $eingang['herkunft'] ?? 'sonstiges',
            'freitext' => $eingang['freitext'] ?? null,
            'betrag' => $eingang['betrag'] ?? null,
        ], $benutzerId);
        return $id;
    }

    /**
     * Bucht Eingang, Ausgang oder Umlagerung (Daten geprüft vom BuchungValidator). Ein Ausgang über den Bestand wird
     * abgelehnt; verkauft_boerse bucht nur die Kasse.
     *
     * @param array<string,mixed> $d datum, menge, herkunft, ausgang_art, freitext, betrag, lagerort_neu
     * @return int ID der Buchung
     * @throws DomainFehler „Der Bestand reicht nicht: verfügbar sind nur <n>.“, Sperrkonflikt u. a.; nichts gebucht
     */
    public function buche(int $artikelId, string $art, array $d, ?int $benutzerId): int
    {
        if (!in_array($art, BuchungValidator::ARTEN, true)) {
            throw new DomainFehler('Bitte eine Buchungsart wählen.');
        }
        if ($art === 'ausgang' && !in_array($d['ausgang_art'] ?? null, BuchungValidator::AUSGANG_ARTEN, true)) {
            throw new DomainFehler(BuchungValidator::KEINE_AUSGANG_ART);
        }
        if ($art === 'eingang' && !in_array($d['herkunft'] ?? null, BuchungValidator::HERKUNFT, true)) {
            throw new DomainFehler(BuchungValidator::KEINE_HERKUNFT);
        }
        return Transaktion::readCommitted($this->pdo, function () use ($artikelId, $art, $d, $benutzerId): int {
            $artikel = $this->rep->sperreArtikel($artikelId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            $datum = (string) ($d['datum'] ?? date('Y-m-d'));
            if ($art === 'umlagerung') {
                return $this->lagereUm($artikel, (string) ($d['lagerort_neu'] ?? ''), $datum, $benutzerId);
            }
            $menge = (int) ($d['menge'] ?? 0);
            if ($menge < 1) {
                throw new DomainFehler(BuchungValidator::KEINE_MENGE);
            }
            if ($art === 'ausgang') {
                $this->pruefeBestand($artikelId, $menge);
            }
            return $this->schreibeBuchung($artikelId, [
                'art' => $art,
                'datum' => $datum,
                'menge' => $art === 'ausgang' ? -$menge : $menge,
                'herkunft' => $art === 'eingang' ? $d['herkunft'] : null,
                'ausgang_art' => $art === 'ausgang' ? $d['ausgang_art'] : null,
                'freitext' => $d['freitext'] ?? null,
                'betrag' => $d['betrag'] ?? null,
            ], $benutzerId);
        });
    }

    /**
     * Korrigiert eine Eingangs- oder Ausgangsbuchung durch eine Gegenbuchung (gleiche Art, Menge negiert). Nicht für
     * Umlagerungen, Gegenbuchungen, schon gegengebuchte Buchungen und Verkäufe der Kasse (die storniert die Kasse).
     *
     * @return int ID der Gegenbuchung
     */
    public function gegenbuchung(int $buchungId, ?int $benutzerId): int
    {
        return Transaktion::readCommitted($this->pdo, fn(): int => $this->bucheGegen($buchungId, $benutzerId, false));
    }

    /**
     * Gegenbuchung in der Transaktion des Aufrufers (Storno der Kasse): auch für Buchungen mit verkauf_position_id.
     *
     * @throws \LogicException ohne laufende Transaktion
     */
    public function gegenbuchungInLaufenderTransaktion(int $buchungId, ?int $benutzerId): int
    {
        Transaktion::verlangeLaufende($this->pdo);
        return $this->bucheGegen($buchungId, $benutzerId, true);
    }

    /**
     * Ausgang in der Transaktion des Aufrufers (Kasse): sperrt den Artikel, prüft den Bestand wie buche().
     *
     * @param ?string $betrag DECIMAL als Zeichenkette, z. B. „12.50“
     * @throws \LogicException ohne laufende Transaktion
     */
    public function ausgangInLaufenderTransaktion(int $artikelId, int $menge, string $ausgangArt, ?string $betrag, ?string $freitext, ?int $positionId, ?int $benutzerId, \DateTimeImmutable $jetzt): int
    {
        Transaktion::verlangeLaufende($this->pdo);
        if (!in_array($ausgangArt, self::AUSGANG_ARTEN, true)) {
            throw new DomainFehler(BuchungValidator::KEINE_AUSGANG_ART);
        }
        if ($menge < 1) {
            throw new DomainFehler(BuchungValidator::KEINE_MENGE);
        }
        if ($betrag !== null && preg_match('/^\d{1,8}\.\d{2}$/', $betrag) !== 1) {
            throw new \InvalidArgumentException('Betrag als DECIMAL-Zeichenkette erwartet, z. B. 12.50.');
        }
        $this->rep->sperreArtikel($artikelId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
        $this->pruefeBestand($artikelId, $menge);
        return $this->schreibeBuchung($artikelId, [
            'art' => 'ausgang',
            'datum' => $jetzt->format('Y-m-d'),
            'menge' => -$menge,
            'ausgang_art' => $ausgangArt,
            'freitext' => $freitext === null ? null : mb_substr($freitext, 0, BuchungValidator::FREITEXT_MAX),
            'betrag' => $betrag,
            'verkauf_position_id' => $positionId,
        ], $benutzerId);
    }

    /**
     * Lagert alle Artikel der Sammlung um: Sammlung FOR UPDATE, dann die Artikel nach id; je Artikel eine Umlagerung.
     *
     * @return int Anzahl umgelagerter Artikel
     */
    public function umlagereSammlung(int $sammlungId, string $lagerort, ?int $benutzerId): int
    {
        $lagerort = trim($lagerort);
        if ($lagerort === '') {
            throw new DomainFehler(BuchungValidator::KEIN_LAGERORT);
        }
        return Transaktion::readCommitted($this->pdo, function () use ($sammlungId, $lagerort, $benutzerId): int {
            $this->rep->sperreSammlung($sammlungId) ?? throw new DomainFehler('Diese Sammlung gibt es nicht mehr.');
            $n = 0;
            foreach ($this->rep->artikelDerSammlung($sammlungId) as $artikelId) {
                $artikel = $this->rep->sperreArtikel($artikelId);
                if ($artikel !== null) {
                    $this->lagereUm($artikel, $lagerort, date('Y-m-d'), $benutzerId);
                    $n++;
                }
            }
            $this->protokoll->schreibe($benutzerId, self::OBJEKT_SAMMLUNG, $sammlungId, 'geaendert', [
                'lagerort' => ['alt' => null, 'neu' => $lagerort . ' (' . $n . ($n === 1 ? ' Artikel)' : ' Artikel)')],
            ]);
            return $n;
        });
    }

    /**
     * Legt eine Sammlung samt Artikeln an.
     *
     * @param list<int> $artikelIds
     */
    public function legeSammlungAn(string $name, ?string $notiz, array $artikelIds, ?int $benutzerId): int
    {
        return Transaktion::readCommitted($this->pdo, function () use ($name, $notiz, $artikelIds, $benutzerId): int {
            $id = $this->rep->sammlungAnlegen($name, $notiz);
            $stand = (string) $this->rep->sperreSammlung($id)['geaendert_am'];
            $this->setzeSammlung($id, $name, $notiz, $artikelIds, $stand);
            $this->protokoll->schreibe($benutzerId, self::OBJEKT_SAMMLUNG, $id, 'angelegt', Aenderungsprotokoll::diff([], [
                'name' => $name, 'notiz' => $notiz, 'artikel' => $this->codes($artikelIds),
            ]));
            return $id;
        });
    }

    /**
     * Ändert Name, Notiz und Artikel einer Sammlung (Stand-Schutz). Ein Artikel gehört höchstens zu einer Sammlung.
     *
     * @param list<int> $artikelIds
     * @throws \Emc\Fahrzeuge\KonfliktFehler bei veraltetem Stand
     */
    public function aktualisiereSammlung(int $id, string $name, ?string $notiz, array $artikelIds, string $stand, ?int $benutzerId): void
    {
        Transaktion::readCommitted($this->pdo, function () use ($id, $name, $notiz, $artikelIds, $stand, $benutzerId): void {
            $alt = $this->rep->sperreSammlung($id) ?? throw new \Emc\Fahrzeuge\KonfliktFehler(MagazinRepository::KONFLIKT_SAMMLUNG);
            $altArtikel = $this->rep->artikelDerSammlung($id);
            $this->setzeSammlung($id, $name, $notiz, $artikelIds, $stand);
            $diff = Aenderungsprotokoll::diff(
                ['name' => $alt['name'], 'notiz' => $alt['notiz'], 'artikel' => $this->codes($altArtikel)],
                ['name' => $name, 'notiz' => $notiz, 'artikel' => $this->codes($artikelIds)]
            );
            if ($diff !== []) {
                $this->protokoll->schreibe($benutzerId, self::OBJEKT_SAMMLUNG, $id, 'geaendert', $diff);
            }
        });
    }

    /**
     * Text einer Buchung für Protokoll und Anzeige, z. B. „Eingang +5 (Spende)“, „Ausgang −3 (verschrottet)“,
     * „Umlagerung → Regal 3“.
     *
     * @param array<string,mixed> $b
     */
    public static function buchungText(array $b): string
    {
        $menge = (int) $b['menge'];
        return match ((string) $b['art']) {
            'umlagerung' => 'Umlagerung → ' . (string) $b['lagerort_neu'],
            'eingang' => 'Eingang ' . self::mitVorzeichen($menge) . ' (' . (MagazinLabels::HERKUNFT[(string) $b['herkunft']] ?? (string) $b['herkunft']) . ')',
            default => 'Ausgang ' . self::mitVorzeichen($menge) . ' (' . (MagazinLabels::AUSGANG[(string) $b['ausgang_art']] ?? (string) $b['ausgang_art']) . ')',
        } . (($b['betrag'] ?? null) !== null ? ', ' . ReparaturValidator::betrag((string) $b['betrag']) : '')
          . (($b['gegenbuchung_zu_id'] ?? null) !== null ? ', Gegenbuchung zu Nr. ' . (int) $b['gegenbuchung_zu_id'] : '');
    }

    /** „+5“, „−3“ (echtes Minuszeichen), „0“. */
    public static function mitVorzeichen(int $menge): string
    {
        return $menge > 0 ? '+' . $menge : ($menge < 0 ? '−' . abs($menge) : '0');
    }

    private function bucheGegen(int $buchungId, ?int $benutzerId, bool $ausKasse): int
    {
        $vorab = $this->rep->buchung($buchungId) ?? throw new DomainFehler(self::NICHT_GEGENBUCHBAR);
        $artikelId = (int) $vorab['artikel_id'];
        $this->rep->sperreArtikel($artikelId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
        // Nach der Sperre neu lesen (READ COMMITTED): eine gleichzeitige Gegenbuchung ist jetzt sichtbar.
        $b = $this->rep->buchung($buchungId) ?? throw new DomainFehler(self::NICHT_GEGENBUCHBAR);
        if (
            !in_array($b['art'], ['eingang', 'ausgang'], true)
            || $b['gegenbuchung_zu_id'] !== null
            || $b['gegengebucht_durch_id'] !== null
            || (!$ausKasse && $b['verkauf_position_id'] !== null)
            || (int) $b['menge'] === 0
        ) {
            throw new DomainFehler(self::NICHT_GEGENBUCHBAR);
        }
        $menge = -(int) $b['menge'];
        if ($menge < 0) {
            $this->pruefeBestand($artikelId, -$menge);
        }
        try {
            return $this->schreibeBuchung($artikelId, [
                'art' => $b['art'],
                'datum' => date('Y-m-d'),
                'menge' => $menge,
                'herkunft' => $b['herkunft'],
                'ausgang_art' => $b['ausgang_art'],
                'freitext' => 'Gegenbuchung zu Buchung Nr. ' . $buchungId,
                'betrag' => $b['betrag'],
                'gegenbuchung_zu_id' => $buchungId,
            ], $benutzerId);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_magazin_buchung_gegenbuchung')) {
                throw new DomainFehler(self::NICHT_GEGENBUCHBAR, 0, $e);
            }
            throw $e;
        }
    }

    /** Nach der Sperre des Artikels: reicht der Bestand für einen Ausgang von $menge? */
    private function pruefeBestand(int $artikelId, int $menge): void
    {
        $bestand = $this->rep->bestand($artikelId);
        if ($menge > $bestand) {
            throw new DomainFehler('Der Bestand reicht nicht: verfügbar sind nur ' . max(0, $bestand) . '.');
        }
    }

    /** @param array<string,mixed> $artikel gesperrte Zeile */
    private function lagereUm(array $artikel, string $lagerort, string $datum, ?int $benutzerId): int
    {
        $lagerort = trim($lagerort);
        if ($lagerort === '') {
            throw new DomainFehler(BuchungValidator::KEIN_LAGERORT);
        }
        $id = (int) $artikel['id'];
        $this->rep->setzeLagerort($id, $lagerort);
        $buchung = $this->rep->bucheZeile($id, ['art' => 'umlagerung', 'datum' => $datum, 'menge' => 0, 'lagerort_neu' => $lagerort], $benutzerId);
        $this->protokoll->schreibe($benutzerId, self::OBJEKT_ARTIKEL, $id, 'geaendert', [
            'lagerort' => ['alt' => $artikel['lagerort'], 'neu' => $lagerort],
        ]);
        return $buchung;
    }

    /** @param array<string,mixed> $z */
    private function schreibeBuchung(int $artikelId, array $z, ?int $benutzerId): int
    {
        $id = $this->rep->bucheZeile($artikelId, $z, $benutzerId);
        $this->protokoll->schreibe($benutzerId, self::OBJEKT_ARTIKEL, $artikelId, 'geaendert', [
            'buchung' => ['alt' => null, 'neu' => 'Nr. ' . $id . ': ' . self::buchungText($z)],
        ]);
        return $id;
    }

    /**
     * In der laufenden Transaktion, Sammlung bereits gesperrt: Artikel nach id sperren, Zugehörigkeit prüfen, speichern.
     *
     * @param list<int> $artikelIds
     */
    private function setzeSammlung(int $id, string $name, ?string $notiz, array $artikelIds, string $stand): void
    {
        $artikelIds = array_values(array_unique(array_map('intval', $artikelIds)));
        sort($artikelIds);
        foreach ($artikelIds as $artikelId) {
            $this->rep->sperreArtikel($artikelId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            $andere = $this->rep->sammlungVonArtikel($artikelId);
            if ($andere !== null && $andere['id'] !== $id) {
                throw new DomainFehler(Code::magazin($artikelId) . ' gehört schon zur Sammlung „' . $andere['name'] . '“.');
            }
        }
        try {
            $this->rep->sammlungAktualisieren($id, $name, $notiz, $artikelIds, $stand);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new DomainFehler('Ein Artikel gehört schon zu einer anderen Sammlung.', 0, $e);
            }
            throw $e;
        }
    }

    /**
     * @param list<int> $ids
     */
    private function codes(array $ids): ?string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids === [] ? null : implode(', ', array_map(Code::magazin(...), $ids));
    }
}
