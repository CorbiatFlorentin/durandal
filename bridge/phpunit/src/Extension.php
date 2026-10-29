<?php

declare(strict_types=1);

namespace Durandal\Bridge\PHPUnit;

use Durandal\Journal\ActiveJournals;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension as PHPUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Extension PHPUnit : oublie les journaux entre deux tests et, quand un test échoue, affiche à la fin
 * de l'exécution la graine et les requêtes de chaque client Durandal qu'il a utilisé.
 *
 *     <extensions>
 *         <bootstrap class="Durandal\Bridge\PHPUnit\Extension"/>
 *     </extensions>
 */
final class Extension implements PHPUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        ActiveJournals::enable();
        $report = new FailureReport();

        // Les abonnés se contentent de relayer les événements au rapport, testé à part.
        $facade->registerSubscribers(
            new class ($report) implements PreparationStartedSubscriber {
                public function __construct(private readonly FailureReport $report) {}

                public function notify(PreparationStarted $event): void
                {
                    $this->report->testStarted();
                }
            },
            new class ($report) implements FailedSubscriber {
                public function __construct(private readonly FailureReport $report) {}

                public function notify(Failed $event): void
                {
                    $this->report->testFailed($event->test()->id());
                }
            },
            new class ($report) implements ErroredSubscriber {
                public function __construct(private readonly FailureReport $report) {}

                public function notify(Errored $event): void
                {
                    $this->report->testFailed($event->test()->id());
                }
            },
            new class ($report) implements ExecutionFinishedSubscriber {
                public function __construct(private readonly FailureReport $report) {}

                public function notify(ExecutionFinished $event): void
                {
                    print $this->report->render();
                }
            },
        );
    }
}
