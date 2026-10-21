<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturValidator;

/**
 * Kasse am Börsentag: Verkauf eines Warenkorbs und Storno am selben Tag.
 *
 * Kein Doppelverkauf: Jeder Abschluss läuft in einer Transaktion READ COMMITTED und sperrt in der festen Reihenfolge der
 * App – boerse → boerse_angebot (nach id aufsteigend) → magazin_sammlung (nach id) → magazin_artikel (nach id) – und
 * prüft Status, Börse und Bestand erst nach den Sperren. Zwei Kassen mit demselben Stück warten also aufeinander; die
 * zweite sieht danach „verkauft“ und speichert nichts (alle Meldungen gesammelt in KasseFehler). Die Magazin-Ausgänge
 * laufen über MagazinDienst::ausgangInLaufenderTransaktion, aufgerufen nach Artikel-id aufsteigend. Provision, Herkunft,
 * Person und Spende werden je Position beim Verkauf festgeschrieben.
 */
final class KasseDienst
{
    public const LAEUFT_NICHT = 'Die Börse läuft nicht – Verkauf nicht möglich.';
    public const LEER = 'Der Warenkorb ist leer.';
    public const KEINE_ZAHLART = 'Bitte die Zahlart wählen.';
    public const NUMMER_FEHLT = 'Bitte die Angebotsnummer eingeben.';
    public const BOERSE_FEHLT = 'Diese Börse gibt es nicht mehr.';
    public const ANGEBOT_FEHLT = 'Ein Stück im Warenkorb gibt es nicht mehr. Bitte entfernen.';
    public const KEIN_GRUND = 'Bitte einen Grund für das Storno angeben.';
    public const GRUND_ZU_LANG = 'Der Grund für das Storno darf höchstens 300 Zeichen lang sein.';
    public const NUR_SELBER_TAG = 'Stornieren geht nur am Tag des Verkaufs.';
    public const SCHON_STORNIERT = 'Der Verkauf ist schon storniert.';
    public const VERKAUF_FEHLT = 'Diesen Verkauf gibt es nicht.';
    public const AUSZAHLUNG_VERMERKT = 'Für ein Mitglied in diesem Verkauf ist schon eine Auszahlung vermerkt. Bitte den Haken zuerst entfernen.';
    public const STORNO_ANGEBOT_GEAENDERT = 'Die Sammlung bzw. der Artikel ist inzwischen anders im Angebot – Storno nicht möglich.';
    public const GRUND_MAX = 300;
    /** Zahlarten der Datenbank mit Anzeigetext. */
    public const ZAHLARTEN = ['bar' => 'bar', 'karte_ueberweisung' => 'Karte/Überweisung'];

    public function __construct(
        private \PDO $pdo,
        private AngebotRepository $angebote,
        private MagazinDienst $magazin,
        private Aenderungsprotokoll $protokoll,
        private ?BoerseRepository $boersen = null,
        private ?MagazinRepository $magazinRep = null,
        private ?VerkaufRepository $verkaeufe = null,
    ) {
        $this->boersen ??= new BoerseRepository($pdo);
        $this->magazinRep ??= new MagazinRepository($pdo);
        $this->verkaeufe ??= new VerkaufRepository($pdo);
    }

    public static function keinAngebot(string $eingabe): string
    {
        return 'Kein Angebot mit der Nummer ' . $eingabe . '.';
    }

    public static function bestandReichtNicht(string $nummer): string
    {
        return $nummer . ': Der Bestand im Magazin reicht nicht.';
    }

    /**
     * Ids eindeutig und aufsteigend – die Sperrreihenfolge innerhalb einer Tabelle (Angebote, Sammlungen, Artikel).
     *
     * @param array<int|string, int> $ids
     * @return list<int>
     */
    public static function aufsteigend(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }

    /**
     * Angebot für den Warenkorb nach Nummer: „118“ (Jahr des Börsendatums), „B2026-118“. Ohne Sperre – verbindlich prüft
     * erst abschliessen().
     *
     * @return array<string,mixed> Angebot aus AngebotRepository::finde
     * @throws KasseFehler unbekannte Nummer, verkauft, zurückgegeben, andere Börse, Börse läuft nicht
     */
    public function pruefeHinzu(int $boerseId, string $eingabe): array
    {
        $boerse = $this->boersen->finde($boerseId) ?? throw new KasseFehler([self::BOERSE_FEHLT]);
        if ($boerse['status'] !== 'laeuft') {
            throw new KasseFehler([self::LAEUFT_NICHT]);
        }
        $eingabe = trim($eingabe);
        if ($eingabe === '') {
            throw new KasseFehler([self::NUMMER_FEHLT]);
        }
        $nr = Angebotsnummer::zerlege($eingabe, (int) substr((string) $boerse['datum'], 0, 4));
        $a = $nr === null ? null : $this->angebote->findeNachNummer($nr['jahr'], $nr['lauf']);
        if ($a === null) {
            throw new KasseFehler([self::keinAngebot(mb_substr($eingabe, 0, 30))]);
        }
        $hindernis = self::hindernis($a, $boerseId);
        if ($hindernis !== null) {
            throw new KasseFehler([$hindernis], [(int) $a['id']]);
        }
        return $a;
    }

