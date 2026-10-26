<?php

declare(strict_types=1);

namespace Durandal\Rule;

use Durandal\Http\RequestInfo;

/**
 * Sélectionne les requêtes concernées par une règle. Immuable : chaque appel renvoie une nouvelle instance.
 *
 * Les motifs d'hôte et de chemin acceptent le joker « * » (n'importe quelle suite de caractères).
 */
final class RequestMatcher
{
    /** @var list<string> */
    private array $methods = [];

    private ?string $hostPattern = null;

    private ?string $pathPattern = null;

    /** @var array<string, ?string> nom d'en-tête en minuscules => valeur attendue (null : présence seule) */
    private array $headers = [];

    private function __construct() {}

    public static function any(): self
    {
        return new self();
    }

    public function method(string ...$methods): self
    {
        $clone = clone $this;
        $clone->methods = array_values(array_map(strtoupper(...), $methods));

        return $clone;
    }

    public function host(string $pattern): self
    {
        $clone = clone $this;
        $clone->hostPattern = $pattern;

        return $clone;
    }

    public function path(string $pattern): self
    {
        $clone = clone $this;
        $clone->pathPattern = $pattern;

        return $clone;
    }

    public function header(string $name, ?string $value = null): self
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = $value;

        return $clone;
    }

    public function matches(RequestInfo $request): bool
    {
        if ($this->methods !== [] && !\in_array($request->method, $this->methods, true)) {
            return false;
        }

        if ($this->hostPattern !== null && !self::glob($this->hostPattern, $request->host, caseInsensitive: true)) {
            return false;
        }

        if ($this->pathPattern !== null && !self::glob($this->pathPattern, $request->path, caseInsensitive: false)) {
            return false;
        }

        foreach ($this->headers as $name => $expected) {
            $values = $request->header($name);
            if ($values === [] || ($expected !== null && !\in_array($expected, $values, true))) {
                return false;
            }
        }

        return true;
    }

    private static function glob(string $pattern, string $subject, bool $caseInsensitive): bool
    {
        // Les jokers consécutifs sont fusionnés pour éviter un retour arrière explosif.
        $joker = (string) preg_replace('/\*+/', '*', $pattern);
        $regex = '#^' . str_replace('\*', '.*', preg_quote($joker, '#')) . '$#Ds' . ($caseInsensitive ? 'i' : '');

        return preg_match($regex, $subject) === 1;
    }
}
