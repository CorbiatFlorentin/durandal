<?php

declare(strict_types=1);

namespace Durandal\Tests;

use Durandal\Durandal;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Test de fumée de la phase 0 : vérifie que l'autoload et la chaîne d'outillage fonctionnent.
 */
#[CoversNothing]
final class SmokeTest extends TestCase
{
    public function testLibraryIsAutoloadable(): void
    {
        self::assertTrue(class_exists(Durandal::class));
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-dev)?$/', Durandal::VERSION);
    }
}
