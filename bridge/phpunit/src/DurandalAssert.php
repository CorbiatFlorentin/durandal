<?php

declare(strict_types=1);

namespace Durandal\Bridge\PHPUnit;

use Durandal\Assert\GaveUpAfter;
use Durandal\Assert\NoDuplicateWrites;
use Durandal\Assert\RespectedRetryAfter;
use Durandal\Assert\RetriedExactly;
use Durandal\Journal\Journal;
use Durandal\Rule\RequestMatcher;
use PHPUnit\Framework\Assert;

/**
 * Assertions de résilience sur le journal d'un client Durandal ($client->journal()).
 * Un échec affiche la raison, la graine et le journal des requêtes.
 */
final class DurandalAssert
{
    /**
     * Le code a réessayé exactement $times fois (tentatives au-delà de la première, par méthode et URL).
     */
    public static function retriedExactly(int $times, Journal $journal, ?RequestMatcher $match = null, string $message = ''): void
    {
        Assert::assertThat($journal, new JournalSatisfies(new RetriedExactly($times, $match)), $message);
    }

    /**
     * Le code a abandonné après exactement $attempts tentatives, la dernière ayant échoué.
     */
    public static function gaveUpAfter(int $attempts, Journal $journal, ?RequestMatcher $match = null, string $message = ''): void
    {
        Assert::assertThat($journal, new JournalSatisfies(new GaveUpAfter($attempts, $match)), $message);
    }

    /**
     * Chaque écriture (POST, PATCH) envoyée plusieurs fois a porté la même clé d'idempotence.
     */
    public static function noDuplicateWrites(Journal $journal, string $idempotencyHeader = 'Idempotency-Key', string $message = ''): void
    {
        Assert::assertThat($journal, new JournalSatisfies(new NoDuplicateWrites($idempotencyHeader)), $message);
    }

    /**
     * Après chaque Retry-After simulé, la tentative suivante a attendu au moins le délai demandé.
     */
    public static function respectedRetryAfter(Journal $journal, string $message = ''): void
    {
        Assert::assertThat($journal, new JournalSatisfies(new RespectedRetryAfter()), $message);
    }
}
