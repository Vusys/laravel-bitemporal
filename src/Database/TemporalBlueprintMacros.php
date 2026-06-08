<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Registers the temporal Blueprint macros so migrations can declare period
 * columns and overlap-prevention with a single call. All columns are emitted at
 * microsecond precision (datetime(6) / timestamptz), which the package mandates.
 *
 * On PostgreSQL the overlap constraint should ideally be an EXCLUDE constraint;
 * that grammar is CI-verified and not emitted here yet, so preventBitemporalOverlaps()
 * currently adds a composite index across drivers. The writer's application-level
 * overlap detection remains the primary guarantee on every engine.
 */
final class TemporalBlueprintMacros
{
    public static function register(): void
    {
        $columns = static fn (): array => self::columns();

        /**
         * @param  array<string, string>  $map
         */
        $emitValid = static function (Blueprint $table, array $map, bool $nullable): void {
            $table->dateTime($map['valid_from'], 6)->nullable($nullable);
            $table->dateTime($map['valid_to'], 6)->nullable();
            $table->boolean($map['is_retraction'])->default(false);
        };

        /**
         * @param  array<string, string>  $map
         */
        $emitRecorded = static function (Blueprint $table, array $map, bool $nullable): void {
            $table->dateTime($map['recorded_from'], 6)->nullable($nullable);
            $table->dateTime($map['recorded_to'], 6)->nullable();
        };

        Blueprint::macro('validPeriod', function (array $options = [], bool $nullable = false) use ($columns, $emitValid): void {
            /** @var Blueprint $this */
            $emitValid($this, array_merge($columns(), $options), $nullable);
        });

        Blueprint::macro('temporalPeriod', function (array $options = [], bool $nullable = false) use ($columns, $emitValid): void {
            /** @var Blueprint $this */
            $emitValid($this, array_merge($columns(), $options), $nullable);
        });

        Blueprint::macro('recordedPeriod', function (array $options = [], bool $nullable = false) use ($columns, $emitRecorded): void {
            /** @var Blueprint $this */
            $emitRecorded($this, array_merge($columns(), $options), $nullable);
        });

        Blueprint::macro('bitemporalPeriods', function (array $options = [], bool $nullable = false) use ($columns, $emitValid, $emitRecorded): void {
            /** @var Blueprint $this */
            $map = array_merge($columns(), $options);
            $emitValid($this, $map, $nullable);
            $emitRecorded($this, $map, $nullable);
        });

        Blueprint::macro('bitemporalForeignFor', function (string $related): void {
            /** @var Blueprint $this */
            $this->foreignIdFor($related)->constrained()->restrictOnDelete();
        });

        Blueprint::macro('bitemporalMorphsFor', function (string $name): void {
            /** @var Blueprint $this */
            $this->morphs($name);
        });

        Blueprint::macro('preventTemporalOverlaps', function (array $entityColumns, array $dimensions = []) use ($columns): void {
            /** @var Blueprint $this */
            $map = $columns();
            $this->index([...$entityColumns, ...$dimensions, $map['valid_from'], $map['valid_to']]);
        });

        Blueprint::macro('preventBitemporalOverlaps', function (array $entityColumns, array $dimensions = []) use ($columns): void {
            /** @var Blueprint $this */
            $map = $columns();
            $this->index([...$entityColumns, ...$dimensions, $map['valid_from'], $map['valid_to'], $map['recorded_from'], $map['recorded_to']]);
        });
    }

    /**
     * @return array<string, string>
     */
    private static function columns(): array
    {
        $configured = config('bitemporal.columns', []);

        $defaults = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'recorded_from' => 'recorded_from',
            'recorded_to' => 'recorded_to',
            'is_retraction' => 'is_retraction',
        ];

        if (! is_array($configured)) {
            return $defaults;
        }

        $result = $defaults;
        foreach ($defaults as $key => $default) {
            $value = $configured[$key] ?? $default;
            $result[$key] = is_string($value) ? $value : $default;
        }

        return $result;
    }
}
