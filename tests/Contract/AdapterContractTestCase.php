<?php

declare(strict_types=1);

namespace Durandal\Tests\Contract;

use Durandal\Clock\Sleeper;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use Durandal\Scenario\Scenario;
use Durandal\Tests\Support\FrozenClock;
use Durandal\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Suite de contrat : chaque adaptateur (PSR-18, Symfony HttpClient…) doit se comporter exactement de la même façon.
 * Un adaptateur s'y soumet en héritant de cette classe et en fournissant un faux serveur et un client Durandal.
 */
abstract class AdapterContractTestCase extends TestCase
{
    protected const SEED = 42;
    protected const URL = 'https://api.example.com/orders';
    protected const BODY = '{"orders":[1,2,3]}';

    protected RecordingSleeper $sleeper;

    protected FrozenClock $clock;

    /**
     * Le faux serveur répondra désormais avec ce statut, ces en-têtes et ce corps.
     *
     * @param array<string, string> $headers
     */
    abstract protected function backendResponds(int $status, string $body, array $headers): void;

    /**
     * Nombre de requêtes réellement parvenues au faux serveur.
     */
    abstract protected function backendCalls(): int;

    /**
     * @param list<Rule> $rules
     * @param list<Scenario> $scenarios
     */
    abstract protected function durandal(array $rules, int $seed, Sleeper $sleeper, array $scenarios, ClockInterface $clock): ContractClient;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
        $this->clock = new FrozenClock();
        $this->backendResponds(200, self::BODY, ['Content-Type' => 'application/json', 'Content-Length' => (string) \strlen(self::BODY)]);
    }

    public function testScenariosPlayBeforeRules(): void
    {
        $client = $this->client([new Rule(new Latency(100))], scenarios: [Scenario::failTimes(2, status: 502)->thenSucceed()]);

        self::assertSame([502, 502, 200], [$client->send('GET', self::URL)->status, $client->send('GET', self::URL)->status, $client->send('GET', self::URL)->status]);
        self::assertSame([100, 100, 100], $this->sleeper->sleeps);
        self::assertSame(1, $this->backendCalls());
    }

    public function testDurationsAreMeasuredWithTheClientClock(): void
    {
        $client = $this->client([], scenarios: [Scenario::offlineFor(5)]);

        $offline = $client->send('GET', self::URL);
        $this->clock->advance(4_999);
        $stillOffline = $client->send('GET', self::URL);
        $this->clock->advance(1);

        self::assertSame(ContractResult::CONNECTION_REFUSED, $offline->error);
        self::assertSame(ContractResult::CONNECTION_REFUSED, $stillOffline->error);
        self::assertSame(200, $client->send('GET', self::URL)->status);
    }

    public function testTheJournalRecordsEveryRequestWithItsFate(): void
    {
        $client = $this->client([new Rule(new HttpError(503, retryAfterSeconds: 2), RequestMatcher::any()->method('POST')), new Rule(new Latency(300))]);

        $client->send('POST', self::URL, ['Idempotency-Key' => 'k-1']);
        $this->clock->advance(2_000);
        $client->send('GET', self::URL . '?page=2');

        $entries = $client->journal()->entries();
        self::assertSame(self::SEED, $client->journal()->seed);
        self::assertCount(2, $entries);
        self::assertSame('POST ' . self::URL, $entries[0]->target());
        self::assertSame(['k-1'], $entries[0]->request->header('Idempotency-Key'));
        self::assertSame(2, $entries[0]->retryAfterSeconds());
        self::assertSame(300, $entries[0]->latencyMilliseconds);
        self::assertSame('10:00:00', $entries[0]->at->format('H:i:s'));
        self::assertSame('GET ' . self::URL . '?page=2', $entries[1]->target());
        self::assertNull($entries[1]->fault);
        self::assertSame('10:00:02', $entries[1]->at->format('H:i:s'));
    }

    public function testWithoutRulesTheRequestPassesThrough(): void
    {
        $result = $this->client([])->send('GET', self::URL);

        self::assertSame(200, $result->status);
        self::assertSame(self::BODY, $result->body);
        self::assertSame('application/json', $result->headers['content-type'] ?? null);
        self::assertSame(1, $this->backendCalls());
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function testHttpErrorAnswersWithoutReachingTheServer(): void
    {
        $result = $this->client([new Rule(new HttpError(503, retryAfterSeconds: 30))])->send('POST', self::URL);

        self::assertSame(503, $result->status);
        self::assertSame('30', $result->headers['retry-after'] ?? null);
        self::assertSame(0, $this->backendCalls());
    }

    public function testHttpErrorWithoutRetryAfterSendsNoRetryAfterHeader(): void
    {
        $result = $this->client([new Rule(new HttpError(500))])->send('GET', self::URL);

        self::assertSame(500, $result->status);
        self::assertArrayNotHasKey('retry-after', $result->headers);
    }

    public function testTimeoutIsANetworkErrorWithoutReachingTheServer(): void
    {
        $result = $this->client([new Rule(new Timeout())])->send('GET', self::URL);

        self::assertSame(ContractResult::TIMEOUT, $result->error);
        self::assertNull($result->status);
        self::assertSame(0, $this->backendCalls());
    }

    public function testConnectionRefusedIsANetworkErrorWithoutReachingTheServer(): void
    {
        $result = $this->client([new Rule(new ConnectionRefused())])->send('GET', self::URL);

        self::assertSame(ContractResult::CONNECTION_REFUSED, $result->error);
        self::assertSame(0, $this->backendCalls());
    }

    public function testLatencyIsAppliedThenTheRequestPassesThrough(): void
    {
        $result = $this->client([new Rule(new Latency(800))])->send('GET', self::URL);

        self::assertSame([800], $this->sleeper->sleeps);
        self::assertSame(200, $result->status);
        self::assertSame(1, $this->backendCalls());
    }

    public function testLatenciesAddUpBeforeAnotherFault(): void
    {
        $result = $this->client([new Rule(new Latency(100)), new Rule(new Latency(200)), new Rule(new HttpError(502))])
            ->send('GET', self::URL);

        self::assertSame([300], $this->sleeper->sleeps);
        self::assertSame(502, $result->status);
    }

    public function testTruncatedBodyCutsTheRealResponseAndKeepsItsHeaders(): void
    {
        $this->backendResponds(200, '0123456789', ['Content-Length' => '10']);

        $result = $this->client([new Rule(new TruncatedBody(0.5))])->send('GET', self::URL);

        self::assertSame(200, $result->status);
        self::assertSame('01234', $result->body);
        self::assertSame('10', $result->headers['content-length'] ?? null);
        self::assertSame(1, $this->backendCalls());
    }

    public function testMalformedBodyReplacesTheRealBody(): void
    {
        $result = $this->client([new Rule(new MalformedBody())])->send('GET', self::URL);

        self::assertSame(200, $result->status);
        self::assertSame(MalformedBody::DEFAULT_BODY, $result->body);
        self::assertArrayNotHasKey('content-length', $result->headers);
        self::assertSame(1, $this->backendCalls());
    }

    public function testRulesOnlyTargetMatchingRequests(): void
    {
        $client = $this->client([
            new Rule(new Timeout(), RequestMatcher::any()->host('other.example.com')),
            new Rule(new HttpError(503), RequestMatcher::any()->method('POST')->path('/orders')->header('Idempotency-Key')),
        ]);

        self::assertSame(200, $client->send('GET', self::URL)->status);
        self::assertSame(200, $client->send('POST', self::URL)->status);
        self::assertSame(503, $client->send('POST', self::URL, ['Idempotency-Key' => 'k-1'])->status);
        self::assertSame(ContractResult::TIMEOUT, $client->send('GET', 'https://other.example.com/')->error);
    }

    public function testFirstFaultWins(): void
    {
        $result = $this->client([new Rule(new Timeout()), new Rule(new HttpError())])->send('GET', self::URL);

        self::assertSame(ContractResult::TIMEOUT, $result->error);
    }

    public function testProbabilisticFaultsAreReproducibleWithTheSameSeed(): void
    {
        $rules = [new Rule(new HttpError(503), probability: 0.3)];

        $first = $this->statuses($this->client($rules), 200);
        $second = $this->statuses($this->client($rules), 200);
        $otherSeed = $this->statuses($this->client($rules, self::SEED + 1), 200);

        self::assertSame($first, $second);
        self::assertNotSame($first, $otherSeed);
        self::assertGreaterThan(40, substr_count($first, 'E'));
        self::assertLessThan(80, substr_count($first, 'E'));
    }

    public function testImpossibleFaultsNeverFire(): void
    {
        $client = $this->client([new Rule(new Timeout(), probability: 0.0)]);

        self::assertSame(str_repeat('.', 50), $this->statuses($client, 50));
    }

    /**
     * @param list<Rule> $rules
     * @param list<Scenario> $scenarios
     */
    private function client(array $rules, int $seed = self::SEED, array $scenarios = []): ContractClient
    {
        return $this->durandal($rules, $seed, $this->sleeper, $scenarios, $this->clock);
    }

    /**
     * Séquence des issues : « . » pour une réponse 200, « E » pour toute autre issue.
     */
    private function statuses(ContractClient $client, int $count): string
    {
        $sequence = '';
        for ($i = 0; $i < $count; ++$i) {
            $sequence .= $client->send('GET', self::URL)->status === 200 ? '.' : 'E';
        }

        return $sequence;
    }
}
