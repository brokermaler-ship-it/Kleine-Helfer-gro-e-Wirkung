<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Börsen anlegen, ändern, starten, abschließen und wieder öffnen; Auszahlungen an Mitglieder vermerken. Jede Schreibung
 * läuft in einer Transaktion READ COMMITTED mit Sperre der Börse zuerst (wie Kasse, Storno und AngebotDienst), damit
 * z. B. die Provision nach dem ersten Verkauf fest bleibt, ein Abschluss auf eine laufende Kasse wartet und der
 * Auszahlungsbetrag erst nach der Sperre – also ohne gleichzeitigen Verkauf oder Storno – berechnet wird.
 */
final class BoerseDienst
{
    public const OBJEKT = 'boerse';
    public const NICHT_GEFUNDEN = 'Diese Börse gibt es nicht mehr.';
    public const NUR_LAUFENDE = 'Nur eine laufende Börse lässt sich abschließen.';
    public const NUR_ABGESCHLOSSENE = 'Nur eine abgeschlossene Börse lässt sich wieder öffnen.';
    public const NICHTS_AUSZUZAHLEN = 'Für dieses Mitglied ist nichts auszuzahlen.';
    public const SCHON_VERMERKT = 'Für dieses Mitglied ist schon eine Auszahlung vermerkt.';
    public const KEINE_AUSZAHLUNG = 'Für dieses Mitglied ist keine Auszahlung vermerkt.';
    public const DATUM_UNGUELTIG = 'Bitte ein gültiges Datum für die Auszahlung angeben.';

    public function __construct(
        private \PDO $pdo,
        private BoerseRepository $boersen,
        private AngebotRepository $angebote,
        private Aenderungsprotokoll $protokoll,
        private ?AbrechnungRepository $abrechnung = null,
    ) {
        $this->abrechnung ??= new AbrechnungRepository($pdo);
    }

    /**
     * Wirft BOERSE_ABGESCHLOSSEN für eine abgeschlossene Börse – der gemeinsame Schreibschutz für Börse bearbeiten,
     * Angebote (AngebotDienst) und Storno (KasseDienst). Statisch, damit die Dienste ihn ohne Abhängigkeit nutzen.
     *
     * @param array<string,mixed> $boerse Zeile mit status (am besten gesperrt)
     */
    public static function verlangeOffen(array $boerse): void
    {
        if (($boerse['status'] ?? null) === 'abgeschlossen') {
            throw new DomainFehler(AngebotDienst::BOERSE_ABGESCHLOSSEN);
        }
    }

    /** @param array{name:string, datum:string, ort:?string, provision_prozent:string} $d vom BoerseValidator */
    public function anlegen(array $d, ?int $benutzerId): int
    {
        return Transaktion::readCommitted($this->pdo, function () use ($d, $benutzerId): int {
            $id = $this->boersen->anlegen($d);
            $this->protokoll->schreibe($benutzerId, self::OBJEKT, $id, 'angelegt', Aenderungsprotokoll::diff([], $d));
            return $id;
        });
    }

    /**
     * Ändert Name, Datum, Ort und Provision (Stand-Schutz). Nach dem ersten Verkauf bleibt die Provision; eine
     * abgeschlossene Börse lässt sich nicht ändern.
     *
     * @param array{name:string, datum:string, ort:?string, provision_prozent:string} $d vom BoerseValidator
     * @throws \Emc\Fahrzeuge\KonfliktFehler bei veraltetem Stand
     */
    public function aendern(int $id, array $d, string $stand, ?int $benutzerId): void
    {
        Transaktion::readCommitted($this->pdo, function () use ($id, $d, $stand, $benutzerId): void {
            $alt = $this->boersen->sperre($id) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            $this->verlangeOffen($alt);
            if ($this->boersen->anzahlVerkaeufe($id) > 0
                && Geld::hundertstelProzent((string) $alt['provision_prozent']) !== Geld::hundertstelProzent($d['provision_prozent'])
            ) {
                throw new DomainFehler(BoerseValidator::PROVISION_GESPERRT);
            }
            $this->boersen->aktualisieren($id, $d, $stand);
            $diff = Aenderungsprotokoll::diff(array_intersect_key($alt, $d), $d);
            if (isset($diff['provision_prozent']) && Geld::hundertstelProzent((string) $alt['provision_prozent']) === Geld::hundertstelProzent($d['provision_prozent'])) {
                unset($diff['provision_prozent']);
            }
            if ($diff !== []) {
                $this->protokoll->schreibe($benutzerId, self::OBJEKT, $id, 'geaendert', $diff);
            }
        });
    }

