<?php

declare(strict_types=1);

namespace Durandal\Adapter;

use Durandal\Clock\NullSleeper;
use Durandal\Clock\Sleeper;
use Durandal\Clock\SystemClock;
use Durandal\Engine\FaultEngine;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Random\SeededRandomizer;
use Durandal\Rule\Rule;
use Durandal\Scenario\Scenario;
use Psr\Clock\ClockInterface;

/**
 * Configuration immuable d'un client Durandal, commune à tous les adaptateurs.
 *
 * @internal configurez les clients avec leurs with…()
 */
final readonly class Settings
{
    /** Les seules pannes que les adaptateurs savent simuler. */
    private const SUPPORTED_FAULTS = [
        Latency::class,
        HttpError::class,
        Timeout::class,
        ConnectionRefused::class,
        TruncatedBody::class,
        MalformedBody::class,
    ];

    /**
     * @param list<Rule> $rules
     * @param list<Scenario> $scenarios
     */
    public function __construct(
        public int $seed,
        public array $rules = [],
        public array $scenarios = [],
        public Sleeper $sleeper = new NullSleeper(),
        public ClockInterface $clock = new SystemClock(),
    ) {
        foreach ($rules as $rule) {
            self::assertSupported($rule->fault);
        }

        foreach ($scenarios as $scenario) {
            foreach ($scenario->steps() as $step) {
                self::assertSupported($step->fault);
            }
        }
    }

    public function withRule(Rule $rule): self
    {
        return new self($this->seed, [...$this->rules, $rule], $this->scenarios, $this->sleeper, $this->clock);
    }

    public function withScenario(Scenario $scenario): self
    {
        return new self($this->seed, $this->rules, [...$this->scenarios, $scenario], $this->sleeper, $this->clock);
    }

    public function withSeed(int $seed): self
    {
        return new self($seed, $this->rules, $this->scenarios, $this->sleeper, $this->clock);
    }

    public function withSleeper(Sleeper $sleeper): self
    {
        return new self($this->seed, $this->rules, $this->scenarios, $sleeper, $this->clock);
    }

    public function withClock(ClockInterface $clock): self
    {
        return new self($this->seed, $this->rules, $this->scenarios, $this->sleeper, $clock);
    }

    /**
     * Un moteur neuf : sa séquence aléatoire et ses scénarios repartent du début.
     */
    public function engine(): FaultEngine
    {
        return new FaultEngine($this->rules, new SeededRandomizer($this->seed), $this->scenarios, $this->clock);
    }

    private static function assertSupported(?Fault $fault): void
    {
        if ($fault !== null && !\in_array($fault::class, self::SUPPORTED_FAULTS, true)) {
            throw new InvalidConfiguration(\sprintf("La panne %s n'est pas prise en charge par Durandal.", $fault::class));
        }
    }
}
