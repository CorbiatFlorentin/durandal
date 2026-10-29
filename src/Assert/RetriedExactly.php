<?php

declare(strict_types=1);

namespace Durandal\Assert;

use Durandal\Journal\Journal;
use Durandal\Rule\RequestMatcher;

/**
 * Le code a réessayé exactement N fois. Chaque requête logique (même méthode, même URL) compte
 * ses tentatives au-delà de la première ; les réessais de toutes les requêtes visées s'additionnent.
 */
final readonly class RetriedExactly implements Expectation
{
    public function __construct(
        private int $times,
        private ?RequestMatcher $match = null,
    ) {}

    public function describe(): string
    {
        return \sprintf('%d réessai(s)', $this->times);
    }

    public function failure(Journal $journal): ?string
    {
        $attempts = [];
        foreach ($journal->entries($this->match) as $entry) {
            $attempts[$entry->target()] = ($attempts[$entry->target()] ?? 0) + 1;
        }

        $retries = array_sum($attempts) - \count($attempts);
        if ($retries === $this->times) {
            return null;
        }

        $detail = [];
        foreach ($attempts as $target => $count) {
            $detail[] = \sprintf('%s : %d tentative(s)', $target, $count);
        }

        return \sprintf(
            '%d réessai(s) attendu(s), %d constaté(s)%s',
            $this->times,
            $retries,
            $detail === [] ? ' (aucune requête visée).' : ' — ' . implode(', ', $detail) . '.',
        );
    }
}
