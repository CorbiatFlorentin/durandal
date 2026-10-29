<?php

declare(strict_types=1);

namespace Durandal\Engine;

use Durandal\Clock\SystemClock;
use Durandal\Fault\Fault;
use Durandal\Fault\Latency;
use Durandal\Http\RequestInfo;
use Durandal\Random\SeededRandomizer;
use Durandal\Rule\Rule;
use Durandal\Scenario\Scenario;
use Psr\Clock\ClockInterface;

/**
 * Décide du sort de chaque requête : d'abord les scénarios, puis les règles, dans l'ordre de déclaration.
 *
 * Tous les scénarios et toutes les règles qui visent la requête sont joués (les règles sont tirées au sort) :
 * les latences s'additionnent, et la première autre panne l'emporte.
 */
final class FaultEngine
{
    /** @var list<ScenarioRun> */
    private readonly array $runs;

    /**
     * @param list<Rule> $rules
     * @param list<Scenario> $scenarios
     */
    public function __construct(
        private readonly array $rules,
        private readonly SeededRandomizer $random,
        array $scenarios = [],
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->runs = array_map(static fn(Scenario $scenario): ScenarioRun => new ScenarioRun($scenario), $scenarios);
    }

    public function decide(RequestInfo $request): Decision
    {
        $now = $this->clock->now();
        $latency = 0;
        $fault = null;

        $faults = [];
        foreach ($this->runs as $run) {
            $faults[] = $run->next($request, $now);
        }

        foreach ($this->rules as $rule) {
            if ($rule->match->matches($request) && $this->random->chance($rule->probability)) {
                $faults[] = $rule->fault;
            }
        }

        foreach ($faults as $candidate) {
            if ($candidate instanceof Latency) {
                $latency += $candidate->milliseconds;
            } elseif ($candidate instanceof Fault) {
                $fault ??= $candidate;
            }
        }

        return new Decision($latency, $fault);
    }
}
