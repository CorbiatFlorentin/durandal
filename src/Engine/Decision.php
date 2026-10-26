<?php

declare(strict_types=1);

namespace Durandal\Engine;

use Durandal\Fault\Fault;

/**
 * Ce qui doit arriver à une requête : une latence totale, puis au plus une panne.
 */
final readonly class Decision
{
    public function __construct(
        public int $latencyMilliseconds = 0,
        public ?Fault $fault = null,
    ) {}
}
