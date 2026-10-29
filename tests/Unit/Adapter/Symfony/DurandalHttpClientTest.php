<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Adapter\Symfony;

use Closure;
use Durandal\Adapter\Settings;
use Durandal\Adapter\Symfony\DurandalHttpClient;
use Durandal\Adapter\Symfony\FailingResponse;
use Durandal\Adapter\Symfony\FaultTransport;
use Durandal\Adapter\Symfony\SimulatedConnectionRefused;
use Durandal\Adapter\Symfony\SimulatedTimeout;
use Durandal\Adapter\Symfony\SimulatedTransportException;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Journal\Entry;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use Durandal\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(DurandalHttpClient::class)]
#[CoversClass(FaultTransport::class)]
#[CoversClass(FailingResponse::class)]
#[CoversClass(SimulatedTransportException::class)]
#[CoversClass(SimulatedTimeout::class)]
#[CoversClass(SimulatedConnectionRefused::class)]
final class DurandalHttpClientTest extends TestCase
{
    private const URL = 'https://api.example.com';

    /** @var list<array{string, string, array<mixed>}> méthode, URL et options reçues par le faux serveur */
    private array $received = [];

    private ?MockResponse $backendResponse = null;

    public function testWithersReturnNewClientsAndKeepTheOriginalUntouched(): void
    {
        $client = $this->client(seed: 1);
        $rule = new Rule(new Timeout());

        $withRule = $client->withRule($rule);
        $withSeed = $client->withSeed(2);
        $withSleeper = $client->withSleeper(new RecordingSleeper());

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
        $client = $this->client()->withSleeper($sleeper)->latency(10)->withSeed(5)->failRate(1.0, 418);

        self::assertSame(418, $client->request('GET', self::URL)->getStatusCode());
        self::assertSame([10], $sleeper->sleeps);
        self::assertSame(5, $client->seed());
    }

    public function testUnsupportedFaultsAreRejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage("n'est pas prise en charge par Durandal");

