<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Core\Session;

/**
 * Warenkorb einer Kasse in der Sitzung (Schlüssel „kasse_<boerseId>“, je Börse getrennt). Gespeichert werden nur
 * Angebots-ids und Verkaufspreise als DECIMAL-Zeichenkette – keine Angebotsdaten; Status und Bezeichnung liest die
 * Kassenseite jedes Mal frisch, verbindlich prüft erst KasseDienst::abschliessen unter Sperre.
 */
final class Warenkorb
{
    public const MAX_POSITIONEN = 100;

    private string $schluessel;

    public function __construct(private Session $session, int $boerseId)
    {
        $this->schluessel = 'kasse_' . $boerseId;
    }

    /** @return list<array{angebot_id:int, preis:string}> in der Reihenfolge des Hinzufügens */
    public function positionen(): array
    {
        $roh = $this->session->hole($this->schluessel, []);
        $ergebnis = [];
        foreach (is_array($roh) ? $roh : [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = filter_var($p['angebot_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $preis = $p['preis'] ?? null;
            if ($id === false || !is_string($preis) || !self::istPreis($preis)) {
                continue;
            }
            $ergebnis[] = ['angebot_id' => $id, 'preis' => $preis];
        }
        return $ergebnis;
    }

    public function enthaelt(int $angebotId): bool
    {
        foreach ($this->positionen() as $p) {
            if ($p['angebot_id'] === $angebotId) {
                return true;
            }
        }
        return false;
    }

    /** @return bool false, wenn das Angebot schon drin ist oder schon MAX_POSITIONEN Positionen drin sind */
    public function hinzu(int $angebotId, string $preis): bool
    {
        self::verlangePreis($preis);
        $liste = $this->positionen();
        if ($this->enthaelt($angebotId) || count($liste) >= self::MAX_POSITIONEN) {
            return false;
        }
        $liste[] = ['angebot_id' => $angebotId, 'preis' => $preis];
        $this->session->setze($this->schluessel, $liste);
        return true;
    }

    public function istVoll(): bool
    {
        return count($this->positionen()) >= self::MAX_POSITIONEN;
    }

    public function setzePreis(int $angebotId, string $preis): void
    {
        self::verlangePreis($preis);
        $liste = $this->positionen();
        foreach ($liste as $i => $p) {
            if ($p['angebot_id'] === $angebotId) {
                $liste[$i]['preis'] = $preis;
            }
        }
        $this->session->setze($this->schluessel, $liste);
    }

    public function entferne(int $angebotId): void
    {
        $liste = array_values(array_filter($this->positionen(), fn(array $p): bool => $p['angebot_id'] !== $angebotId));
        $this->session->setze($this->schluessel, $liste);
    }

    public function leere(): void
    {
        $this->session->entferne($this->schluessel);
    }

    public function summeCent(): int
    {
        $summe = 0;
        foreach ($this->positionen() as $p) {
            $summe += Geld::cent($p['preis']);
        }
        return $summe;
    }

    /** DECIMAL(10,2) als Zeichenkette ab 0, z. B. „12.50“ (aus ReparaturValidator::betragWert). */
    public static function istPreis(string $preis): bool
    {
        return preg_match('/^\d{1,8}\.\d{2}$/', $preis) === 1;
    }

    private static function verlangePreis(string $preis): void
    {
        if (!self::istPreis($preis)) {
            throw new \InvalidArgumentException('Preis als DECIMAL-Zeichenkette erwartet, z. B. 12.50.');
        }
    }
}
