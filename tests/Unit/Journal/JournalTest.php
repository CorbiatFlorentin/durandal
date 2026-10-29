<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Journal;

use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Journal\ActiveJournals;
use Durandal\Journal\Entry;
use Durandal\Journal\Journal;
use Durandal\Rule\RequestMatcher;
use Durandal\Tests\Support\JournalBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Journal::class)]
#[CoversClass(Entry::class)]
#[CoversClass(ActiveJournals::class)]
final class JournalTest extends TestCase
{
    protected function setUp(): void
    {
        ActiveJournals::disable();
    }

    protected function tearDown(): void
    {
        ActiveJournals::disable();
    }

    public function testEntriesAreNumberedInOrder(): void
    {
        $journal = (new JournalBuilder())
            ->request('GET', '/a')
            ->request('POST', '/b', new HttpError(503), after: 250)
            ->journal;

        self::assertCount(2, $journal);
        self::assertSame([1, 2], array_map(static fn(Entry $entry): int => $entry->number, $journal->entries()));
        self::assertSame(['GET https://api.example.com/a', 'POST https://api.example.com/b'], array_map(static fn(Entry $entry): string => $entry->target(), iterator_to_array($journal)));
        self::assertSame(['POST https://api.example.com/b'], array_map(static fn(Entry $entry): string => $entry->target(), $journal->entries(RequestMatcher::any()->method('POST'))));
    }

    public function testDescribeShowsTheSeedAndEveryRequest(): void
    {
        $journal = (new JournalBuilder(seed: 7))
            ->request('POST', '/orders', new HttpError(503, 30), latency: 800)
            ->request('POST', '/orders', after: 1500)
            ->journal;

        self::assertSame(
            "Graine 7 (rejouer : DURANDAL_SEED=7)\n"
            . "#1  10:00:00.000  POST https://api.example.com/orders  (+800 ms)  → 503 simulé (Retry-After : 30 s)\n"
            . '#2  10:00:01.500  POST https://api.example.com/orders  → transmise au serveur',
            $journal->describe(),
        );
    }

    public function testAnEmptyJournalSaysSo(): void
    {
        self::assertSame("Graine 3 (rejouer : DURANDAL_SEED=3)\n(aucune requête)", (new Journal(3))->describe());
    }

    /**
     * @return iterable<string, array{?Fault, string, bool, bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'transmise' => [null, 'transmise au serveur', true, false];
        yield 'erreur HTTP' => [new HttpError(429), '429 simulé', false, true];
        yield 'délai dépassé' => [new Timeout(), 'délai dépassé simulé', false, true];
        yield 'refus de connexion' => [new ConnectionRefused(), 'refus de connexion simulé', false, true];
        yield 'corps tronqué' => [new TruncatedBody(0.25), 'transmise, corps tronqué à 25 %', true, true];
        yield 'corps invalide' => [new MalformedBody(), 'transmise, corps remplacé par un contenu invalide', true, true];
    }

    #[DataProvider('outcomes')]
    public function testEachOutcomeIsDescribed(?Fault $fault, string $outcome, bool $reachedServer, bool $failed): void
    {
        $entry = (new JournalBuilder())->request('GET', '/', $fault)->journal->entries()[0];

        self::assertStringEndsWith('→ ' . $outcome, $entry->describe());
        self::assertSame($reachedServer, $entry->reachedServer());
        self::assertSame($failed, $entry->failed());
        self::assertNull($entry->retryAfterSeconds());
    }

    public function testJournalsAreOnlyTrackedOnceEnabledAndUntilReset(): void
    {
        $untracked = (new JournalBuilder())->request('GET', '/')->journal;
        ActiveJournals::enable();
        $used = (new JournalBuilder())->request('GET', '/')->journal;
        new Journal(1);

        self::assertSame([$used], ActiveJournals::used());
        self::assertNotContains($untracked, ActiveJournals::used());

        ActiveJournals::reset();
        self::assertSame([], ActiveJournals::used());
    }
}