    /**
     * Schließt einen Verkauf ab (eine Transaktion, Sperrreihenfolge siehe Klasse). Bei jedem Hindernis wird nichts
     * gespeichert.
     *
     * @param list<array{angebot_id:int, preis:string}> $positionen Preise als DECIMAL-Zeichenkette („12.50“)
     * @return int ID des Verkaufs
     * @throws KasseFehler leer, Zahlart, Börse läuft nicht, verkauft/zurück/andere Börse, Bestand (alle Meldungen)
     * @throws DomainFehler Sperrkonflikt (Transaktion::SPERRKONFLIKT)
     */
    public function abschliessen(int $boerseId, array $positionen, string $zahlart, ?int $benutzerId, \DateTimeImmutable $jetzt): int
    {
        if ($positionen === []) {
            throw new KasseFehler([self::LEER]);
        }
        if (!isset(self::ZAHLARTEN[$zahlart])) {
            throw new KasseFehler([self::KEINE_ZAHLART]);
        }
        $preise = [];
        foreach ($positionen as $p) {
            $preis = (string) $p['preis'];
            if (!Warenkorb::istPreis($preis)) {
                throw new \InvalidArgumentException('Preis als DECIMAL-Zeichenkette erwartet, z. B. 12.50.');
            }
            $preise[(int) $p['angebot_id']] ??= $preis;
        }
        $jetzt = self::inAppZeit($jetzt);

        return Transaktion::readCommitted($this->pdo, function () use ($boerseId, $preise, $zahlart, $benutzerId, $jetzt): int {
            // 1. Börse
            $boerse = $this->boersen->sperre($boerseId) ?? throw new KasseFehler([self::BOERSE_FEHLT]);
            if ($boerse['status'] !== 'laeuft') {
                throw new KasseFehler([self::LAEUFT_NICHT]);
            }
            // 2. Angebote nach id; erst jetzt Status und Börse prüfen (eine gleichzeitige Kasse ist festgeschrieben).
            $gesperrt = [];
            $meldungen = [];
            $fehlerIds = [];
            foreach (self::aufsteigend(array_keys($preise)) as $id) {
                $a = $this->angebote->sperre($id);
                $hindernis = $a === null ? self::ANGEBOT_FEHLT : self::hindernis($a, $boerseId);
                if ($hindernis !== null) {
                    $meldungen[] = $hindernis;
                    $fehlerIds[] = $id;
                    continue;
                }
                $gesperrt[$id] = $a;
            }
            if ($meldungen !== []) {
                throw new KasseFehler($meldungen, $fehlerIds);
            }
            // 3. Sammlungen nach id, 4. alle Artikel nach id
            $sammlungIds = self::aufsteigend(array_filter(array_map(fn(array $a) => $a['magazin_sammlung_id'], $gesperrt), fn($s) => $s !== null));
            $sammlungArtikel = [];
            foreach ($sammlungIds as $sid) {
                $this->magazinRep->sperreSammlung($sid);
                $sammlungArtikel[$sid] = $this->magazinRep->artikelDerSammlung($sid);
            }
            $einzeln = array_filter(array_map(fn(array $a) => $a['magazin_artikel_id'], $gesperrt), fn($s) => $s !== null);
            $artikelIds = self::aufsteigend([...array_values($einzeln), ...array_merge([], ...array_values($sammlungArtikel))]);
            $bestand = [];
            foreach ($artikelIds as $aid) {
                $bestand[$aid] = $this->magazinRep->sperreArtikel($aid) === null ? 0 : $this->magazinRep->bestand($aid);
            }
            // Bestand nach den Sperren prüfen (alle Meldungen sammeln)
            $bedarf = [];
            foreach ($gesperrt as $a) {
                if ($a['magazin_artikel_id'] !== null) {
                    $bedarf[(int) $a['magazin_artikel_id']] = ($bedarf[(int) $a['magazin_artikel_id']] ?? 0) + (int) $a['menge'];
                }
            }
            foreach ($gesperrt as $id => $a) {
                $fehlt = $a['magazin_artikel_id'] !== null
                    ? $bestand[(int) $a['magazin_artikel_id']] < $bedarf[(int) $a['magazin_artikel_id']]
                    : ($a['magazin_sammlung_id'] !== null && array_sum(array_map(fn(int $aid) => $bestand[$aid], $sammlungArtikel[(int) $a['magazin_sammlung_id']])) < 1);
                if ($fehlt) {
                    $meldungen[] = self::bestandReichtNicht(self::nummer($a));
                    $fehlerIds[] = $id;
                }
            }
            if ($meldungen !== []) {
                throw new KasseFehler($meldungen, $fehlerIds);
            }

            // Schreiben: Verkauf, Positionen, Status, Magazin-Ausgänge
            $summe = 0;
            foreach ($preise as $preis) {
                $summe += Geld::cent($preis);
            }
            $verkaufId = $this->verkaeufe->anlegen($boerseId, Geld::dezimal($summe), $zahlart, $jetzt, $benutzerId);
            $ausgaenge = [];
            foreach ($gesperrt as $id => $a) {
                $preis = $preise[$id];
                $mitglied = $a['herkunft'] === 'mitglied';
                $spende = $mitglied && (int) $a['erloes_spende'] === 1;
                $provision = $mitglied && !$spende ? Geld::provision(Geld::cent($preis), (string) $boerse['provision_prozent']) : 0;
                $positionId = $this->verkaeufe->position(
                    $verkaufId, $id, $preis, Geld::dezimal($provision), (string) $a['herkunft'],
                    $mitglied && $a['person_id'] !== null ? (int) $a['person_id'] : null, $spende
                );
                $this->angebote->setzeStatus($id, 'verkauft');
                $this->protokoll->schreibe($benutzerId, AngebotDienst::OBJEKT, $id, 'geaendert', [
                    'status' => ['alt' => $a['status'], 'neu' => 'verkauft'],
                    'verkauf' => ['alt' => null, 'neu' => 'Nr. ' . $verkaufId . ', ' . ReparaturValidator::betrag($preis) . ' (' . self::ZAHLARTEN[$zahlart] . ')'],
                ]);
                $nummer = self::nummer($a);
                if ($a['magazin_artikel_id'] !== null) {
                    $ausgaenge[] = [(int) $a['magazin_artikel_id'], (int) $a['menge'], $preis, $nummer, $positionId, $id];
                } elseif ($a['magazin_sammlung_id'] !== null) {
                    $betrag = $preis; // Betrag nur auf dem ersten Artikel mit Bestand, die übrigen 0.00
                    foreach ($sammlungArtikel[(int) $a['magazin_sammlung_id']] as $aid) {
                        if ($bestand[$aid] > 0) {
                            $ausgaenge[] = [$aid, $bestand[$aid], $betrag, $nummer, $positionId, $id];
                            $betrag = '0.00';
                        }
                    }
                }
            }
            // Artikel sind schon gesperrt; die Ausgänge trotzdem nach Artikel-id (Sperrreihenfolge auch in ausgang…()).
            usort($ausgaenge, fn(array $x, array $y): int => [$x[0], $x[4]] <=> [$y[0], $y[4]]);
            foreach ($ausgaenge as [$aid, $menge, $betrag, $nummer, $positionId, $angebotId]) {
                try {
                    $this->magazin->ausgangInLaufenderTransaktion($aid, $menge, 'verkauft_boerse', $betrag, $nummer, $positionId, $benutzerId, $jetzt);
                } catch (DomainFehler $e) {
                    throw new KasseFehler([self::bestandReichtNicht($nummer)], [$angebotId]);
                }
            }
            return $verkaufId;
        });
    }

