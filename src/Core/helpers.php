<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** @param array<string,mixed> $query */
    function url(string $pfad, array $query = []): string
    {
        $basis = rtrim((string) ($GLOBALS['emc_basis_url'] ?? ''), '/');
        $url = $basis . '/' . ltrim($pfad, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }
}
