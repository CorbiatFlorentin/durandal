<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Fault;

use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\TruncatedBody;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Latency::class)]
#[CoversClass(HttpError::class)]
#[CoversClass(TruncatedBody::class)]
#[CoversClass(MalformedBody::class)]
final class FaultValidationTest extends TestCase
{
    public function testLatencyAcceptsZero(): void
    {
        self::assertSame(0, (new Latency(0))->milliseconds);
    }

    public function testLatencyRejectsNegativeValues(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('-1 ms');

        new Latency(-1);
    }

    public function testHttpErrorDefaultsTo503WithoutRetryAfter(): void
    {
        $fault = new HttpError();

        self::assertSame(503, $fault->status);
        self::assertNull($fault->retryAfterSeconds);
    }

    public function testHttpErrorAcceptsTheBoundsOfErrorStatuses(): void
    {
        self::assertSame(400, (new HttpError(400))->status);
        self::assertSame(599, (new HttpError(599, 0))->status);
    }

    #[DataProvider('invalidStatuses')]
    public function testHttpErrorRejectsNonErrorStatuses(int $status): void
    {
        $this->expectException(InvalidConfiguration::class);

        new HttpError($status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatuses(): iterable
    {
        yield 'succès' => [200];
        yield 'juste avant 400' => [399];
        yield 'juste après 599' => [600];
    }

    public function testHttpErrorRejectsNegativeRetryAfter(): void
    {
        $this->expectException(InvalidConfiguration::class);

        new HttpError(429, -1);
    }

    public function testTruncatedBodyKeepsTheRequestedShareRoundedDown(): void
    {
        self::assertSame('01', (new TruncatedBody(0.25))->apply('0123456789'));
        self::assertSame('01234', (new TruncatedBody())->apply('0123456789'));
        self::assertSame('', (new TruncatedBody(0.0))->apply('0123456789'));
    }

    #[DataProvider('invalidRatios')]
    public function testTruncatedBodyRejectsRatiosOutsideZeroToOne(float $ratio): void
    {
        $this->expectException(InvalidConfiguration::class);

        new TruncatedBody($ratio);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidRatios(): iterable
    {
        yield 'négatif' => [-0.1];
        yield 'corps entier' => [1.0];
        yield 'NaN' => [\NAN];
    }

    public function testMalformedBodyDefaultsToInvalidJson(): void
    {
        self::assertSame(MalformedBody::DEFAULT_BODY, (new MalformedBody())->body);
        self::assertSame('<html>', (new MalformedBody('<html>'))->body);

        $this->expectException(JsonException::class);
        json_decode(MalformedBody::DEFAULT_BODY, flags: \JSON_THROW_ON_ERROR);
    }
}
