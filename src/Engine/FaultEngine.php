<?php

declare(strict_types=1);

namespace Durandal\Engine;

use Durandal\Fault\Latency;
use Durandal\Http\RequestInfo;
use Durandal\Random\SeededRandomizer;
use Durandal\Rule\Rule;

/**
 * Décide, règle après règle, du sort de chaque requête.
 *
 * Toutes les règles qui visent la requête sont tirées au sort dans l'ordre de déclaration :
 * les latences déclenchées s'additionnent, et la première autre panne déclenchée l'emporte.
 */
final class FaultEngine
{
    /**
     * @param list<Rule> $rules
     */
    public function __construct(
        private readonly array $rules,
        private readonly SeededRandomizer $random,
    ) {}

    public function decide(RequestInfo $request): Decision
    {
        $latency = 0;
        $fault = null;

        foreach ($this->rules as $rule) {
            if (!$rule->match->matches($request) || !$this->random->chance($rule->probability)) {
                continue;
            }

            if ($rule->fault instanceof Latency) {
                $latency += $rule->fault->milliseconds;
            } else {
                $fault ??= $rule->fault;
            }
        }

        return new Decision($latency, $fault);
    }
}
