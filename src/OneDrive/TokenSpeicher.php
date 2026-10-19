<?php

declare(strict_types=1);

namespace Emc\OneDrive;

use Emc\Betrieb\Einstellungen;
use Emc\Core\DomainFehler;
use Emc\Core\Tresor;

/** Hält das OneDrive-Token ausschließlich verschlüsselt in der Tabelle `einstellung`. */
class TokenSpeicher
{
    public const STATUS_VERBUNDEN = 'verbunden';
    public const STATUS_ABGELAUFEN = 'abgelaufen';
    public const STATUS_GETRENNT = 'getrennt';

    public function __construct(private Einstellungen $einstellungen, private Tresor $tresor)
    {
    }

    /** @param array{refresh_token:string,access_token:string,ablauf:int} $token */
    public function speichere(array $token): void
    {
        $this->einstellungen->setze('onedrive_token', $this->tresor->verschluessele(json_encode($token, JSON_THROW_ON_ERROR)));
        $this->setzeStatus(self::STATUS_VERBUNDEN);
    }

    /** @return array{refresh_token:string,access_token:string,ablauf:int}|null */
    public function lade(): ?array
    {
        $roh = $this->einstellungen->hole('onedrive_token');
        if ($roh === null || $roh === '') {
            return null;
        }
        try {
            $d = json_decode($this->tresor->entschluessele($roh), true);
        } catch (DomainFehler) {
            return null;
        }
        return is_array($d) && isset($d['refresh_token'], $d['access_token'], $d['ablauf']) ? $d : null;
    }

    public function loesche(): void
    {
        $this->einstellungen->setze('onedrive_token', '');
        $this->setzeStatus(self::STATUS_GETRENNT);
    }

    public function status(): string
    {
        return $this->einstellungen->hole('onedrive_status') ?? self::STATUS_GETRENNT;
    }

    public function setzeStatus(string $status): void
    {
        $this->einstellungen->setze('onedrive_status', $status);
    }
}
