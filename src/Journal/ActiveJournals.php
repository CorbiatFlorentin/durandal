<?php

declare(strict_types=1);

namespace Durandal\Journal;

/**
 * Journaux créés pendant le test en cours, pour que l'extension PHPUnit les affiche en cas d'échec.
 * Inactif tant que l'extension ne l'active pas : sans elle, rien n'est retenu.
 *
 * @internal
 */
final class ActiveJournals
{
    private static bool $enabled = false;

    /** @var list<Journal> */
    private static array $journals = [];

    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Cesse de retenir les journaux et oublie ceux déjà retenus.
     */
    public static function disable(): void
    {
        self::$enabled = false;
        self::$journals = [];
    }

    public static function track(Journal $journal): void
    {
        if (self::$enabled) {
            self::$journals[] = $journal;
        }
    }

    /**
     * Les journaux qui ont enregistré au moins une requête.
     *
     * @return list<Journal>
     */
    public static function used(): array
    {
        return array_values(array_filter(self::$journals, static fn(Journal $journal): bool => \count($journal) > 0));
    }

    public static function reset(): void
    {
        self::$journals = [];
    }
}
