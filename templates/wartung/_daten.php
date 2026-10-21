<?php
/**
 * Entpackt die Daten des Wartungsblocks (WartungDienst::blockDaten) in die Variablen der Teil-Templates:
 * $objektTyp, $objekt, $wartungen, $arten, $ausnahmen, $personen, $darfEintragen, $darfPlan,
 * $werte, $fehler, $planWerte, $planFehler.
 *
 * @var array<string,mixed> $block
 */
$objektTyp = (string) $block['objektTyp'];
$objekt = $block['objekt'];
$wartungen = $block['wartungen'];
$arten = $block['arten'];
$ausnahmen = $block['ausnahmen'];
$personen = $block['personen'];
$darfEintragen = (bool) $block['darfEintragen'];
$darfPlan = (bool) $block['darfPlan'];
$werte = $block['werte'];
$fehler = $block['fehler'];
$planWerte = $block['planWerte'];
$planFehler = $block['planFehler'];
$basisUrl = ($objektTyp === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . (int) $objekt['id'];
$stundenText = fn(mixed $h): string => number_format((float) $h, 1, ',', '.') . ' h';
