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
     * Rahmen für alles, was Reparaturen eines Objekts anlegt oder ändert: eigene Transaktion in READ COMMITTED (jede
     * Lesung sieht den neuesten festgeschriebenen Stand, auch nach früheren Lesungen in der Transaktion), das Objekt
     * wird ZUERST mit FOR UPDATE gesperrt, dann läuft $arbeit, danach wendeStatusAn, dann Commit. So gilt überall
     * dieselbe Sperrreihenfolge (Objekt vor Reparaturzeilen), und gleichzeitige Schreiber entscheiden nacheinander.
     * Bei einem Fehler wird zurückgerollt und weitergeworfen.
     *
     * @template T
     * @param callable(array<string,mixed> $objekt): T $arbeit erhält die gesperrte Zeile des Objekts
     * @return ?T null, wenn es das Objekt nicht (mehr) gibt ($arbeit läuft dann nicht; Aufrufer: 404)
     */
    public function inTransaktion(string $objektTyp, int $objektId, ?int $benutzerId, callable $arbeit): mixed
    {
        $tabelle = self::tabelle($objektTyp);
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('ReparaturDienst::inTransaktion braucht eine eigene Transaktion.');
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); // gilt nur für die nächste Transaktion
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM $tabelle WHERE id = ? FOR UPDATE");
            $st->execute([$objektId]);
            $objekt = $st->fetch(\PDO::FETCH_ASSOC);
            if ($objekt === false) {
                $this->pdo->rollBack();
                return null;
            }
            $ergebnis = $arbeit($objekt);
            $this->wendeStatusAn($objektTyp, $objektId, $benutzerId);
            $this->pdo->commit();
            return $ergebnis;
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }
    }

    /**
     * Wendet den automatischen Status auf das Objekt an (nach Anlegen oder Speichern einer Reparatur). Läuft in der
     * Transaktion des Aufrufers – dafür inTransaktion() verwenden: nur dort ist das Objekt vor jeder anderen Lesung
     * gesperrt und die Liste der offenen Reparaturen garantiert aktuell (READ COMMITTED). In einer eigenen Transaktion
     * mit REPEATABLE READ sähe offeneFuerObjekt nach einer früheren Lesung einen veralteten Schnappschuss.
     * Ändert sich der Status, steht das im Änderungsprotokoll des Objekts.
     */
    public function wendeStatusAn(string $objektTyp, int $objektId, ?int $benutzerId): void
    {
        $tabelle = self::tabelle($objektTyp);
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

    private static function tabelle(string $objektTyp): string
    {
        return match ($objektTyp) {
            'fahrzeug' => 'fahrzeug',
            'anlage' => 'anlage',
            default => throw new \InvalidArgumentException('Unbekannter Objekttyp: ' . $objektTyp),
        };
    }
}
