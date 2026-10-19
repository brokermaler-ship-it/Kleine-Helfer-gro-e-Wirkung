<?php

declare(strict_types=1);

namespace Emc\OneDrive;

final readonly class HttpAntwort
{
    /** @param array<string,string> $kopf Header mit kleingeschriebenen Namen */
    public function __construct(public int $status, public array $kopf, public string $koerper)
    {
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $d = json_decode($this->koerper, true);
        return is_array($d) ? $d : [];
    }
}
