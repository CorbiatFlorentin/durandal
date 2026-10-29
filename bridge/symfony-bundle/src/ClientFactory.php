<?php

declare(strict_types=1);

namespace Durandal\Bridge\Symfony;

use Durandal\Adapter\Settings;
use Durandal\Adapter\Symfony\DurandalHttpClient;
use Durandal\Durandal;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Traduit la configuration du bundle (un tableau par règle, tel qu'écrit en YAML) en règles, puis en client décoré.
 *
 *     ['fault' => 'http_error', 'status' => 503, 'probability' => 0.3, 'match' => ['host' => 'repo.packagist.org']]
 */
final class ClientFactory
{
    /**
     * Pannes configurables et paramètres propres à chacune.
     */
    public const FAULTS = [
        'latency' => ['ms'],
        'http_error' => ['status', 'retry_after'],
        'timeout' => [],
        'connection_refused' => [],
        'truncated_body' => ['keep_ratio'],
        'malformed_body' => ['body'],
    ];

    private const PARAMETERS = ['ms', 'status', 'retry_after', 'keep_ratio', 'body'];

    public static function create(HttpClientInterface $client, mixed $rules, ?int $seed): DurandalHttpClient
    {
        return new DurandalHttpClient($client, new Settings($seed ?? Durandal::defaultSeed(), self::rules($rules)));
    }

    /**
     * @return list<Rule>
     *
     * @throws InvalidConfiguration en indiquant la règle fautive
     */
    public static function rules(mixed $rules): array
    {
        if (!\is_array($rules)) {
            throw new InvalidConfiguration('les règles doivent être une liste.');
        }

        $built = [];
        foreach (array_values($rules) as $index => $rule) {
            try {
                if (!\is_array($rule)) {
                    throw new InvalidConfiguration('une règle doit être un tableau.');
                }
                $built[] = new Rule(self::fault($rule), self::matcher($rule['match'] ?? []), self::float($rule, 'probability') ?? 1.0);
            } catch (InvalidConfiguration $e) {
                throw new InvalidConfiguration(\sprintf('Règle n° %d%s : %s', $index + 1, self::label($rule), $e->getMessage()), previous: $e);
            }
        }

        return $built;
    }

    /**
     * @param array<mixed> $rule
     */
    private static function fault(array $rule): Fault
    {
        $name = self::string($rule, 'fault') ?? throw new InvalidConfiguration('le paramètre « fault » est obligatoire.');
        $allowed = self::FAULTS[$name] ?? throw new InvalidConfiguration(\sprintf(
            'panne inconnue, attendu : %s.',
            implode(', ', array_keys(self::FAULTS)),
        ));

        foreach (self::PARAMETERS as $parameter) {
            if (isset($rule[$parameter]) && !\in_array($parameter, $allowed, true)) {
                throw new InvalidConfiguration(\sprintf('le paramètre « %s » ne s\'applique pas à cette panne.', $parameter));
            }
        }

        return match ($name) {
            'latency' => new Latency(self::int($rule, 'ms') ?? throw new InvalidConfiguration('le paramètre « ms » est obligatoire.')),
            'http_error' => new HttpError(self::int($rule, 'status') ?? 503, self::int($rule, 'retry_after')),
            'timeout' => new Timeout(),
            'connection_refused' => new ConnectionRefused(),
            'truncated_body' => new TruncatedBody(self::float($rule, 'keep_ratio') ?? 0.5),
            default => new MalformedBody(self::string($rule, 'body') ?? MalformedBody::DEFAULT_BODY),
        };
    }

    private static function matcher(mixed $match): RequestMatcher
    {
        if (!\is_array($match)) {
            throw new InvalidConfiguration('« match » doit être un tableau.');
        }

        $matcher = RequestMatcher::any();

        $methods = $match['method'] ?? [];
        $methods = \is_array($methods) ? $methods : [$methods];
        foreach ($methods as $method) {
            if (!\is_string($method)) {
                throw new InvalidConfiguration('« match.method » doit contenir des méthodes HTTP.');
            }
        }
        if ($methods !== []) {
            $matcher = $matcher->method(...$methods);
        }

        if (($host = self::string($match, 'host')) !== null) {
            $matcher = $matcher->host($host);
        }

        if (($path = self::string($match, 'path')) !== null) {
            $matcher = $matcher->path($path);
        }

        $headers = $match['headers'] ?? [];
        if (!\is_array($headers)) {
            throw new InvalidConfiguration('« match.headers » doit associer un nom d\'en-tête à une valeur.');
        }
        foreach ($headers as $name => $value) {
            $matcher = $matcher->header((string) $name, self::string($headers, $name));
        }

        return $matcher;
    }

    /**
     * @param array<mixed> $values
     */
    private static function string(array $values, int|string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new InvalidConfiguration(\sprintf('« %s » doit être une chaîne.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private static function int(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;
        if ($value !== null && !\is_int($value)) {
            throw new InvalidConfiguration(\sprintf('« %s » doit être un entier.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private static function float(array $values, string $key): ?float
    {
        $value = $values[$key] ?? null;
        if ($value !== null && !\is_int($value) && !\is_float($value)) {
            throw new InvalidConfiguration(\sprintf('« %s » doit être un nombre.', $key));
        }

        return $value === null ? null : (float) $value;
    }

    private static function label(mixed $rule): string
    {
        return \is_array($rule) && \is_string($rule['fault'] ?? null) ? ' (' . $rule['fault'] . ')' : '';
    }
}
