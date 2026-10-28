<?php

declare(strict_types=1);

/*
 * Échoue si la couverture des lignes d'un rapport Clover est sous le seuil.
 *
 * Usage : php tools/check-coverage.php build/coverage.xml 95
 */

[, $file, $threshold] = $argv + [null, null, null];

if (!is_string($file) || !is_numeric($threshold)) {
    fwrite(STDERR, "Usage : php tools/check-coverage.php <clover.xml> <seuil en %>\n");
    exit(2);
}

$xml = @simplexml_load_file($file);
$metrics = $xml === false ? null : $xml->project?->metrics;

if ($metrics === null || !isset($metrics['statements'], $metrics['coveredstatements'])) {
    fwrite(STDERR, sprintf("Rapport Clover illisible : %s\n", $file));
    exit(2);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$coverage = $statements === 0 ? 100.0 : $covered / $statements * 100;

printf("Couverture des lignes : %.2f %% (%d / %d), seuil : %s %%\n", $coverage, $covered, $statements, $threshold);

if ($coverage < (float) $threshold) {
    fwrite(STDERR, "La couverture est sous le seuil.\n");
    exit(1);
}
