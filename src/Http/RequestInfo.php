<?php

declare(strict_types=1);

namespace Durandal\Http;

/**
 * Description d'une requête, indépendante du client HTTP, sur laquelle portent les règles.
 * La méthode est en majuscules, l'hôte et les noms d'en-têtes en minuscules.
 */
final readonly class RequestInfo
{
    public string $method;

    public string $host;

    public string $path;

    /** @var array<array-key, list<string>> noms en minuscules ; un nom numérique reste une clé entière en PHP */
    public array $headers;

    /**
     * @param array<array<string>> $headers PHP transforme les noms d'en-têtes numériques en clés entières
     */
    public function __construct(string $method, string $host, string $path, array $headers = [])
    {
        $this->method = strtoupper($method);
        $this->host = strtolower($host);
        $this->path = $path === '' ? '/' : $path;

        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower((string) $name)] = array_values($values);
        }
        $this->headers = $normalized;
    }

    /**
     * @return list<string>
     */
    public function header(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}