    /**
     * Storniert einen Verkauf am selben Tag: Stücke wieder „angeboten“, Magazin-Gegenbuchungen, Verkauf mit
     * storniert_am und Grund. Sperrreihenfolge: Börse → Verkauf → Angebote → Sammlungen → Artikel (über die
     * Gegenbuchungen, nach Artikel-id).
     *
     * @throws DomainFehler Grund, schon storniert, anderer Tag, Börse abgeschlossen, Auszahlung vermerkt, Sperrkonflikt
     */
    public function storniere(int $verkaufId, string $grund, ?int $benutzerId, \DateTimeImmutable $jetzt): void
    {
        $grund = trim($grund);
        if ($grund === '') {
            throw new DomainFehler(self::KEIN_GRUND);
        }
        if (mb_strlen($grund) > self::GRUND_MAX) {
            throw new DomainFehler(self::GRUND_ZU_LANG);
        }
        $jetzt = self::inAppZeit($jetzt);
        Transaktion::readCommitted($this->pdo, function () use ($verkaufId, $grund, $benutzerId, $jetzt): void {
            $vorab = $this->verkaeufe->finde($verkaufId) ?? throw new DomainFehler(self::VERKAUF_FEHLT);
            $boerse = $this->boersen->sperre((int) $vorab['boerse_id']) ?? throw new DomainFehler(self::BOERSE_FEHLT);
            $v = $this->verkaeufe->sperre($verkaufId) ?? throw new DomainFehler(self::VERKAUF_FEHLT);
            if ($v['storniert_am'] !== null) {
                throw new DomainFehler(self::SCHON_STORNIERT);
            }
            if ($boerse['status'] === 'abgeschlossen') {
                throw new DomainFehler(AngebotDienst::BOERSE_ABGESCHLOSSEN);
            }
            if ($boerse['status'] !== 'laeuft') {
                throw new DomainFehler(self::LAEUFT_NICHT);
            }
            if (substr((string) $v['verkauft_am'], 0, 10) !== $jetzt->format('Y-m-d')) {
                throw new DomainFehler(self::NUR_SELBER_TAG);
            }
            $positionen = $this->verkaeufe->positionen($verkaufId);
            $personen = [];
            foreach ($positionen as $p) {
                if ($p['herkunft'] === 'mitglied' && $p['person_id'] !== null) {
                    $personen[] = (int) $p['person_id'];
                }
            }
            if ($this->verkaeufe->auszahlungVermerkt((int) $v['boerse_id'], self::aufsteigend($personen))) {
                throw new DomainFehler(self::AUSZAHLUNG_VERMERKT);
            }
            $gesperrt = [];
            foreach (self::aufsteigend(array_map(fn(array $p) => (int) $p['angebot_id'], $positionen)) as $id) {
                $gesperrt[$id] = $this->angebote->sperre($id) ?? throw new DomainFehler(self::ANGEBOT_FEHLT);
            }
            $buchungen = $this->verkaeufe->buchungen($verkaufId); // nach Artikel-id aufsteigend
            $this->verlangeUnveraendertesAngebot($gesperrt, $positionen, $buchungen);
            foreach ($gesperrt as $id => $a) {
                $this->angebote->setzeStatus($id, 'angeboten');
                $this->protokoll->schreibe($benutzerId, AngebotDienst::OBJEKT, $id, 'geaendert', [
                    'status' => ['alt' => $a['status'], 'neu' => 'angeboten'],
                    'storno' => ['alt' => null, 'neu' => 'Verkauf Nr. ' . $verkaufId . ': ' . $grund],
                ]);
            }
            foreach ($buchungen as $b) {
                $this->magazin->gegenbuchungInLaufenderTransaktion($b['id'], $benutzerId);
            }
            $this->verkaeufe->storniere($verkaufId, $grund, $jetzt, $benutzerId);
        });
    }

