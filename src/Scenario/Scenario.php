<?php

declare(strict_types=1);

namespace Durandal\Scenario;

use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\Timeout;
use Durandal\Rule\RequestMatcher;

/**
 * Séquence d'étapes jouée dans l'ordre sur les requêtes visées : « 2 échecs puis OK », « hors ligne 5 s »…
 * Une fois la séquence terminée, les requêtes passent.
 *
 * Immuable. L'avancement dans la séquence appartient à chaque client : un nouveau client (with…()) repart
 * du début. Les étapes de durée se mesurent avec l'horloge du client, à partir de leur première requête.
 */
final class Scenario
{
    /**
     * @param list<Step> $steps
     */
    private function __construct(
        private readonly array $steps,
        private readonly RequestMatcher $match,
    ) {}

    public static function faultTimes(int $times, Fault $fault): self
    {
        return new self([Step::forRequests($times, $fault)], RequestMatcher::any());
    }

    public static function failTimes(int $times, int $status = 503, ?int $retryAfterSeconds = null): self
    {
        return self::faultTimes($times, new HttpError($status, $retryAfterSeconds));
    }

    public static function timeoutTimes(int $times): self
    {
        return self::faultTimes($times, new Timeout());
    }

    public static function succeedTimes(int $times): self
    {
        return new self([Step::forRequests($times, null)], RequestMatcher::any());
    }

    /**
     * Refuse les connexions pendant cette durée, mesurée à partir de la première requête visée.
     */
    public static function offlineFor(int $seconds): self
    {
        return new self([Step::forDuration($seconds * 1000, new ConnectionRefused())], RequestMatcher::any());
    }

    /**
     * Ralentit les premières requêtes, puis laisse passer les suivantes sans délai.
     */
    public static function slowThenFast(int $slowRequests, int $milliseconds): self
    {
        return self::faultTimes($slowRequests, new Latency($milliseconds));
    }

    /**
     * Enchaîne les étapes d'un autre scénario après celles-ci (son filtre de requêtes est ignoré).
     */
    public function then(self $next): self
    {
        return new self([...$this->steps, ...$next->steps], $this->match);
    }

    /**
     * Pour la lisibilité : après la dernière étape, les requêtes passent déjà.
     */
    public function thenSucceed(): self
    {
        return $this;
    }

    /**
     * Ne joue le scénario que sur ces requêtes ; les autres ne le font pas avancer.
     */
    public function forRequests(RequestMatcher $match): self
    {
        return new self($this->steps, $match);
    }

    /**
     * @return list<Step>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function matcher(): RequestMatcher
    {
        return $this->match;
    }
}
