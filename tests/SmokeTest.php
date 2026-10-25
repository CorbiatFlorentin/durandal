<?php

declare(strict_types=1);

namespace Durandal\Tests;

use Durandal\Durandal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test de fumée de la phase 0 : vérifie que l'autoload et la chaîne d'outillage fonctionnent.
 */
#[CoversClass(Durandal::class)]
final class SmokeTest extends TestCase
{
    public function testVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-dev)?$/', Durandal::version());
    }
}
