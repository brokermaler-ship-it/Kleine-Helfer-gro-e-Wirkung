<?php
/** @var array<string,mixed> $fahrzeug */
/** @var list<array<string,mixed>> $dateien */
$uploadUrl = url('/fahrzeuge/' . $fahrzeug['id'] . '/dateien');
$titelbildUrl = url('/fahrzeuge/' . $fahrzeug['id'] . '/titelbild');
$darfDateien = $darfFz($fahrzeug);
$titelbildId = $fahrzeug['titelbild_dokument_id'];
include dirname(__DIR__) . '/partials/dateien.php';
