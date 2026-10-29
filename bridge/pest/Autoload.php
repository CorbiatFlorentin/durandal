<?php

declare(strict_types=1);

/*
 * Attentes Pest de Durandal, chargées automatiquement par Composer. Sans Pest, ce fichier ne fait rien.
 *
 *     expect($client)->toHaveRetriedExactly(2)->toHaveNoDuplicateWrites();
 */

use Durandal\Bridge\Pest\JournalOf;
use Durandal\Bridge\PHPUnit\DurandalAssert;
use Durandal\Rule\RequestMatcher;
use Pest\Expectation;

if (!class_exists(Expectation::class)) {
    return;
}

// extend() enregistre l'attente pour toutes les instances ; on ne dépend pas de la fonction expect(),
// que Composer peut charger après ce fichier.
$expectation = new Expectation(null);

$expectation->extend('toHaveRetriedExactly', function (int $times, ?RequestMatcher $match = null): Expectation {
    DurandalAssert::retriedExactly($times, JournalOf::value($this->value), $match);

    return $this;
});

$expectation->extend('toHaveGivenUpAfter', function (int $attempts, ?RequestMatcher $match = null): Expectation {
    DurandalAssert::gaveUpAfter($attempts, JournalOf::value($this->value), $match);

    return $this;
});

$expectation->extend('toHaveNoDuplicateWrites', function (string $idempotencyHeader = 'Idempotency-Key'): Expectation {
    DurandalAssert::noDuplicateWrites(JournalOf::value($this->value), $idempotencyHeader);

    return $this;
});

$expectation->extend('toHaveRespectedRetryAfter', function (): Expectation {
    DurandalAssert::respectedRetryAfter(JournalOf::value($this->value));

    return $this;
});
