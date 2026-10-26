<?php

declare(strict_types=1);

namespace Durandal\Clock;

/**
 * Applique une latence simulée. Durandal n'attend jamais réellement par défaut (NullSleeper) ;
 * l'horloge virtuelle de la phase 4 implémentera aussi cette interface.
 */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