    /** in_vorbereitung → laeuft (ab dann ist die Kasse offen). */
    public function starten(int $id, ?int $benutzerId): void
    {
        Transaktion::readCommitted($this->pdo, function () use ($id, $benutzerId): void {
            $b = $this->boersen->sperre($id) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            if ($b['status'] !== 'in_vorbereitung') {
                throw new DomainFehler('Nur eine Börse in Vorbereitung lässt sich starten.');
            }
            $this->boersen->setzeStatus($id, 'laeuft');
            $this->protokoll->schreibe($benutzerId, self::OBJEKT, $id, 'geaendert', ['status' => ['alt' => 'in_vorbereitung', 'neu' => 'laeuft']]);
        });
    }

    /**
     * laeuft → abgeschlossen. Alle offenen Angebote dieser Börse (angeboten/reserviert) gehen zurück; Vereinsstücke
     * bleiben unverändert im Magazin (keine Buchung – der Bestand war nie abgebucht, das Angebot gibt ihn nur frei).
     * Dauerhafte Angebote bleiben angeboten. Sperrreihenfolge: Börse → Angebote nach id aufsteigend; eine laufende Kasse
     * wird abgewartet, ein Warenkorb danach mit „Die Börse läuft nicht“ abgewiesen.
     *
     * @return int Anzahl zurückgegebener Stücke (Angebote)
     * @throws DomainFehler nicht laufend, unbekannt, Sperrkonflikt (nichts geändert)
     */
    public function abschliessen(int $boerseId, ?int $benutzerId, \DateTimeImmutable $jetzt): int
    {
        $jetzt = $jetzt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return Transaktion::readCommitted($this->pdo, function () use ($boerseId, $benutzerId, $jetzt): int {
            $b = $this->boersen->sperre($boerseId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            if ($b['status'] !== 'laeuft') {
                throw new DomainFehler(self::NUR_LAUFENDE);
            }
            $anzahl = 0;
            foreach ($this->angebote->offeneIdsDerBoerse($boerseId) as $id) {
                $a = $this->angebote->sperre($id);
                if ($a === null || (int) ($a['boerse_id'] ?? 0) !== $boerseId || !in_array($a['status'], AngebotRepository::OFFEN, true)) {
                    continue;
                }
                $this->angebote->setzeStatus($id, 'zurueck');
                $this->protokoll->schreibe($benutzerId, AngebotDienst::OBJEKT, $id, 'geaendert', [
                    'status' => ['alt' => $a['status'], 'neu' => 'zurueck'],
                    'boerse' => ['alt' => null, 'neu' => 'Börse abgeschlossen'],
                ]);
                $anzahl++;
            }
            $this->boersen->setzeStatus($boerseId, 'abgeschlossen', $jetzt);
            $this->protokoll->schreibe($benutzerId, self::OBJEKT, $boerseId, 'geaendert', [
                'status' => ['alt' => 'laeuft', 'neu' => 'abgeschlossen'],
                'zurueck' => ['alt' => null, 'neu' => $anzahl],
            ]);
            return $anzahl;
        });
    }

    /**
     * abgeschlossen → laeuft (nur Administratoren, prüft die Route). Die Angebote bleiben zurück; der Börsenwart bietet
     * sie bei Bedarf wieder an.
     *
     * @throws DomainFehler nicht abgeschlossen, unbekannt, Sperrkonflikt
     */
    public function wiederOeffnen(int $boerseId, ?int $benutzerId): void
    {
        Transaktion::readCommitted($this->pdo, function () use ($boerseId, $benutzerId): void {
            $b = $this->boersen->sperre($boerseId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            if ($b['status'] !== 'abgeschlossen') {
                throw new DomainFehler(self::NUR_ABGESCHLOSSENE);
            }
            $this->boersen->setzeStatus($boerseId, 'laeuft');
            $this->protokoll->schreibe($benutzerId, self::OBJEKT, $boerseId, 'geaendert', ['status' => ['alt' => 'abgeschlossen', 'neu' => 'laeuft']]);
        });
    }

    /**
     * Haken „ausgezahlt“: Betrag = aktuelle Auszahlung der Person laut Abrechnung (nur > 0), berechnet nach der Sperre
     * der Börse – ein gleichzeitiger Verkauf oder ein Storno ist dann entweder ganz enthalten oder wartet. Auch nach dem
     * Abschluss erlaubt (Dokumentation der Auszahlung). Das Protokoll der Börse nennt nur die Personen-id, keinen Namen.
     *
     * @param string $datum Y-m-d
     * @throws DomainFehler unbekannt, Datum, nichts auszuzahlen, schon vermerkt, Sperrkonflikt
     */
    public function vermerkeAuszahlung(int $boerseId, int $personId, string $datum, ?int $benutzerId): void
    {
        $datum = self::datum($datum) ?? throw new DomainFehler(self::DATUM_UNGUELTIG);
        Transaktion::readCommitted($this->pdo, function () use ($boerseId, $personId, $datum, $benutzerId): void {
            $this->boersen->sperre($boerseId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            if ($this->abrechnung->auszahlungVermerkt($boerseId, $personId)) {
                throw new DomainFehler(self::SCHON_VERMERKT);
            }
            $cent = 0;
            foreach (Abrechnung::berechne($this->abrechnung->positionen($boerseId))['mitglieder'] as $m) {
                if ($m['person_id'] === $personId) {
                    $cent = $m['auszahlung'];
                }
            }
            if ($cent <= 0) {
                throw new DomainFehler(self::NICHTS_AUSZUZAHLEN);
            }
            $betrag = Geld::dezimal($cent);
            $this->abrechnung->vermerkeAuszahlung($boerseId, $personId, $betrag, $datum, $benutzerId);
            $this->protokoll->schreibe($benutzerId, self::OBJEKT, $boerseId, 'geaendert', [
                'auszahlung' => ['alt' => null, 'neu' => 'Person ' . $personId . ': ' . $betrag . ' am ' . $datum],
            ]);
        });
    }

    /**
     * Haken „ausgezahlt“ entfernen (z. B. vertan, oder vor einem Storno).
     *
     * @throws DomainFehler unbekannt, keine Auszahlung vermerkt, Sperrkonflikt
     */
    public function entferneAuszahlung(int $boerseId, int $personId, ?int $benutzerId): void
    {
        Transaktion::readCommitted($this->pdo, function () use ($boerseId, $personId, $benutzerId): void {
            $this->boersen->sperre($boerseId) ?? throw new DomainFehler(self::NICHT_GEFUNDEN);
            if (!$this->abrechnung->entferneAuszahlung($boerseId, $personId)) {
                throw new DomainFehler(self::KEINE_AUSZAHLUNG);
            }
            $this->protokoll->schreibe($benutzerId, self::OBJEKT, $boerseId, 'geaendert', [
                'auszahlung' => ['alt' => 'Person ' . $personId, 'neu' => null],
            ]);
        });
    }

    /** Y-m-d als gültiges Kalenderdatum (2000–2999), sonst null. */
    private static function datum(string $w): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($w), $m) !== 1) {
            return null;
        }
        [$j, $mo, $t] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        return $j >= 2000 && $j <= 2999 && checkdate($mo, $t, $j) ? sprintf('%04d-%02d-%02d', $j, $mo, $t) : null;
    }
}
