<?php

declare(strict_types=1);

namespace Durandal\Tests\Bridge\Symfony;

use Durandal\Bridge\Symfony\ClientFactory;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Http\RequestInfo;
use Durandal\Rule\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(ClientFactory::class)]
final class ClientFactoryTest extends TestCase
{
    public function testAMinimalRuleTargetsEveryRequestAndAlwaysFires(): void
    {
        $rule = self::single(['fault' => 'timeout']);

        self::assertInstanceOf(Timeout::class, $rule->fault);
        self::assertTrue($rule->probability->isCertain());
        self::assertTrue($rule->match->matches(new RequestInfo('DELETE', 'any.example.com', '/')));
    }

    public function testEveryFaultIsBuiltWithItsParameters(): void
    {
        $rules = ClientFactory::rules([
            ['fault' => 'latency', 'ms' => 800],
            ['fault' => 'http_error', 'status' => 429, 'retry_after' => 30],
            ['fault' => 'connection_refused'],
            ['fault' => 'truncated_body', 'keep_ratio' => 0.25],
            ['fault' => 'malformed_body', 'body' => '<html>'],
            ['fault' => 'truncated_body', 'keep_ratio' => 0],
        ]);

        self::assertSame(
            self::describe([new Latency(800), new HttpError(429, 30), new ConnectionRefused(), new TruncatedBody(0.25), new MalformedBody('<html>'), new TruncatedBody(0.0)]),
            self::describe(array_map(static fn(Rule $rule) => $rule->fault, $rules)),
        );
    }

    public function testFaultParametersHaveDefaults(): void
    {
        $rules = ClientFactory::rules([
            ['fault' => 'http_error', 'status' => null],
            ['fault' => 'truncated_body'],
            ['fault' => 'malformed_body'],
        ]);

        self::assertSame(
            self::describe([new HttpError(503), new TruncatedBody(0.5), new MalformedBody()]),
            self::describe(array_map(static fn(Rule $rule) => $rule->fault, $rules)),
        );
    }

    public function testTheMatcherIsBuiltFromTheConfiguration(): void
    {
        $rule = self::single([
            'fault' => 'timeout',
            'probability' => 0.3,
            'match' => ['method' => ['get', 'POST'], 'host' => '*.example.com', 'path' => '/orders*', 'headers' => ['Idempotency-Key' => null, 'X-Env' => 'test']],
        ]);
        $headers = ['idempotency-key' => ['k-1'], 'x-env' => ['test']];

        self::assertSame(0.3, $rule->probability->value);
        self::assertTrue($rule->match->matches(new RequestInfo('POST', 'api.example.com', '/orders/1', $headers)));
        self::assertTrue($rule->match->matches(new RequestInfo('GET', 'api.example.com', '/orders', $headers)));
        self::assertFalse($rule->match->matches(new RequestInfo('PUT', 'api.example.com', '/orders', $headers)));
        self::assertFalse($rule->match->matches(new RequestInfo('POST', 'example.org', '/orders', $headers)));
        self::assertFalse($rule->match->matches(new RequestInfo('POST', 'api.example.com', '/users', $headers)));
        self::assertFalse($rule->match->matches(new RequestInfo('POST', 'api.example.com', '/orders', ['x-env' => ['test']])));
        self::assertFalse($rule->match->matches(new RequestInfo('POST', 'api.example.com', '/orders', ['idempotency-key' => ['k-1'], 'x-env' => ['prod']])));
    }

    public function testASingleMethodNeedsNoList(): void
    {
        $rule = self::single(['fault' => 'timeout', 'match' => ['method' => 'post']]);

        self::assertTrue($rule->match->matches(new RequestInfo('POST', 'example.com', '/')));
        self::assertFalse($rule->match->matches(new RequestInfo('GET', 'example.com', '/')));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'règles hors liste' => ['timeout', 'les règles doivent être une liste.'];
        yield 'règle hors tableau' => [['timeout'], 'Règle n° 1 : une règle doit être un tableau.'];
        yield 'panne absente' => [[['ms' => 10]], 'Règle n° 1 : le paramètre « fault » est obligatoire.'];
        yield 'panne inconnue' => [[['fault' => 'meteor']], 'Règle n° 1 (meteor) : panne inconnue, attendu : latency, http_error, timeout, connection_refused, truncated_body, malformed_body.'];
        yield 'panne mal typée' => [[['fault' => 42]], 'Règle n° 1 : « fault » doit être une chaîne.'];
        yield 'paramètre étranger' => [[['fault' => 'timeout'], ['fault' => 'latency', 'ms' => 1, 'body' => 'x']], 'Règle n° 2 (latency) : le paramètre « body » ne s\'applique pas à cette panne.'];
        yield 'latence sans durée' => [[['fault' => 'latency']], 'Règle n° 1 (latency) : le paramètre « ms » est obligatoire.'];
        yield 'entier mal typé' => [[['fault' => 'latency', 'ms' => '800']], 'Règle n° 1 (latency) : « ms » doit être un entier.'];
        yield 'nombre mal typé' => [[['fault' => 'timeout', 'probability' => 'often']], 'Règle n° 1 (timeout) : « probability » doit être un nombre.'];
        yield 'valeur refusée par la panne' => [[['fault' => 'http_error', 'status' => 200]], 'Règle n° 1 (http_error) : Le statut doit être une erreur HTTP (400 à 599), 200 reçu.'];
        yield 'probabilité hors bornes' => [[['fault' => 'timeout', 'probability' => 2]], 'Règle n° 1 (timeout) : La probabilité doit être dans [0, 1]'];
        yield 'match hors tableau' => [[['fault' => 'timeout', 'match' => 'all']], '« match » doit être un tableau.'];
        yield 'méthode mal typée' => [[['fault' => 'timeout', 'match' => ['method' => [1]]]], '« match.method » doit contenir des méthodes HTTP.'];
        yield 'hôte mal typé' => [[['fault' => 'timeout', 'match' => ['host' => 1]]], '« host » doit être une chaîne.'];
        yield 'en-têtes hors tableau' => [[['fault' => 'timeout', 'match' => ['headers' => 'X-Key']]], '« match.headers » doit associer un nom d\'en-tête à une valeur.'];
        yield 'valeur d\'en-tête mal typée' => [[['fault' => 'timeout', 'match' => ['headers' => ['X-Key' => 1]]]], '« X-Key » doit être une chaîne.'];
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRulesAreReportedWithTheirPosition(mixed $rules, string $message): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        ClientFactory::rules($rules);
    }

    public function testCreateDecoratesTheClientWithTheRulesAndTheSeed(): void
    {
        $client = ClientFactory::create(new MockHttpClient(), [['fault' => 'http_error', 'status' => 418]], 7);

        self::assertSame(7, $client->seed());
        self::assertSame(418, $client->request('GET', 'https://example.com/')->getStatusCode());
    }

    public function testCreateDrawsASeedWhenNoneIsConfigured(): void
    {
        self::assertGreaterThanOrEqual(0, ClientFactory::create(new MockHttpClient(), [], null)->seed());
    }

    /**
     * Les pannes sont des valeurs : on les compare par classe et propriétés.
     *
     * @param list<Fault> $faults
     *
     * @return list<array{class-string<Fault>, array<mixed>}>
     */
    private static function describe(array $faults): array
    {
        return array_map(static fn(Fault $fault): array => [$fault::class, get_object_vars($fault)], $faults);
    }

    /**
     * @param array<mixed> $config
     */
    private static function single(array $config): Rule
    {
        $rules = ClientFactory::rules([$config]);
        self::assertCount(1, $rules);

        return $rules[0];
    }
}
