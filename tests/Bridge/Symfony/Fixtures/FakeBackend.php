<?php

declare(strict_types=1);

namespace Durandal\Tests\Bridge\Symfony\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Faux serveur branché via framework.http_client.mock_response_factory : répond 200 et note chaque requête reçue.
 */
final class FakeBackend
{
    /** @var list<string> « MÉTHODE URL » */
    public array $requests = [];

    public function __invoke(string $method, string $url): MockResponse
    {
        $this->requests[] = $method . ' ' . $url;

        return new MockResponse('{"ok":true}', ['response_headers' => ['Content-Type' => 'application/json']]);
    }
}
