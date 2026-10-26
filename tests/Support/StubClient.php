<?php

declare(strict_types=1);

namespace Durandal\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client PSR-18 factice : renvoie toujours la même réponse et compte les requêtes reçues.
 */
final class StubClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    private readonly ResponseInterface $response;

    public function __construct(?ResponseInterface $response = null)
    {
        $this->response = $response ?? new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->response;
    }
}
