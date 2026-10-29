<?php

declare(strict_types=1);

namespace Durandal\Assert;

use Durandal\Journal\Journal;
use Durandal\Rule\RequestMatcher;

/**
 * Le code a abandonné après exactement N tentatives, la dernière ayant échoué : il ne réessaie pas indéfiniment.
 * Ciblez la requête avec un RequestMatcher quand le code en envoie plusieurs.
 */
final readonly class GaveUpAfter implements Expectation
{
    public function __construct(
        private int $attempts,
        private ?RequestMatcher $match = null,
    ) {}

    public function describe(): string
    {
        return \sprintf('abandon après %d tentative(s)', $this->attempts);
    }

    public function failure(Journal $journal): ?string
    {
        $entries = $journal->entries($this->match);
        $last = $entries === [] ? null : $entries[\count($entries) - 1];

        if (\count($entries) !== $this->attempts) {
            return \sprintf('%d tentative(s) attendue(s) avant l\'abandon, %d constatée(s).', $this->attempts, \count($entries));
        }

        if ($last !== null && !$last->failed()) {
            return \sprintf('la dernière tentative (#%d) a réussi : le code n\'a pas eu à abandonner.', $last->number);
        }

        return null;
    }
}