    /**
     * Storno, Angebote schon gesperrt: sperrt die betroffenen Sammlungen (aufsteigend), dann alle betroffenen Artikel
     * (aufsteigend) und prüft, dass das Wiederanbieten keine Exklusivität verletzt. Eine verkaufte Sammlung ist nach dem
     * Verkauf nicht mehr „offen“ – inzwischen kann sie neu angeboten, ihr Inhalt geändert oder einer ihrer Artikel einzeln
     * angeboten worden sein; ein verkaufter Einzelartikel kann inzwischen in einer angebotenen Sammlung stecken. Dann wird
     * das Storno abgelehnt (nichts geändert).
     *
     * @param array<int, array<string,mixed>> $gesperrt Angebote nach id
     * @param list<array<string,mixed>> $positionen
     * @param list<array{id:int, artikel_id:int, verkauf_position_id:int}> $buchungen
     */
    private function verlangeUnveraendertesAngebot(array $gesperrt, array $positionen, array $buchungen): void
    {
        $positionVon = [];
        foreach ($positionen as $p) {
            $positionVon[(int) $p['id']] = (int) $p['angebot_id'];
        }
        $gebucht = []; // Angebot → Artikel mit Kassen-Ausgang
        foreach ($buchungen as $b) {
            $gebucht[$positionVon[$b['verkauf_position_id']] ?? 0][] = $b['artikel_id'];
        }
        // Sammlungen: die verkauften und die, in denen verkaufte Einzelartikel inzwischen stecken (ungesperrt gelesen,
        // nach der Sperre erneut geprüft)
        $sammlungen = [];
        foreach ($gesperrt as $a) {
            if ($a['magazin_sammlung_id'] !== null) {
                $sammlungen[] = (int) $a['magazin_sammlung_id'];
            } elseif ($a['magazin_artikel_id'] !== null) {
                $s = $this->magazinRep->sammlungVonArtikel((int) $a['magazin_artikel_id']);
                if ($s !== null) {
                    $sammlungen[] = (int) $s['id'];
                }
            }
        }
        $inhalt = [];
        foreach (self::aufsteigend($sammlungen) as $sid) {
            $this->magazinRep->sperreSammlung($sid);
            $inhalt[$sid] = $this->magazinRep->artikelDerSammlung($sid);
        }
        $artikel = array_column($buchungen, 'artikel_id');
        foreach ($gesperrt as $a) {
            if ($a['magazin_sammlung_id'] !== null) {
                array_push($artikel, ...($inhalt[(int) $a['magazin_sammlung_id']] ?? []));
            } elseif ($a['magazin_artikel_id'] !== null) {
                $artikel[] = (int) $a['magazin_artikel_id'];
            }
        }
        foreach (self::aufsteigend($artikel) as $aid) {
            $this->magazinRep->sperreArtikel($aid);
        }
        $meldungen = [];
        foreach ($gesperrt as $id => $a) {
            $geaendert = false;
            if ($a['magazin_sammlung_id'] !== null) {
                $sid = (int) $a['magazin_sammlung_id'];
                $jetzt = $inhalt[$sid] ?? [];
                $verkauft = $gebucht[$id] ?? [];
                $geaendert = $this->angebote->offenFuerSammlung($sid, $id)
                    || array_diff($verkauft, $jetzt) !== []
                    || array_filter(array_diff($jetzt, $verkauft), fn(int $aid): bool => $this->magazinRep->bestand($aid) > 0) !== []
                    || array_filter($jetzt, fn(int $aid): bool => $this->angebote->offeneMengeFuerArtikel($aid) > 0) !== [];
            } elseif ($a['magazin_artikel_id'] !== null) {
                $s = $this->magazinRep->sammlungVonArtikel((int) $a['magazin_artikel_id']);
                $geaendert = $s !== null && $this->angebote->offenFuerSammlung((int) $s['id']);
            }
            if ($geaendert) {
                $meldungen[] = self::nummer($a) . ': ' . self::STORNO_ANGEBOT_GEAENDERT;
            }
        }
        if ($meldungen !== []) {
            throw new DomainFehler(implode(' ', $meldungen));
        }
    }

