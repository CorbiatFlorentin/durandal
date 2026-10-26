<?php

declare(strict_types=1);

namespace Durandal\Tests\Contract;

/**
 * Un client Durandal vu par la suite de contrat, quel que soit l'adaptateur.
 */
interface ContractClient
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers = []): ContractResult;
}
