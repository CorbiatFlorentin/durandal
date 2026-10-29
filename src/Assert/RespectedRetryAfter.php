<?php

declare(strict_types=1);

namespace Durandal\Assert;

use Durandal\Journal\Entry;
use Durandal\Journal\Journal;

/**
 * Après une réponse simulée portant Retry-After, la tentative suivante de la même requête a attendu au moins
 * le délai demandé. Les instants viennent de l'horloge du client : donnez-lui celle qu'utilise le code testé.
 */
final readonly class RespectedRetryAfter implements Expectation
{
    public function describe(): string
    {
        return 'respect de Retry-After';
    }

    public function failure(Journal $journal): ?string
    {
        $entries = $journal->entries();
        $checked = 0;
        $problems = [];

        foreach ($entries as $index => $entry) {
            $delay = $entry->retryAfterSeconds();
            $next = $delay === null ? null : self::nextAttempt($entries, $index);
            if ($delay === null || $next === null) {
                continue;
            }

            ++$checked;
            $waited = (float) $next->at->format('U.u') - (float) $entry->at->format('U.u');
            if ($waited < $delay) {
                $problems[] = \sprintf('#%d relancée %.3f s après #%d, qui demandait %d s', $next->number, $waited, $entry->number, $delay);
            }
        }

        if ($checked === 0) {
            return 'aucune réponse Retry-After n\'a été suivie d\'une nouvelle tentative : rien à vérifier.';
        }

        return $problems === [] ? null : 'Retry-After non respecté : ' . implode(' ; ', $problems) . '.';
    }

    /**
     * @param list<Entry> $entries
     */
    private static function nextAttempt(array $entries, int $index): ?Entry
    {
        foreach (\array_slice($entries, $index + 1) as $candidate) {
            if ($candidate->target() === $entries[$index]->target()) {
                return $candidate;
            }
        }

        return null;
    }
}
