<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Vusys\Bitemporal\Exceptions\TemporalUnsupportedDatabaseException;

/**
 * Registers the temporal Blueprint macros so migrations can declare period
 * columns and overlap-prevention with a single call. All columns are emitted at
 * microsecond precision (datetime(6) / timestamptz), which the package mandates.
 *
 * The default overlap constraint is a composite index across every driver, with
 * the writer's application-level overlap detection as the primary guarantee. On
 * PostgreSQL, passing `useRanges: true` opts into native `tstzrange` columns and
 * a database-enforced `EXCLUDE USING gist` constraint as defence in depth.
 */
final class TemporalBlueprintMacros
{
    public static function register(): void
    {
        self::registerPostgresRangeGrammar();

        $columns = static fn (): array => self::columns();

        // Laravel derives index names from the table plus every column, which
        // the long temporal column names push past MySQL/MariaDB's 64-char
        // identifier limit. Use a short, deterministic name, hashing only when
        // a long table name would still overflow.
        $overlapIndex = static fn (string $table, string $suffix): string => self::overlapIndexName($table, $suffix);

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

        Blueprint::macro('bitemporalPeriods', function (array $options = [], bool $nullable = false, bool $useRanges = false) use ($columns, $emitValid, $emitRecorded): void {
            /** @var Blueprint $this */
            $map = array_merge($columns(), $options);

            if ($useRanges) {
                TemporalBlueprintMacros::requirePostgres();
                $this->addColumn('tstzrange', $options['valid_period'] ?? 'valid_period')->nullable($nullable);
                $this->addColumn('tstzrange', $options['recorded_period'] ?? 'recorded_period')->nullable($nullable);
                $this->boolean($map['is_retraction'])->default(false);

                return;
            }

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

        Blueprint::macro('preventTemporalOverlaps', function (array $entityColumns, array $dimensions = []) use ($columns, $overlapIndex): void {
            /** @var Blueprint $this */
            $map = $columns();
            $this->index(
                [...$entityColumns, ...$dimensions, $map['valid_from'], $map['valid_to']],
                $overlapIndex($this->getTable(), 'temporal_overlap'),
            );
        });

        Blueprint::macro('preventBitemporalOverlaps', function (array $entityColumns, array $dimensions = [], bool $useRanges = false, array $options = []) use ($columns, $overlapIndex): void {
            /** @var Blueprint $this */
            if ($useRanges) {
                TemporalBlueprintMacros::requireBtreeGist();
                TemporalBlueprintMacros::addExcludeCommand($this, [
                    'index' => $overlapIndex($this->getTable(), 'bitemporal_excl'),
                    'scalars' => [...$entityColumns, ...$dimensions],
                    'ranges' => [$options['valid_period'] ?? 'valid_period', $options['recorded_period'] ?? 'recorded_period'],
                ]);

                return;
            }

            $map = $columns();
            $this->index(
                [...$entityColumns, ...$dimensions, $map['valid_from'], $map['valid_to'], $map['recorded_from'], $map['recorded_to']],
                $overlapIndex($this->getTable(), 'bitemporal_overlap'),
            );
        });
    }

    /**
     * Registers the tstzrange column type and the EXCLUDE-constraint compiler on
     * the PostgreSQL schema grammar (both Macroable), so the range macros can
     * emit native DDL. Idempotent — safe to call on every boot.
     */
    private static function registerPostgresRangeGrammar(): void
    {
        if (! PostgresGrammar::hasMacro('typeTstzrange')) {
            PostgresGrammar::macro('typeTstzrange', fn (): string => 'tstzrange');
        }

        if (! PostgresGrammar::hasMacro('compileBitemporalExclude')) {
            PostgresGrammar::macro('compileBitemporalExclude', function (Blueprint $blueprint, Fluent $command): string {
                /** @var PostgresGrammar $this */
                $scalars = $command->get('scalars', []);
                $ranges = $command->get('ranges', []);
                $index = $command->get('index');
                $parts = [];

                foreach (is_array($scalars) ? $scalars : [] as $column) {
                    if (is_string($column)) {
                        $parts[] = $this->wrap($column).' WITH =';
                    }
                }

                foreach (is_array($ranges) ? $ranges : [] as $column) {
                    if (is_string($column)) {
                        $parts[] = $this->wrap($column).' WITH &&';
                    }
                }

                return sprintf(
                    'alter table %s add constraint %s exclude using gist (%s)',
                    $this->wrapTable($blueprint),
                    $this->wrap(is_string($index) ? $index : ''),
                    implode(', ', $parts),
                );
            });
        }
    }

    /**
     * The deterministic name for an overlap-prevention index/constraint on a
     * table. Short by default; hashed only when a long table name would push
     * the identifier past MySQL/MariaDB's 64-char limit. The single source of
     * truth for package index names — shared by the emit macros and the
     * runtime IndexRegistry that identifies them for withoutIndexes().
     */
    public static function overlapIndexName(string $table, string $suffix): string
    {
        $name = "{$table}_{$suffix}";

        return strlen($name) <= 64 ? $name : "{$suffix}_".md5($name);
    }

    /**
     * Append the EXCLUDE-constraint command to the blueprint. Blueprint offers
     * no public API to register a custom command, so we reach the protected
     * addCommand() by reflection; the command is compiled by the
     * compileBitemporalExclude grammar macro.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function addExcludeCommand(Blueprint $blueprint, array $parameters): void
    {
        $addCommand = new \ReflectionMethod(Blueprint::class, 'addCommand');
        $addCommand->invoke($blueprint, 'bitemporalExclude', $parameters);
    }

    /**
     * Native ranges are a PostgreSQL-only feature.
     */
    public static function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw TemporalUnsupportedDatabaseException::btreeGistMissing();
        }
    }

    /**
     * The EXCLUDE USING gist constraint mixes scalar `=` with range `&&`
     * operators in one GiST index, which requires the btree_gist extension.
     */
    public static function requireBtreeGist(): void
    {
        self::requirePostgres();

        $present = DB::connection()->selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'btree_gist'");

        if ($present === null) {
            throw TemporalUnsupportedDatabaseException::btreeGistMissing();
        }
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
