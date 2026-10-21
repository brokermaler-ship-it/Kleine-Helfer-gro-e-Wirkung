<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Anlagen\AnlageRepository;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Gemeinsame Bausteine rund um Reparaturen: der automatische Fahrzeug-/Anlagenstatus (StatusAutomatik) und das
 * Aufräumen, wenn ein Objekt gelöscht wird.
 */
final class ReparaturDienst
{
    /** Zusatz im Änderungsprotokoll des Objekts, wenn eine Reparatur den Status gesetzt hat. */
    public const AUTOMATISCH = '(automatisch durch Reparatur)';

    public function __construct(
        private \PDO $pdo,
        private ReparaturRepository $reparaturen,
        private FahrzeugRepository $fahrzeuge,
        private AnlageRepository $anlagen,
        private DokumentRepository $dokumente,
        private Aenderungsprotokoll $protokoll,
    ) {
    }

    /**
     * Wendet den automatischen Status auf das Objekt an (nach Anlegen oder Speichern einer Reparatur). Läuft in der
     * Transaktion des Aufrufers: das Objekt wird mit FOR UPDATE gelesen, damit gleichzeitige Meldungen am selben Objekt
     * nacheinander entscheiden. Ändert sich der Status, steht das im Änderungsprotokoll des Objekts.
     */
    public function wendeStatusAn(string $objektTyp, int $objektId, ?int $benutzerId): void
    {
        $tabelle = match ($objektTyp) {
            'fahrzeug' => 'fahrzeug',
            'anlage' => 'anlage',
            default => throw new \InvalidArgumentException('Unbekannter Objekttyp: ' . $objektTyp),
        };
        $st = $this->pdo->prepare("SELECT status, status_automatisch FROM $tabelle WHERE id = ? FOR UPDATE");
        $st->execute([$objektId]);
        $objekt = $st->fetch(\PDO::FETCH_ASSOC);
        if ($objekt === false) {
            return;
        }
        $alt = (string) $objekt['status'];
        $ziel = StatusAutomatik::ziel($objektTyp, $alt, (bool) (int) $objekt['status_automatisch'], $this->reparaturen->offeneFuerObjekt($objektTyp, $objektId));
        if ($ziel === null) {
            return;
        }
        if ($objektTyp === 'fahrzeug') {
            $this->fahrzeuge->setzeStatusAutomatisch($objektId, $ziel['status'], $ziel['automatisch']);
        } else {
            $this->anlagen->setzeStatusAutomatisch($objektId, $ziel['status'], $ziel['automatisch']);
        }
        if ($ziel['status'] !== $alt) {
            $this->protokoll->schreibe($benutzerId, $objektTyp, $objektId, 'geaendert', [
                'status' => ['alt' => $alt, 'neu' => $ziel['status'] . ' ' . self::AUTOMATISCH],
            ]);
        }
    }

    /**
     * Vor dem Löschen eines Fahrzeugs bzw. einer Anlage: Reparaturen samt nur ihnen zugeordneter Fotos entfernen
     * (Ersatzteile und Gesehen-Zeilen per CASCADE). Die Fotos zuerst (DokumentRepository::loeschen hat eigene
     * Transaktionen), danach die Reparaturen gemeinsam.
     */
    public function entferneFuerObjekt(string $objektTyp, int $objektId): void
    {
        try {
            $ids = $this->reparaturen->idsFuerObjekt($objektTyp, $objektId);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return; // Migration 014 noch nicht ausgeführt: es gibt keine Reparaturen.
            }
            throw $e;
        }
        foreach ($ids as $reparaturId) {
            $this->dokumente->entferneVonObjekt('reparatur', $reparaturId);
        }
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $reparaturId) {
                $this->reparaturen->loeschen($reparaturId);
            }
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }
}
