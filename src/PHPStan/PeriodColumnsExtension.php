<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\PHPStan;

use Carbon\CarbonImmutable;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\BooleanType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Vusys\Bitemporal\Bitemporal;

/**
 * Tells PHPStan about the period columns the Blueprint macros add
 * (`bitemporalPeriods()`, `validPeriod()`, `recordedPeriod()`) on every model
 * using the Bitemporal trait, typed as the trait casts them. Larastan infers
 * model properties from migrations but can't see what a macro adds, so
 * without this `$price->valid_from` is an undefined property at level 9.
 *
 * Column names default to the package's; override them to match a changed
 * `bitemporal.columns` config:
 *
 * ```neon
 * parameters:
 *     bitemporal:
 *         columns: {valid_from: effective_from, valid_to: effective_to}
 * ```
 */
final readonly class PeriodColumnsExtension implements PropertiesClassReflectionExtension
{
    private const array DEFAULTS = ['valid_from', 'valid_to', 'recorded_from', 'recorded_to', 'is_retraction'];

    /**
     * @param  array<string, string>  $columns
     */
    public function __construct(private array $columns = []) {}

    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        return $this->column($propertyName) !== null && $this->usesBitemporal($classReflection);
    }

    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        return new PeriodColumnProperty($classReflection, $this->type((string) $this->column($propertyName)));
    }

    private function type(string $column): Type
    {
        return match ($column) {
            'is_retraction' => new BooleanType,
            'valid_to', 'recorded_to' => TypeCombinator::union(new ObjectType(CarbonImmutable::class), new NullType),
            default => new ObjectType(CarbonImmutable::class),
        };
    }

    /** Which period column $propertyName is, if any. */
    private function column(string $propertyName): ?string
    {
        foreach (self::DEFAULTS as $column) {
            if (($this->columns[$column] ?? $column) === $propertyName) {
                return $column;
            }
        }

        return null;
    }

    private function usesBitemporal(ClassReflection $classReflection): bool
    {
        for ($class = $classReflection; $class instanceof ClassReflection; $class = $class->getParentClass()) {
            if (array_key_exists(Bitemporal::class, $class->getTraits(true))) {
                return true;
            }
        }

        return false;
    }
}
