<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The shipped PHPStan extension types the period columns on any Bitemporal
 * model, including subclasses, without @property docblocks (#126).
 */
final class PeriodColumnsExtensionTest extends TypeInferenceTestCase
{
    /** @return iterable<mixed> */
    public static function dataFileAsserts(): iterable
    {
        // Loaded so its classes exist for PHPStan's reflection.
        require_once __DIR__.'/data/period-columns.php';

        yield from self::gatherAssertTypes(__DIR__.'/data/period-columns.php');
    }

    #[DataProvider('dataFileAsserts')]
    public function test_file_asserts(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/extension-test.neon'];
    }
}
