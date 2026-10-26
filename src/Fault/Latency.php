<?php

declare(strict_types=1);

namespace Durandal\Fault;

use Durandal\Exception\InvalidConfiguration;

/**
 * Retarde la requête avant de la laisser passer. Se cumule avec les autres latences
 * et peut précéder une autre panne.
 */
final readonly class Latency implements Fault
{
    public function __construct(public int $milliseconds)
    {
        if ($milliseconds < 0) {
            throw new InvalidConfiguration(\sprintf('La latence doit être positive ou nulle, %d ms reçu.', $milliseconds));
        }
    }
}
