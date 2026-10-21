<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Core\Zeitstempel;
use Emc\Fahrzeuge\KonfliktFehler;

/** Börsen (Veranstaltungen): nur SQL. Statuswechsel laufen über den BoerseDienst (Transaktion, Sperre der Börse). */
final class BoerseRepository
{
    public const KONFLIKT = 'Diese Börse wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.';
    public const STATUS = ['in_vorbereitung', 'laeuft', 'abgeschlossen'];

    private const SELECT = "SELECT b.*, (SELECT COUNT(*) FROM boerse_angebot a WHERE a.boerse_id = b.id) AS anzahl_angebote, "
        . "(SELECT COUNT(*) FROM boerse_angebot a WHERE a.boerse_id = b.id AND a.status IN ('angeboten', 'reserviert')) AS anzahl_offen, "
        . '(SELECT COUNT(*) FROM boerse_verkauf v WHERE v.boerse_id = b.id AND v.storniert_am IS NULL) AS anzahl_verkaeufe '
        . 'FROM boerse b ';

    public function __construct(private \PDO $pdo)
    {
    }

    /** @param array{name:string, datum:string, ort:?string, provision_prozent:string} $d */
    public function anlegen(array $d): int
    {
        $this->pdo->prepare('INSERT INTO boerse (name, datum, ort, provision_prozent) VALUES (?, ?, ?, ?)')
            ->execute([$d['name'], $d['datum'], $d['ort'], $d['provision_prozent']]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return ?array<string,mixed> mit anzahl_angebote, anzahl_offen, anzahl_verkaeufe (nicht storniert) als int */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE b.id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::aufbereitet($z);
    }

    /**
     * Sperrt die Börse (SELECT … FOR UPDATE); nur in einer laufenden Transaktion. Erstes Glied der Sperrreihenfolge.
     *
     * @return ?array<string,mixed>
     */
    public function sperre(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM boerse WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** Anzahl nicht stornierter Verkäufe (nach der Sperre der Börse lesen). */
    public function anzahlVerkaeufe(int $id): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM boerse_verkauf WHERE boerse_id = ? AND storniert_am IS NULL');
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    }

    /**
     * @param array{name:string, datum:string, ort:?string, provision_prozent:string} $d
     * @throws KonfliktFehler wenn die Börse inzwischen geändert wurde
     */
    public function aktualisieren(int $id, array $d, string $stand): void
    {
        if (!Zeitstempel::istGueltig($stand)) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
        $st = $this->pdo->prepare('UPDATE boerse SET name = ?, datum = ?, ort = ?, provision_prozent = ?, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?');
        $st->execute([$d['name'], $d['datum'], $d['ort'], $d['provision_prozent'], $id, $stand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
    }

    /** @return list<array<string,mixed>> neueste zuerst */
    public function alle(): array
    {
        return array_map(self::aufbereitet(...), $this->pdo->query(self::SELECT . 'ORDER BY b.datum DESC, b.id DESC')->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> nicht abgeschlossene Börsen, nach Datum */
    public function offene(): array
    {
        return array_map(self::aufbereitet(...), $this->pdo->query(self::SELECT . "WHERE b.status <> 'abgeschlossen' ORDER BY b.datum, b.id")->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> laufende Börsen, nach Datum */
    public function laufende(): array
    {
        return array_map(self::aufbereitet(...), $this->pdo->query(self::SELECT . "WHERE b.status = 'laeuft' ORDER BY b.datum, b.id")->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return ?array<string,mixed> nächste nicht abgeschlossene Börse ab heute (frühestes Datum) */
    public function naechste(\DateTimeImmutable $heute): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . "WHERE b.status <> 'abgeschlossen' AND b.datum >= ? ORDER BY b.datum, b.id LIMIT 1");
        $st->execute([$heute->format('Y-m-d')]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::aufbereitet($z);
    }

    /** Status setzen (in der Transaktion des BoerseDienst nach der Sperre); abgeschlossen setzt abgeschlossen_am. */
    public function setzeStatus(int $id, string $status, ?\DateTimeImmutable $jetzt = null): void
    {
        $this->pdo->prepare('UPDATE boerse SET status = ?, abgeschlossen_am = ?, geaendert_am = geaendert_am WHERE id = ?')->execute([
            $status,
            $status === 'abgeschlossen' ? ($jetzt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            $id,
        ]);
    }

    /**
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function aufbereitet(array $z): array
    {
        foreach (['anzahl_angebote', 'anzahl_offen', 'anzahl_verkaeufe'] as $f) {
            $z[$f] = (int) $z[$f];
        }
        return $z;
    }
}
