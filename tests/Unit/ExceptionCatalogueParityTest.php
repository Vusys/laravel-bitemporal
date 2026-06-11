<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Exceptions\TemporalDomainException;
use Vusys\Bitemporal\Exceptions\TemporalException;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Exceptions\TemporalUnsupportedDatabaseException;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;

final class ExceptionCatalogueParityTest extends TestCase
{
    /**
     * @return array<int, array{0: class-string<TemporalException>}>
     */
    public static function catalogueClasses(): array
    {
        return [
            [TemporalConfigurationException::class],
            [TemporalInvalidSpellException::class],
            [TemporalMissingDimensionException::class],
            [TemporalOverlapException::class],
            [TemporalCardinalityException::class],
            [TemporalWriteConflictException::class],
            [TemporalUnsupportedDatabaseException::class],
            [TemporalDomainException::class],
        ];
    }

    /**
     * @param  class-string<TemporalException>  $class
     */
    #[DataProvider('catalogueClasses')]
    public function test_every_catalogued_class_extends_the_base(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, TemporalException::class), "{$class} must extend TemporalException");
    }

    public function test_domain_exception_factories_produce_documented_messages(): void
    {
        $this->assertStringContainsString(
            'Report this with reproduction',
            TemporalDomainException::invariant('post-commit overlap', 'BitemporalWriter')->getMessage(),
        );

        $this->assertStringContainsString(
            'Clock skew',
            TemporalDomainException::clockSkew('Model#1', 'a', 'b', 90000, 60000)->getMessage(),
        );
    }

    public function test_message_template_skeleton_loads(): void
    {
        /** @var array<string, mixed> $messages */
        $messages = require __DIR__.'/../../lang/en/messages.php';

        $this->assertArrayHasKey('domain', $messages);
        $this->assertArrayHasKey('write_conflict', $messages);
    }
}
