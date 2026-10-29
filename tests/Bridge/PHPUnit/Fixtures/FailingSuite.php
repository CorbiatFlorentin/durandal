<?php

declare(strict_types=1);

namespace Durandal\Tests\Bridge\PHPUnit\Fixtures;

use Durandal\Adapter\Psr18\DurandalClient;
use Durandal\Durandal;
use Durandal\Fault\HttpError;
use Durandal\Rule\Rule;
use Durandal\Tests\Support\StubClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Suite exécutée dans un PHPUnit séparé avec l'extension Durandal : certains tests échouent exprès.
 */
final class FailingSuite extends TestCase
{
    public function testPasses(): void
    {
        $client = self::client(99);
        $client->sendRequest(new Request('GET', 'https://api.example.com/ok'));

        self::assertCount(1, $client->journal());
    }

    public function testFailsAfterRetrying(): void
    {
        $client = self::client(1234)->withRule(new Rule(new HttpError(503)));
        $response = $client->sendRequest(new Request('POST', 'https://api.example.com/orders'));

        self::assertSame(200, $response->getStatusCode(), 'La commande aurait dû passer.');
    }

    public function testErrors(): void
    {
        self::client(7)->sendRequest(new Request('GET', 'https://api.example.com/boom'));

        throw new RuntimeException('Erreur inattendue');
    }

    public function testFailsWithoutRequests(): void
    {
        self::client(8);

        self::fail('Échec sans requête HTTP.');
    }

    private static function client(int $seed): DurandalClient
    {
        $factory = new HttpFactory();

        return Durandal::wrap(new StubClient(), $factory, $factory)->withSeed($seed);
    }
}
