<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Assert;

use Durandal\Assert\GaveUpAfter;
use Durandal\Assert\NoDuplicateWrites;
use Durandal\Assert\RespectedRetryAfter;
use Durandal\Assert\RetriedExactly;
use Durandal\Fault\HttpError;
use Durandal\Fault\Timeout;
use Durandal\Rule\RequestMatcher;
use Durandal\Tests\Support\JournalBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetriedExactly::class)]
#[CoversClass(GaveUpAfter::class)]
#[CoversClass(NoDuplicateWrites::class)]
#[CoversClass(RespectedRetryAfter::class)]
final class ExpectationsTest extends TestCase
{
    public function testRetriesAreCountedPerRequestAndAddedUp(): void
    {
        $journal = (new JournalBuilder())
            ->request('GET', '/a', new HttpError())
            ->request('GET', '/a', new HttpError())
            ->request('GET', '/a')
            ->request('GET', '/b', new Timeout())
            ->request('GET', '/b')
            ->request('POST', '/c')
            ->journal;

        self::assertNull((new RetriedExactly(3))->failure($journal));
        self::assertNull((new RetriedExactly(2, RequestMatcher::any()->path('/a')))->failure($journal));
        self::assertSame(
            '2 réessai(s) attendu(s), 3 constaté(s) — GET https://api.example.com/a : 3 tentative(s), GET https://api.example.com/b : 2 tentative(s), POST https://api.example.com/c : 1 tentative(s).',
            (new RetriedExactly(2))->failure($journal),
        );
        self::assertSame('1 réessai(s) attendu(s), 0 constaté(s) (aucune requête visée).', (new RetriedExactly(1, RequestMatcher::any()->host('none')))->failure($journal));
        self::assertSame('3 réessai(s)', (new RetriedExactly(3))->describe());
    }

    public function testGivingUpRequiresTheExactAttemptsAndAFailedLastOne(): void
    {
        $gaveUp = (new JournalBuilder())->request('GET', '/a', new HttpError())->request('GET', '/a', new Timeout())->journal;
        $succeeded = (new JournalBuilder())->request('GET', '/a', new HttpError())->request('GET', '/a')->journal;

        self::assertNull((new GaveUpAfter(2))->failure($gaveUp));
        self::assertSame('3 tentative(s) attendue(s) avant l\'abandon, 2 constatée(s).', (new GaveUpAfter(3))->failure($gaveUp));
        self::assertSame('la dernière tentative (#2) a réussi : le code n\'a pas eu à abandonner.', (new GaveUpAfter(2))->failure($succeeded));
        self::assertSame('1 tentative(s) attendue(s) avant l\'abandon, 0 constatée(s).', (new GaveUpAfter(1, RequestMatcher::any()->method('DELETE')))->failure($gaveUp));
        self::assertNull((new GaveUpAfter(0))->failure((new JournalBuilder())->journal));
        self::assertSame('abandon après 2 tentative(s)', (new GaveUpAfter(2))->describe());
    }

    public function testRetriedWritesMustKeepTheSameIdempotencyKey(): void
    {
        $safe = (new JournalBuilder())
            ->request('POST', '/orders', new HttpError(), ['Idempotency-Key' => 'k1'], '{"id":1}')
            ->request('POST', '/orders', null, ['Idempotency-Key' => 'k1'], '{"id":1}')
            ->request('POST', '/orders', null, [], '{"id":2}')
            ->request('PUT', '/orders/1')
            ->request('PUT', '/orders/1')
            ->journal;

        self::assertNull((new NoDuplicateWrites())->failure($safe));
        self::assertSame('aucune écriture en double (en-tête Idempotency-Key)', (new NoDuplicateWrites())->describe());
    }

    public function testWritesSentTwiceWithoutOrWithDifferentKeysAreReported(): void
    {
        $journal = (new JournalBuilder())
            ->request('POST', '/orders', new HttpError(), ['X-Request-Id' => 'a'])
            ->request('POST', '/orders', null, ['X-Request-Id' => 'b'])
            ->request('PATCH', '/orders/1', new Timeout(), ['X-Request-Id' => 'c'])
            ->request('PATCH', '/orders/1')
            ->journal;

        self::assertSame(
            'écriture(s) en double possible(s) : '
            . 'POST https://api.example.com/orders envoyée 2 fois (#1, #2) avec des X-Request-Id différentes ; '
            . 'PATCH https://api.example.com/orders/1 envoyée 2 fois (#3, #4) sans X-Request-Id à chaque tentative.',
            (new NoDuplicateWrites('X-Request-Id'))->failure($journal),
        );
    }

    public function testTheNextAttemptMustWaitForRetryAfter(): void
    {
        $patient = (new JournalBuilder())
            ->request('GET', '/a', new HttpError(429, 2))
            ->request('GET', '/b', after: 100)
            ->request('GET', '/a', after: 1900)
            ->journal;
        $hasty = (new JournalBuilder())
            ->request('GET', '/a', new HttpError(429, 2))
            ->request('GET', '/a', after: 1500)
            ->journal;

        self::assertNull((new RespectedRetryAfter())->failure($patient));
        self::assertSame('Retry-After non respecté : #2 relancée 1.500 s après #1, qui demandait 2 s.', (new RespectedRetryAfter())->failure($hasty));
        self::assertSame('respect de Retry-After', (new RespectedRetryAfter())->describe());
    }

    public function testRetryAfterNeedsSomethingToCheck(): void
    {
        $lastAttempt = (new JournalBuilder())->request('GET', '/a', new HttpError(429, 2))->request('GET', '/b', new HttpError(503))->journal;

        self::assertSame(
            'aucune réponse Retry-After n\'a été suivie d\'une nouvelle tentative : rien à vérifier.',
            (new RespectedRetryAfter())->failure($lastAttempt),
        );
    }
}
