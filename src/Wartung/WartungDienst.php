<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Auth\Benutzer;
use Emc\Auth\Berechtigung;
use Emc\Dateien\DokumentRepository;
use Emc\Personen\PersonRepository;

/**
 * Gemeinsame Bausteine für den Wartungsblock auf Fahrzeug- und Anlagenseiten (Daten zusammenstellen,
 * wählbare Wartungsarten) und das Aufräumen, wenn ein Objekt gelöscht wird.
 */
final class WartungDienst
{
    public function __construct(
        private \PDO $pdo,
        private WartungRepository $wartungen,
        private AusnahmeRepository $ausnahmen,
        private WartungsartRepository $arten,
        private PersonRepository $personen,
        private DokumentRepository $dokumente,
    ) {
    }

    /**
     * Aktive, zum Objekt passende Wartungsarten (auch abgeschaltete – für „Wartungsplan anpassen“).
     *
     * @param array<string,mixed> $objekt
     * @return list<array<string,mixed>>
     */
    public function passendeArten(string $objektTyp, array $objekt): array
    {
        return $this->arten->passendeFuer($objektTyp, $objektTyp === 'fahrzeug' ? (string) ($objekt['gattung'] ?? '') : null);
    }

    /**
     * Wartungsarten, die sich eintragen lassen: aktiv, passend und nicht im Wartungsplan des Objekts abgeschaltet.
     *
     * @param array<string,mixed> $objekt
     * @return list<array<string,mixed>>
     */
    public function eintragbareArten(string $objektTyp, array $objekt): array
    {
        $ausnahmen = $this->ausnahmen->fuerObjekt($objektTyp, (int) $objekt['id']);
        return array_values(array_filter(
            $this->passendeArten($objektTyp, $objekt),
            fn(array $a): bool => !($ausnahmen[$a['id']]['aus'] ?? false)
        ));
    }

    /**
     * Daten für templates/wartung/_block.php; null, solange das Datenbank-Update (Migration 012/013) aussteht.
     *
     * @param array<string,mixed> $objekt
     * @param array<string,mixed> $werte Formularwerte „Wartung eintragen“ (nach Fehlern)
     * @param array<string,string> $fehler
     * @param array<string,mixed> $planWerte Formularwerte „Wartungsplan anpassen“ (nach Fehlern)
     * @param array<int,string> $planFehler Meldung je Wartungsart-ID
     * @return ?array<string,mixed>
     */
    public function blockDaten(string $objektTyp, array $objekt, ?Benutzer $b, array $werte = [], array $fehler = [], array $planWerte = [], array $planFehler = []): ?array
    {
        try {
            $id = (int) $objekt['id'];
            $ausnahmen = $this->ausnahmen->fuerObjekt($objektTyp, $id);
            return [
                'objektTyp' => $objektTyp,
                'objekt' => $objekt,
                'wartungen' => $this->wartungen->fuerObjekt($objektTyp, $id),
                'arten' => $this->passendeArten($objektTyp, $objekt),
                'ausnahmen' => $ausnahmen,
                'personen' => $this->personen->alle(true),
                'darfEintragen' => Berechtigung::darfWartungEintragen($b, $objektTyp, $objekt),
                'darfPlan' => Berechtigung::darfWartungsplanAendern($b, $objektTyp),
                'werte' => $werte + ['datum' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'arten' => [], 'betriebsstunden' => '', 'ausgefuehrt_von' => $b === null ? '' : (string) $b->personId, 'notiz' => ''],
                'fehler' => $fehler,
                'planWerte' => $planWerte,
                'planFehler' => $planFehler,
            ];
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return null; // Tabellen fehlen noch: Seite ohne Wartungsblock anzeigen.
            }
            throw $e;
        }
    }

    /**
     * Vor dem Löschen eines Fahrzeugs bzw. einer Anlage: Wartungen samt nur ihnen zugeordneter Fotos und die
     * Ausnahmen des Objekts entfernen. Die Fotos zuerst (DokumentRepository::loeschen hat eigene Transaktionen),
     * danach Wartungen und Ausnahmen gemeinsam.
     */
    public function entferneFuerObjekt(string $objektTyp, int $objektId): void
    {
        try {
            $ids = $this->wartungen->idsFuerObjekt($objektTyp, $objektId);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return; // Migration 013 noch nicht ausgeführt: es gibt keine Wartungen.
            }
            throw $e;
        }
        foreach ($ids as $wartungId) {
            $this->dokumente->entferneVonObjekt('wartung', $wartungId);
        }
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $wartungId) {
                $this->wartungen->loeschen($wartungId);
            }
            $this->ausnahmen->loescheFuerObjekt($objektTyp, $objektId);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }

    /** Text für Protokoll und Anzeige: „21.10.2026: Räder und Radschleifer reinigen, Kupplungen prüfen“. */
    public static function beschreibung(string $datum, array $artNamen): string
    {
        return date('d.m.Y', (int) strtotime($datum)) . ': ' . implode(', ', $artNamen);
    }
}
