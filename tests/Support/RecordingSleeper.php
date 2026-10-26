<?php

declare(strict_types=1);

namespace Durandal\Tests\Support;

use Durandal\Clock\Sleeper;

/**
 * Enregistre les latences demandées au lieu d'attendre.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleeps = [];

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