    /** Darf am Tag $jetzt storniert werden (Anzeige der Verkaufsliste; verbindlich prüft storniere())? */
    public static function stornierbar(array $verkauf, array $boerse, \DateTimeImmutable $jetzt): bool
    {
        return $verkauf['storniert_am'] === null && $boerse['status'] === 'laeuft'
            && substr((string) $verkauf['verkauft_am'], 0, 10) === self::inAppZeit($jetzt)->format('Y-m-d');
    }

    /**
     * Warum das Angebot an dieser Börse nicht verkauft werden kann, oder null. Reservierte Stücke sind verkaufbar.
     *
     * @param array<string,mixed> $a Angebotszeile (mit jahr, laufnummer, status, boerse_id)
     */
    private static function hindernis(array $a, int $boerseId): ?string
    {
        $nummer = self::nummer($a);
        return match (true) {
            $a['status'] === 'verkauft' => $nummer . ' ist bereits verkauft.',
            $a['status'] === 'zurueck' => $nummer . ' ist zurückgegeben.',
            $a['boerse_id'] !== null && (int) $a['boerse_id'] !== $boerseId => $nummer . ' gehört nicht zu dieser Börse.',
            default => null,
        };
    }

    /** @param array<string,mixed> $a */
    private static function nummer(array $a): string
    {
        return Angebotsnummer::formatiere((int) $a['jahr'], (int) $a['laufnummer']);
    }

    /** Zeitpunkt in der Zeitzone der App (verkauft_am und „am selben Tag“). */
    private static function inAppZeit(\DateTimeImmutable $jetzt): \DateTimeImmutable
    {
        return $jetzt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