        $this->client()->withRule(new Rule(new class implements Fault {}));
    }

    public function testFaultedAndRealResponsesStreamTogether(): void
    {
        $this->backendResponse = new MockResponse('0123456789', ['response_headers' => ['Content-Length' => '10', 'Content-Encoding' => 'identity']]);
        $client = $this->client()
            ->withRule(new Rule(new HttpError(503), RequestMatcher::any()->path('/error')))
            ->withRule(new Rule(new TruncatedBody(0.5), RequestMatcher::any()->path('/truncated')))
            ->withRule(new Rule(new MalformedBody('<html>'), RequestMatcher::any()->path('/malformed')));

        $responses = [];
        foreach (['ok', 'error', 'truncated', 'malformed'] as $path) {
            $responses[$path] = $client->request('GET', self::URL . '/' . $path);
        }
        // Un statut d'erreur non consulté lèverait une exception pendant le streaming, comme avec tout client Symfony.
        self::assertSame(503, $responses['error']->getStatusCode());

        self::assertSame(['ok' => '0123456789', 'error' => '', 'truncated' => '01234', 'malformed' => '<html>'], $this->streamContents($client, $responses));
        self::assertSame(['10'], $responses['truncated']->getHeaders()['content-length'] ?? null);
        self::assertArrayNotHasKey('content-length', $responses['malformed']->getHeaders());
        self::assertArrayNotHasKey('content-encoding', $responses['malformed']->getHeaders());
        self::assertCount(3, $this->received);
    }

    public function testSimulatedTimeoutsSurfaceAsTransportErrorsWhileStreaming(): void
    {
        $client = $this->client()->withRule(new Rule(new Timeout()));
        $response = $client->request('GET', self::URL . '/orders');

        try {
            foreach ($client->stream($response) as $chunk) {
                $chunk->getContent();
            }
            self::fail('Le délai dépassé simulé aurait dû être levé.');
        } catch (TransportExceptionInterface $e) {
            self::assertInstanceOf(SimulatedTimeout::class, $e->getPrevious());
            self::assertSame('Durandal : délai dépassé simulé(e) pour GET https://api.example.com/orders', $e->getMessage());
        }

        self::assertSame([], $this->received);
    }

    public function testRealTransportErrorsCrossTheBodyFaults(): void
    {
        $this->backendResponse = new MockResponse('', ['error' => 'connexion perdue']);

        foreach ([new TruncatedBody(), new MalformedBody()] as $fault) {
            $response = $this->client()->withRule(new Rule($fault))->request('GET', self::URL);

            try {
                $response->getContent();
                self::fail('L\'erreur du vrai transport aurait dû être levée.');
            } catch (TransportExceptionInterface $e) {
                self::assertStringContainsString('connexion perdue', $e->getMessage());
            }
        }
    }

    public function testRulesSeeHeadersInEveryFormAndHostsFromBaseUri(): void
    {
        $client = $this->client()->withRule(new Rule(
            new HttpError(418),
            RequestMatcher::any()->host('api.example.com')->path('/orders')->header('X-Key', 'k'),
        ));
        $relative = ['base_uri' => self::URL];

        self::assertSame(418, $client->request('GET', '/orders', $relative + ['headers' => ['X-Key: k']])->getStatusCode());
        self::assertSame(418, $client->request('GET', '/orders', $relative + ['headers' => ['x-key' => ['a', 'k']]])->getStatusCode());
        self::assertSame(200, $client->request('GET', '/orders', $relative + ['headers' => ['X-Key' => 'other']])->getStatusCode());
        self::assertSame(200, $client->request('GET', '/orders', ['headers' => ['X-Key' => 'k']] + ['base_uri' => 'https://other.example.com'])->getStatusCode());
    }

    public function testWithOptionsDecoratesTheConfiguredInnerClient(): void
    {
        $client = $this->client(seed: 3)->failRate(1.0, 502, RequestMatcher::any()->method('POST'));

        $configured = $client->withOptions(['headers' => ['X-Default' => 'yes']]);
        $configured->request('GET', self::URL)->getContent();

        self::assertNotSame($client, $configured);
        self::assertSame(3, $configured->seed());
        self::assertSame(502, $configured->request('POST', self::URL)->getStatusCode());
        self::assertContains('X-Default: yes', self::receivedHeaders($this->received[0][2]));
    }

    public function testTheJournalKeepsBodiesKnownInAdvance(): void
    {
        $client = $this->client();

        $client->request('POST', self::URL, ['body' => 'brut'])->getContent();
        $client->request('POST', self::URL, ['body' => ['a' => '1', 'b' => '2']])->getContent();
        $client->request('POST', self::URL, ['json' => ['id' => 1]])->getContent();
        $client->request('POST', self::URL, ['body' => static fn(): string => ''])->getContent();

        self::assertSame(
            ['brut', 'a=1&b=2', '{"id":1}', null],
            array_map(static fn(Entry $entry): ?string => $entry->body, $client->journal()->entries()),
        );
    }

    public function testResetIsForwardedToTheInnerClient(): void
    {
        $backend = new MockHttpClient(new MockResponse('ok'));
        $client = new DurandalHttpClient($backend, new Settings(1));

        $client->request('GET', self::URL)->getContent();
        $client->reset();

        self::assertSame(0, $backend->getRequestsCount());
    }

    public function testTheTransportForwardsOptionsToItsClient(): void
    {
        $backend = new MockHttpClient(fn(string $method, string $url, array $options): MockResponse => new MockResponse(implode("\n", self::receivedHeaders($options))));

        $transport = (new FaultTransport($backend))->withOptions(['headers' => ['X-Default' => 'yes']]);

        self::assertStringContainsString('X-Default: yes', $transport->request('GET', self::URL)->getContent());
    }

    /**
     * @return iterable<string, array{Closure(FailingResponse): mixed}>
     */
    public static function failingReads(): iterable
    {
        yield 'statut' => [static fn(FailingResponse $response): mixed => $response->getStatusCode()];
        yield 'en-têtes' => [static fn(FailingResponse $response): mixed => $response->getHeaders()];
        yield 'contenu' => [static fn(FailingResponse $response): mixed => $response->getContent()];
        yield 'tableau' => [static fn(FailingResponse $response): mixed => $response->toArray()];
    }

    /**
     * @param Closure(FailingResponse): mixed $read
     */
    #[DataProvider('failingReads')]
    public function testFailingResponsesFailOnEveryRead(Closure $read): void
    {
        $error = SimulatedConnectionRefused::for('GET', self::URL);

        $this->expectExceptionObject($error);

        $read(new FailingResponse($error));
    }

    public function testFailingResponsesHaveNoInfoAndCancelSilently(): void
    {
        $error = SimulatedConnectionRefused::for('GET', self::URL);
        $response = new FailingResponse($error);

        $response->cancel();

        self::assertSame([], $response->getInfo());
        self::assertNull($response->getInfo('http_code'));
        self::assertSame('Durandal : refus de connexion simulé(e) pour GET https://api.example.com', $error->getMessage());
    }

    private function client(int $seed = 42): DurandalHttpClient
    {
        $backend = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->received[] = [$method, $url, $options];

            return $this->backendResponse ?? new MockResponse('ok');
        });

        return new DurandalHttpClient($backend, new Settings($seed));
    }

    /**
     * @param array<string, ResponseInterface> $responses
     *
     * @return array<string, string>
     */
    private function streamContents(DurandalHttpClient $client, array $responses): array
    {
        $contents = array_fill_keys(array_keys($responses), '');
        foreach ($client->stream($responses) as $response => $chunk) {
            foreach ($responses as $key => $candidate) {
                if ($candidate === $response) {
                    $contents[$key] .= $chunk->getContent();
                }
            }
        }

        return $contents;
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private static function receivedHeaders(array $options): array
    {
        $headers = $options['headers'] ?? [];

        return \is_array($headers) ? array_values(array_filter($headers, \is_string(...))) : [];
    }
}
