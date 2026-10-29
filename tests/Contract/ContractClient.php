<?php

declare(strict_types=1);

namespace Durandal\Tests\Contract;

use Durandal\Journal\Journal;

/**
 * Un client Durandal vu par la suite de contrat, quel que soit l'adaptateur.
 */
interface ContractClient
{
    public function journal(): Journal;

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers = []): ContractResult;
}
