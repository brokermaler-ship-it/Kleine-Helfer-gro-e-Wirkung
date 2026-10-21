<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Anlagen\AnlageRepository;
use Emc\Auth\Benutzer;
use Emc\Auth\Berechtigung;
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
     * Daten für templates/reparatur/_block.php (Tab „Reparaturen“ am Fahrzeug, Abschnitt an der Anlage); null, solange
     * das Datenbank-Update (Migration 014) aussteht – die Seite erscheint dann ohne Block.
     *
     * @param array<string,mixed> $objekt
     * @param ?Benutzer $b für darfBearbeiten (ohne Benutzer: false)
     * @return ?array{objektTyp:string, objekt:array<string,mixed>, reparaturen:list<array<string,mixed>>, offene:int, darfBearbeiten:bool}
     */
    public function blockDaten(string $objektTyp, array $objekt, ?Benutzer $b = null): ?array
    {
        try {
            $reparaturen = $this->reparaturen->fuerObjekt($objektTyp, (int) $objekt['id']);
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                return null;
            }
            throw $e;
        }
        $offene = count(array_filter($reparaturen, fn(array $r): bool => ReparaturStatus::tryFrom((string) $r['status'])?->istOffen() ?? false));
        return [
            'objektTyp' => $objektTyp,
            'objekt' => $objekt,
            'reparaturen' => $reparaturen,
            'offene' => $offene,
            'darfBearbeiten' => Berechtigung::darfReparaturBearbeiten($b, $objektTyp, $objekt),
        ];
    }

    /**
     * Rahmen zum Löschen eines Fahrzeugs bzw. einer Anlage, wie inTransaktion(): eigene Transaktion in READ COMMITTED, das
     * Objekt wird ZUERST mit FOR UPDATE gesperrt; dann die Reparaturen samt nur ihnen zugeordneter Fotos, danach $loeschen
     * (Wartungen, Dokumente und der Datensatz selbst, alles ohne eigene Transaktion), dann Commit; erst danach die Dateien
     * vom Webspace. Eine gleichzeitige Meldung (inTransaktion) liegt so entweder vorher – die Löschung wartet auf sie und
     * sieht sie danach – und wird mitgelöscht, oder sie findet das Objekt nicht mehr (404): keine verwaiste Reparatur.
     *
     * @param callable(array<string,mixed> $objekt): list<string> $loeschen liefert relative Pfade, deren Dateien nach
     *                                                              dem Commit entfernt werden
     * @return bool false, wenn es das Objekt nicht (mehr) gibt ($loeschen läuft dann nicht; Aufrufer: 404)
     */
    public function loescheObjekt(string $objektTyp, int $objektId, callable $loeschen): bool
    {
        $tabelle = self::tabelle($objektTyp);
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('ReparaturDienst::loescheObjekt braucht eine eigene Transaktion.');
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); // gilt nur für die nächste Transaktion
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM $tabelle WHERE id = ? FOR UPDATE");
            $st->execute([$objektId]);
            $objekt = $st->fetch(\PDO::FETCH_ASSOC);
            if ($objekt === false) {
                $this->pdo->rollBack();
                return false;
            }
            $pfade = $this->entferneFuerObjektInTransaktion($objektTyp, $objektId);
            array_push($pfade, ...$loeschen($objekt));
            // Ab dem Commit müssen die Dateien weg, auch wenn der Browser die Anfrage abbricht.
            ignore_user_abort(true);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }
        foreach ($pfade as $pfad) {
            if (!$this->dokumente->entferneDateien($pfad)) {
                error_log('Löschen ' . $objektTyp . ' Nr. ' . $objektId . ': Dateien konnten nicht vom Webspace entfernt werden.');
            }
        }
        return true;
    }

    /**
     * Vor dem Löschen eines Fahrzeugs bzw. einer Anlage: Reparaturen samt nur ihnen zugeordneter Fotos entfernen
     * (Ersatzteile und Gesehen-Zeilen per CASCADE) – gemeinsam in einer Transaktion, die Fotodateien danach. Für das
     * Löschen des Objekts selbst loescheObjekt() verwenden (Objekt zuerst gesperrt).
     */
    public function entferneFuerObjekt(string $objektTyp, int $objektId): void
    {
        $this->pdo->beginTransaction();
        try {
            $pfade = $this->entferneFuerObjektInTransaktion($objektTyp, $objektId);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
        foreach ($pfade as $pfad) {
            $this->dokumente->entferneDateien($pfad);
        }
    }

    /**
     * Reparaturen des Objekts mit sperrender Lesung (aktueller Stand, auch eine eben festgeschriebene Meldung) samt nur
     * ihnen zugeordneter Fotos löschen, in der Transaktion des Aufrufers; vor Migration 014 gibt es keine.
     *
     * @return list<string> relative Pfade der gelöschten Fotos (Dateien entfernt der Aufrufer nach dem Commit)
     */
    private function entferneFuerObjektInTransaktion(string $objektTyp, int $objektId): array
    {
        try {
            $ids = $this->reparaturen->idsFuerObjekt($objektTyp, $objektId, true);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return []; // Migration 014 noch nicht ausgeführt: es gibt keine Reparaturen.
            }
            throw $e;
        }
        $pfade = [];
        foreach ($ids as $reparaturId) {
            array_push($pfade, ...$this->dokumente->loeseVonObjekt('reparatur', $reparaturId));
            $this->reparaturen->loeschen($reparaturId);
        }
        return $pfade;
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
