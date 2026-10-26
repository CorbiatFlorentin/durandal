<?php

declare(strict_types=1);

namespace Durandal\Rule;

use Durandal\Fault\Fault;

/**
 * Une panne, les requêtes qu'elle vise et la probabilité qu'elle se déclenche.
 */
final readonly class Rule
{
    public RequestMatcher $match;

    public Probability $probability;

    public function __construct(
        public Fault $fault,
        ?RequestMatcher $match = null,
        Probability|float $probability = 1.0,
    ) {
        $this->match = $match ?? RequestMatcher::any();
        $this->probability = $probability instanceof Probability ? $probability : new Probability($probability);
    }
}
