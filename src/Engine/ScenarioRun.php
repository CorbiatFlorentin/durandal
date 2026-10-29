<?php

declare(strict_types=1);

namespace Durandal\Engine;

use DateTimeImmutable;
use Durandal\Fault\Fault;
use Durandal\Http\RequestInfo;
use Durandal\Scenario\Scenario;

/**
 * Avancement d'un client dans un scénario.
 *
 * @internal
 */
final class ScenarioRun
{
    private int $step = 0;

    private int $served = 0;

    private ?DateTimeImmutable $startedAt = null;

    public function __construct(private readonly Scenario $scenario) {}

    /**
     * La panne de l'étape en cours pour cette requête, ou null si elle passe.
     */
    public function next(RequestInfo $request, DateTimeImmutable $now): ?Fault
    {
        if (!$this->scenario->matcher()->matches($request)) {
            return null;
        }

        $steps = $this->scenario->steps();
        while (isset($steps[$this->step])) {
            $step = $steps[$this->step];

            if ($step->requests !== null && $this->served < $step->requests) {
                ++$this->served;

                return $step->fault;
            }

            if ($step->milliseconds !== null) {
                $this->startedAt ??= $now;
                if (self::elapsedMilliseconds($this->startedAt, $now) < $step->milliseconds) {
                    return $step->fault;
                }
            }

            ++$this->step;
            $this->served = 0;
            $this->startedAt = null;
        }

        return null;
    }

    private static function elapsedMilliseconds(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) round(((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000);
    }
}
