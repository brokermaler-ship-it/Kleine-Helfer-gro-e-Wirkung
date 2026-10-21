<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Börsen anlegen, ändern und starten. Statuswechsel und Änderungen laufen in einer Transaktion READ COMMITTED mit
 * Sperre der Börse zuerst (wie die Kasse), damit z. B. die Provision nach dem ersten Verkauf fest bleibt.
 * (Abschluss, Wiederöffnen und Auszahlungen folgen mit der Abrechnung.)
 */
final class BoerseDienst
{
    public const OBJEKT = 'boerse';
    public const NICHT_GEFUNDEN = 'Diese Börse gibt es nicht mehr.';

    public function __construct(
        private \PDO $pdo,
        private BoerseRepository $boersen,
        private AngebotRepository $angebote,
        private Aenderungsprotokoll $protokoll,
    ) {
    }

    /** Wirft BOERSE_ABGESCHLOSSEN für eine abgeschlossene Börse (Schreibschutz für Börse, Angebote und Kasse). */
    public function verlangeOffen(array $boerse): void
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
}
