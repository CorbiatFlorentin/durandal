<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit;

use Durandal\Durandal;
use Durandal\Tests\Support\StubClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(Durandal::class)]
final class DurandalTest extends TestCase
{
    public function testVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-dev)?$/', Durandal::version());
    }

    public function testWrapDiscoversPsr17FactoriesAndDrawsASeed(): void
    {
        $client = Durandal::wrap(new StubClient());

        self::assertGreaterThanOrEqual(0, $client->seed());
        self::assertSame([], $client->rules());
    }

    public function testWrapSymfonyDrawsASeed(): void
    {
        $client = Durandal::wrapSymfony(new MockHttpClient());

        self::assertGreaterThanOrEqual(0, $client->seed());
        self::assertSame([], $client->rules());
    }

    public function testWrapUsesTheGivenFactories(): void
    {
        $factory = new HttpFactory();

        $response = Durandal::wrap(new StubClient(), $factory, $factory)
            ->failRate(1.0, 502)
            ->sendRequest(new Request('GET', 'https://example.com/'));

        self::assertSame(502, $response->getStatusCode());
    }
}
