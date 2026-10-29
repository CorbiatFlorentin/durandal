<?php

declare(strict_types=1);

namespace Durandal\Assert;

use Durandal\Journal\Journal;

/**
 * Une propriété de résilience vérifiée sur un journal, indépendamment de tout outil de test :
 * les ponts PHPUnit et Pest en font des assertions.
 */
interface Expectation
{
    /**
     * Ce qui est attendu, en une phrase.
     */
    public function describe(): string;

    /**
     * Pourquoi le journal ne satisfait pas l'attente, ou null s'il la satisfait.
     */
    public function failure(Journal $journal): ?string;
}
