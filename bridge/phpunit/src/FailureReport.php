<?php

declare(strict_types=1);

namespace Durandal\Bridge\PHPUnit;

use Durandal\Journal\ActiveJournals;
use Durandal\Journal\Journal;

/**
 * Journaux des tests en échec, affichés une fois tous les tests exécutés.
 *
 * @internal
 */
final class FailureReport
{
    /** @var array<string, string> test => journaux rendus */
    private array $failures = [];

    /**
     * Un test commence : les journaux du précédent ne le concernent plus.
     */
    public function testStarted(): void
    {
        ActiveJournals::reset();
    }

    /**
     * Un test a échoué : on retient les journaux des clients qui ont envoyé des requêtes.
     */
    public function testFailed(string $test): void
    {
        $this->record($test, ActiveJournals::used());
    }

    /**
     * @param list<Journal> $journals
     */
    public function record(string $test, array $journals): void
    {
        if ($journals !== []) {
            $this->failures[$test] = implode("\n\n", array_map(static fn(Journal $journal): string => $journal->describe(), $journals));
        }
    }

    public function render(): string
    {
        if ($this->failures === []) {
            return '';
        }

        $blocks = [];
        foreach ($this->failures as $test => $journals) {
            $blocks[] = $test . "\n" . preg_replace('/^/m', '  ', $journals);
        }

        return "\n\nDurandal — requêtes des tests en échec :\n\n" . implode("\n\n", $blocks) . "\n";
    }
}
