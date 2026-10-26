<?php

declare(strict_types=1);

namespace Durandal\Random;

use Durandal\Rule\Probability;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Unique source d'aléatoire de Durandal : même graine, même séquence de tirages.
 *
 * Seules les probabilités strictement comprises entre 0 et 1 consomment un tirage, si bien qu'ajouter
 * une règle certaine ou impossible ne décale pas la séquence des autres.
 */
final class SeededRandomizer
{
    private const RESOLUTION = 1_000_000;

    private readonly Randomizer $randomizer;

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar($seed));
    }

    public function chance(Probability $probability): bool
    {
        if ($probability->isImpossible()) {
            return false;
        }

        if ($probability->isCertain()) {
            return true;
        }

        return $this->randomizer->getInt(1, self::RESOLUTION) <= (int) round($probability->value * self::RESOLUTION);
    }
}
