<?php

declare(strict_types=1);

use Durandal\Adapter\Psr18\DurandalClient;
use Durandal\Durandal;
use Durandal\Fault\HttpError;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use Durandal\Scenario\Scenario;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Clock\ClockInterface;

function durandal(?ClockInterface $clock = null): DurandalClient
{
    $backend = new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 10, new Response(201))))]);
    $factory = new HttpFactory();
    $client = Durandal::wrap($backend, $factory, $factory)->withSeed(1);

    return $clock === null ? $client : $client->withClock($clock);
}

function placeOrder(DurandalClient $client, int $attempts): void
{
    for ($i = 0; $i < $attempts; ++$i) {
        $client->sendRequest(new Request('POST', 'https://api.example.com/orders', ['Idempotency-Key' => 'k-1'], '{"id":1}'));
    }
}

it('vérifie les réessais sur un client ou sur son journal', function (): void {
    $client = durandal()->withScenario(Scenario::failTimes(2)->thenSucceed());

    placeOrder($client, 3);

    expect($client)->toHaveRetriedExactly(2)->toHaveNoDuplicateWrites();
    expect($client->journal())->toHaveRetriedExactly(2, RequestMatcher::any()->method('POST'));
});

it('vérifie l\'abandon après des échecs répétés', function (): void {
    $client = durandal()->withRule(new Rule(new HttpError(503)));

    placeOrder($client, 3);

    expect($client)->toHaveGivenUpAfter(3);
});

it('vérifie le respect de Retry-After avec l\'horloge du client', function (): void {
    $clock = new class implements ClockInterface {
        public DateTimeImmutable $now;

        public function __construct()
        {
            $this->now = new DateTimeImmutable('2026-01-01 10:00:00');
        }

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };
    $client = durandal($clock)->withScenario(Scenario::failTimes(1, status: 429, retryAfterSeconds: 2));

    placeOrder($client, 1);
    $clock->now = $clock->now->modify('+2 seconds');
    placeOrder($client, 1);

    expect($client)->toHaveRespectedRetryAfter();
});

it('affiche la graine et le journal quand une attente échoue', function (): void {
    $client = durandal()->withScenario(Scenario::failTimes(1));

    placeOrder($client, 2);

    expect($client)->toHaveRetriedExactly(3);
})->throws(ExpectationFailedException::class, 'Graine 1 (rejouer : DURANDAL_SEED=1)');

it('refuse ce qui n\'est ni un journal ni un client Durandal', function (): void {
    expect('journal')->toHaveRetriedExactly(0);
})->throws(InvalidArgumentException::class, 'un journal ou un client Durandal est attendu');
