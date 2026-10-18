<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

use Emc\Core\Request;

final class FahrzeugFilter
{
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?int $spurweiteId = null,
        public readonly ?string $eigentuemer = null,
        public readonly ?int $personId = null,
        public readonly ?string $status = null,
        public readonly ?string $gattung = null,
    ) {
    }

    public static function ausRequest(Request $r): self
    {
        $text = trim($r->text('q'));
        $eigentuemer = $r->text('eigentuemer');
        $status = $r->text('status');
        $gattung = $r->text('gattung');
        return new self(
            $text === '' ? null : mb_substr($text, 0, 100),
            $r->ganzzahl('spurweite', 1, 2147483647),
            in_array($eigentuemer, ['verein', 'mitglied'], true) ? $eigentuemer : null,
            $r->ganzzahl('person', 1, 2147483647),
            in_array($status, FahrzeugValidator::STATUS, true) ? $status : null,
            in_array($gattung, FahrzeugValidator::GATTUNGEN, true) ? $gattung : null,
        );
    }

    public function istGesetzt(): bool
    {
        return $this->text !== null || $this->spurweiteId !== null || $this->eigentuemer !== null
            || $this->personId !== null || $this->status !== null || $this->gattung !== null;
    }
}
