<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Adapter\Psr18;

use Durandal\Adapter\Psr18\DurandalClient;
use Durandal\Adapter\Psr18\SimulatedConnectionRefused;
use Durandal\Adapter\Psr18\SimulatedNetworkException;
use Durandal\Adapter\Psr18\SimulatedTimeout;
use Durandal\Clock\NullSleeper;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use Durandal\Tests\Support\RecordingSleeper;
use Durandal\Tests\Support\StubClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;

#[CoversClass(DurandalClient::class)]
#[CoversClass(SimulatedNetworkException::class)]
#[CoversClass(SimulatedTimeout::class)]
#[CoversClass(SimulatedConnectionRefused::class)]
#[CoversClass(NullSleeper::class)]
final class DurandalClientTest extends TestCase
{
    public function testWithersReturnNewClientsAndKeepTheOriginalUntouched(): void
    {
        $client = self::client(seed: 1);
        $rule = new Rule(new Timeout());
        $sleeper = new RecordingSleeper();

        $withRule = $client->withRule($rule);
        $withSeed = $client->withSeed(2);
        $withSleeper = $client->withSleeper($sleeper);

        self::assertNotSame($client, $withRule);
        self::assertSame([$rule], $withRule->rules());
        self::assertSame(1, $withRule->seed());
        self::assertSame(2, $withSeed->seed());
        self::assertSame([], $withSeed->rules());
        self::assertSame(1, $withSleeper->seed());
        self::assertSame([], $client->rules());
        self::assertSame(1, $client->seed());
    }

    public function testWithersKeepTheOtherSettings(): void
    {
        $sleeper = new RecordingSleeper();
        $client = self::client()->withSleeper($sleeper)->latency(10)->withSeed(5)->withRule(new Rule(new HttpError(418)));

        $response = $client->sendRequest(self::request());

        self::assertSame(418, $response->getStatusCode());
        self::assertSame([10], $sleeper->sleeps);
        self::assertSame(5, $client->seed());
    }

    public function testLatencyShortcutOnlyTargetsMatchingRequests(): void
    {
        $sleeper = new RecordingSleeper();
        $client = self::client()->withSleeper($sleeper)->latency(800, RequestMatcher::any()->method('POST'));

        $client->sendRequest(self::request('GET'));
        $client->sendRequest(self::request('POST'));

        self::assertSame([800], $sleeper->sleeps);
    }

    public function testZeroLatencyDoesNotCallTheSleeper(): void
    {
        $sleeper = new RecordingSleeper();

        self::client()->withSleeper($sleeper)->withRule(new Rule(new Latency(0)))->sendRequest(self::request());

        self::assertSame([], $sleeper->sleeps);
    }

    public function testFailRateShortcut(): void
    {
        $client = self::client()->failRate(1.0, 429, RequestMatcher::any()->path('/limited'));

        self::assertSame(429, $client->sendRequest(self::request('GET', 'https://api.example.com/limited'))->getStatusCode());
        self::assertSame(200, $client->sendRequest(self::request('GET', 'https://api.example.com/free'))->getStatusCode());
        self::assertSame(503, self::client()->failRate(1.0)->sendRequest(self::request())->getStatusCode());
    }

    public function testDefaultSleeperDoesNotWait(): void
    {
        $start = hrtime(true);

        self::client()->latency(5_000)->sendRequest(self::request());

        self::assertLessThan(1_000_000_000, hrtime(true) - $start);
    }

    public function testSimulatedNetworkErrorsArePsr18NetworkExceptions(): void
    {
        $request = self::request('DELETE', 'https://api.example.com/orders/1');

        try {
            self::client()->withRule(new Rule(new Timeout()))->sendRequest($request);
            self::fail('Un délai dépassé était attendu.');
        } catch (NetworkExceptionInterface $exception) {
            self::assertInstanceOf(SimulatedTimeout::class, $exception);
            self::assertSame($request, $exception->getRequest());
            self::assertSame('Durandal : délai dépassé simulé(e) pour DELETE https://api.example.com/orders/1', $exception->getMessage());
        }

        $this->expectException(SimulatedConnectionRefused::class);
        $this->expectExceptionMessage('Durandal : refus de connexion simulé(e) pour DELETE');
        self::client()->withRule(new Rule(new ConnectionRefused()))->sendRequest($request);
    }

    public function testUnsupportedFaultsAreRejectedAtConfigurationTime(): void
    {
        $custom = new class implements Fault {};

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('pas prise en charge');

        self::client()->withRule(new Rule($custom));
    }

    public function testHostFallsBackToTheHostHeaderForRelativeUris(): void
    {
        $client = self::client()->failRate(1.0, 503, RequestMatcher::any()->host('api.example.com'));
        $relative = (new Request('GET', '/orders'))->withHeader('Host', 'api.example.com:8443');

        self::assertSame(503, $client->sendRequest($relative)->getStatusCode());
        self::assertSame(200, $client->sendRequest(new Request('GET', '/orders'))->getStatusCode());
    }

    public function testMalformedBodyAlsoDropsContentEncoding(): void
    {
        $inner = new StubClient(new Response(200, ['Content-Encoding' => 'gzip', 'Content-Length' => '42'], 'compressé'));
        $factory = new HttpFactory();
        $client = (new DurandalClient($inner, $factory, $factory, 42))->withRule(new Rule(new MalformedBody()));

        $response = $client->sendRequest(self::request());

        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame(MalformedBody::DEFAULT_BODY, (string) $response->getBody());
    }

    private static function client(int $seed = 42): DurandalClient
    {
        $factory = new HttpFactory();

        return new DurandalClient(new StubClient(), $factory, $factory, $seed);
    }

    private static function request(string $method = 'GET', string $uri = 'https://api.example.com/orders'): Request
    {
        return new Request($method, $uri);
    }
}
