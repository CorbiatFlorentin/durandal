<?php

declare(strict_types=1);

namespace Durandal\Tests\Bridge\Pest;

use Durandal\Bridge\Pest\JournalOf;
use Durandal\Durandal;
use Durandal\Journal\Journal;
use Durandal\Tests\Support\StubClient;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(JournalOf::class)]
final class JournalOfTest extends TestCase
{
    public function testAcceptsAJournalOrADurandalClient(): void
    {
        $journal = new Journal(1);
        $factory = new HttpFactory();
        $psr18 = Durandal::wrap(new StubClient(), $factory, $factory);
        $symfony = Durandal::wrapSymfony(new MockHttpClient());

        self::assertSame($journal, JournalOf::value($journal));
        self::assertSame($psr18->journal(), JournalOf::value($psr18));
        self::assertSame($symfony->journal(), JournalOf::value($symfony));
    }

    public function testRejectsAnythingElse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Durandal : un journal ou un client Durandal est attendu, array reçu.');

        JournalOf::value([]);
    }
}
