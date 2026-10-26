<?php

declare(strict_types=1);

namespace Durandal\Rule;

use Durandal\Exception\InvalidConfiguration;

/**
 * Probabilité qu'une règle s'applique, entre 0 et 1.
 */
final readonly class Probability
{
    public function __construct(public float $value)
    {
        if (!($value >= 0.0 && $value <= 1.0)) {
            throw new InvalidConfiguration(\sprintf('La probabilité doit être dans [0, 1], %F reçu.', $value));
        }
    }

    public static function always(): self
    {
        return new self(1.0);
    }

    public function isCertain(): bool
    {
        return $this->value === 1.0;
    }

    public function isImpossible(): bool
    {
        return $this->value === 0.0;
    }
}
