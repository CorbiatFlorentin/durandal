<?php

declare(strict_types=1);

namespace Durandal\Tests\Bridge\PHPUnit;

use Durandal\Assert\RetriedExactly;
use Durandal\Bridge\PHPUnit\DurandalAssert;
use Durandal\Bridge\PHPUnit\FailureReport;
use Durandal\Bridge\PHPUnit\JournalSatisfies;
use Durandal\Fault\HttpError;
use Durandal\Journal\ActiveJournals;
use Durandal\Rule\RequestMatcher;
use Durandal\Tests\Support\JournalBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

#[CoversClass(DurandalAssert::class)]
#[CoversClass(JournalSatisfies::class)]
#[CoversClass(FailureReport::class)]
final class DurandalAssertTest extends TestCase
{
    public function testPassingAssertionsAreCounted(): void
    {
        $journal = (new JournalBuilder())
            ->request('POST', '/orders', new HttpError(503, 1), ['Idempotency-Key' => 'k'])
            ->request('POST', '/orders', new HttpError(503), ['Idempotency-Key' => 'k'], after: 1000)
            ->journal;
        $before = self::getCount();

        DurandalAssert::retriedExactly(1, $journal);
        DurandalAssert::retriedExactly(0, $journal, RequestMatcher::any()->method('GET'));
        DurandalAssert::gaveUpAfter(2, $journal);
        DurandalAssert::noDuplicateWrites($journal);
        DurandalAssert::respectedRetryAfter($journal);

        self::assertSame($before + 5, self::getCount());
    }

    public function testAFailureShowsTheReasonTheSeedAndTheJournal(): void
    {
        $journal = (new JournalBuilder(seed: 1234))->request('GET', '/orders', new HttpError(503))->journal;

        try {
            DurandalAssert::retriedExactly(2, $journal, message: 'la synchro doit réessayer');
            self::fail('L\'assertion aurait dû échouer.');
        } catch (ExpectationFailedException $e) {
            self::assertSame(
                "la synchro doit réessayer\n"
                . "Durandal : 2 réessai(s) attendu(s), 0 constaté(s) — GET https://api.example.com/orders : 1 tentative(s).\n"
                . "Graine 1234 (rejouer : DURANDAL_SEED=1234)\n"
                . '#1  10:00:00.000  GET https://api.example.com/orders  → 503 simulé',
                $e->getMessage(),
            );
        }
    }

    public function testEveryAssertionReportsItsFailure(): void
    {
        $journal = (new JournalBuilder())
            ->request('POST', '/orders', new HttpError(429, 5))
            ->request('POST', '/orders')
            ->journal;

        foreach ([
            'abandon' => static fn() => DurandalAssert::gaveUpAfter(2, $journal),
            'écriture' => static fn() => DurandalAssert::noDuplicateWrites($journal),
            'Retry-After' => static fn() => DurandalAssert::respectedRetryAfter($journal),
        ] as $expected => $assert) {
            try {
                $assert();
                self::fail('L\'assertion aurait dû échouer.');
            } catch (ExpectationFailedException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
                self::assertStringStartsWith('Durandal : ', $e->getMessage());
            }
        }
    }

    public function testTheConstraintNeedsAJournal(): void
    {
        $constraint = new JournalSatisfies(new RetriedExactly(1));

        self::assertSame('satisfait « 1 réessai(s) »', $constraint->toString());

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Durandal : un journal (Durandal\Journal\Journal) est attendu, string reçu.');

        self::assertThat('journal', $constraint);
    }

    public function testTheReportListsOnlyFailedTestsThatSentRequests(): void
    {
        $report = new FailureReport();
        self::assertSame('', $report->render());

        $report->record('Tests\SyncTest::testRetries', [(new JournalBuilder(seed: 5))->request('GET', '/a')->journal]);
        $report->record('Tests\SyncTest::testWithoutHttp', []);

        self::assertSame(
            "\n\nDurandal — requêtes des tests en échec :\n\n"
            . "Tests\\SyncTest::testRetries\n"
            . "  Graine 5 (rejouer : DURANDAL_SEED=5)\n"
            . "  #1  10:00:00.000  GET https://api.example.com/a  → transmise au serveur\n",
            $report->render(),
        );
    }

    public function testTheReportFollowsTheTestsLifecycle(): void
    {
        $report = new FailureReport();
        ActiveJournals::enable();

        try {
            (new JournalBuilder(seed: 11))->request('GET', '/previous-test');
            $report->testStarted();
            (new JournalBuilder(seed: 12))->request('GET', '/current-test');
            $report->testFailed('Tests\SyncTest::testCurrent');
        } finally {
            ActiveJournals::disable();
        }

        self::assertStringContainsString("Tests\\SyncTest::testCurrent\n  Graine 12", $report->render());
        self::assertStringNotContainsString('Graine 11', $report->render());
    }

    public function testTheExtensionPrintsTheJournalsOfFailedTests(): void
    {
        $command = \sprintf(
            '%s %s -c %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 3) . '/vendor/phpunit/phpunit/phpunit'),
            escapeshellarg(__DIR__ . '/Fixtures/phpunit.xml'),
        );
        exec($command, $lines, $exitCode);
        $output = implode("\n", $lines);
        self::assertNotSame(0, $exitCode, $output);
        self::assertSame(1, preg_match('/Durandal — requêtes des tests en échec :\n\n(.*?)\nTime:/s', $output, $match), $output);
        $report = $match[1];

        self::assertStringContainsString("FailingSuite::testFailsAfterRetrying\n  Graine 1234 (rejouer : DURANDAL_SEED=1234)\n  #1 ", $report);
        self::assertStringContainsString('POST https://api.example.com/orders  → 503 simulé', $report);
        self::assertStringContainsString("FailingSuite::testErrors\n  Graine 7", $report);
        self::assertStringNotContainsString('testPasses', $report);
        self::assertStringNotContainsString('testFailsWithoutRequests', $report);
    }
}
