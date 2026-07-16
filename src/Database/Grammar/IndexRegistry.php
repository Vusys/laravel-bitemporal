<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Database\Grammar;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Facades\Log;
use Vusys\Bitemporal\Database\TemporalBlueprintMacros;
use Vusys\Bitemporal\Exceptions\TemporalOnlineDdlException;

/**
 * Engine-aware introspection and DDL for the package-managed overlap indexes
 * (`temporal_overlap`, `bitemporal_overlap`). Identifies them by their
 * deterministic names, captures their exact definition before a drop, and
 * recreates them online where the engine supports it. The PostgreSQL
 * `bitemporal_excl` EXCLUDE constraint is a constraint, not a plain index, and
 * is never a candidate here.
 *
 * Stateless: every method takes the connection + table it operates on.
 */
final class IndexRegistry
{
    /**
     * The deterministic names of the two plain overlap indexes the package emits
     * for a table. Exactly one name per suffix (raw or hashed), never the
     * EXCLUDE constraint.
     *
     * @return array<int, string>
     */
    public static function candidateNames(string $table): array
    {
        return [
            TemporalBlueprintMacros::overlapIndexName($table, 'temporal_overlap'),
            TemporalBlueprintMacros::overlapIndexName($table, 'bitemporal_overlap'),
        ];
    }

    /**
     * The package overlap indexes that actually exist on the table, with their
     * ordered columns and uniqueness captured for faithful recreation.
     *
     * @return array<int, IndexDescriptor>
     */
    public function existing(ConnectionInterface $connection, string $table): array
    {
        return match ($this->driver($connection)) {
            'pgsql' => $this->existingPostgres($connection, $table),
            'sqlite' => $this->existingSqlite($connection, $table),
            default => $this->existingMysql($connection, $table),
        };
    }

    public function drop(ConnectionInterface $connection, string $table, IndexDescriptor $index): void
    {
        $grammar = $this->grammar($connection);
        $name = $grammar->wrap($index->name);

        $sql = match ($this->driver($connection)) {
            'pgsql' => 'drop index '.($this->onlineDdl() ? 'concurrently ' : '').$name,
            'sqlite' => 'drop index '.$name,
            default => 'alter table '.$grammar->wrapTable($table).' drop index '.$name,
        };

        $connection->statement($sql);
    }

    public function recreate(ConnectionInterface $connection, string $table, IndexDescriptor $index): void
    {
        $sql = $this->recreateSql($connection, $table, $index);

        if ($this->driver($connection) === 'sqlite' && ! $this->suppressSqliteWarning()) {
            Log::warning(
                "withoutIndexes(): recreating index '{$index->name}' on SQLite rebuilds it with a full ".
                'table lock. This path is intended for tests and small datasets only.',
            );
        }

        try {
            $connection->statement($sql);
        } catch (\Throwable $previous) {
            $extra = $this->driver($connection) === 'pgsql'
                ? "CREATE INDEX CONCURRENTLY may have left an INVALID index '{$index->name}'; ".
                    "run DROP INDEX IF EXISTS {$index->name} before retrying."
                : null;

            throw TemporalOnlineDdlException::recreateFailed(
                $index->name,
                $sql,
                $extra,
                $previous,
            );
        }
    }

    public function recreateSql(ConnectionInterface $connection, string $table, IndexDescriptor $index): string
    {
        $grammar = $this->grammar($connection);
        $name = $grammar->wrap($index->name);
        $tableName = $grammar->wrapTable($table);
        $columns = implode(', ', array_map($grammar->wrap(...), $index->columns));
        $unique = $index->unique ? 'unique ' : '';

        return match ($this->driver($connection)) {
            'pgsql' => 'create '.$unique.'index '.($this->onlineDdl() ? 'concurrently ' : '')
                .$name.' on '.$tableName.' ('.$columns.')',
            'sqlite' => 'create '.$unique.'index '.$name.' on '.$tableName.' ('.$columns.')',
            default => 'alter table '.$tableName.' add '.$unique.'index '.$name.' ('.$columns.')'
                .($this->onlineDdl() ? ', algorithm=inplace, lock=none' : ''),
        };
    }

    /**
     * @return array<int, IndexDescriptor>
     */
    private function existingMysql(ConnectionInterface $connection, string $table): array
    {
        $candidates = self::candidateNames($table);
        $placeholders = implode(', ', array_fill(0, count($candidates), '?'));

        // Alias to lowercase explicitly: MySQL labels information_schema columns
        // in uppercase (MariaDB does not), so unaliased $row->index_name misses.
        $rows = $connection->select(
            'select index_name as index_name, column_name as column_name, '
            .'non_unique as non_unique, seq_in_index as seq_in_index '
            .'from information_schema.statistics '
            .'where table_schema = database() and table_name = ? and index_name in ('.$placeholders.') '
            .'order by index_name, seq_in_index',
            [$table, ...$candidates],
        );

        /** @var array<string, array{columns: array<int, string>, unique: bool}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $name = (string) $row->index_name;
            $grouped[$name]['columns'][] = (string) $row->column_name;
            $grouped[$name]['unique'] = ((int) $row->non_unique) === 0;
        }

        return $this->toDescriptors($grouped);
    }

    /**
     * @return array<int, IndexDescriptor>
     */
    private function existingPostgres(ConnectionInterface $connection, string $table): array
    {
        $candidates = self::candidateNames($table);
        $placeholders = implode(', ', array_fill(0, count($candidates), '?'));

        $rows = $connection->select(
            'select i.relname as index_name, a.attname as column_name, x.indisunique as is_unique, k.ord '
            .'from pg_class t '
            .'join pg_index x on x.indrelid = t.oid '
            .'join pg_class i on i.oid = x.indexrelid '
            .'join lateral unnest(x.indkey) with ordinality as k(attnum, ord) on true '
            .'join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum '
            .'where t.relname = ? and i.relname in ('.$placeholders.') '
            .'and not x.indisexclusion '
            .'and not exists (select 1 from pg_constraint c where c.conindid = i.oid) '
            .'order by i.relname, k.ord',
            [$table, ...$candidates],
        );

        /** @var array<string, array{columns: array<int, string>, unique: bool}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $name = (string) $row->index_name;
            $grouped[$name]['columns'][] = (string) $row->column_name;
            $grouped[$name]['unique'] = (bool) $row->is_unique;
        }

        return $this->toDescriptors($grouped);
    }

    /**
     * @return array<int, IndexDescriptor>
     */
    private function existingSqlite(ConnectionInterface $connection, string $table): array
    {
        $candidates = self::candidateNames($table);

        /** @var array<string, array{columns: array<int, string>, unique: bool}> $grouped */
        $grouped = [];
        foreach ($connection->select('pragma index_list('.$this->quoteSqlite($table).')') as $index) {
            $name = (string) $index->name;
            // origin 'c' = created by CREATE INDEX (ours); skip 'u'/'pk' auto-indexes.
            if ($index->origin !== 'c') {
                continue;
            }
            if (! in_array($name, $candidates, true)) {
                continue;
            }

            $columns = [];
            foreach ($connection->select('pragma index_info('.$this->quoteSqlite($name).')') as $column) {
                $columns[] = (string) $column->name;
            }

            $grouped[$name] = ['columns' => $columns, 'unique' => ((int) $index->unique) === 1];
        }

        return $this->toDescriptors($grouped);
    }

    /**
     * @param  array<string, array{columns: array<int, string>, unique: bool}>  $grouped
     * @return array<int, IndexDescriptor>
     */
    private function toDescriptors(array $grouped): array
    {
        $descriptors = [];
        foreach ($grouped as $name => $meta) {
            $descriptors[] = new IndexDescriptor($name, $meta['columns'], $meta['unique']);
        }

        return $descriptors;
    }

    private function driver(ConnectionInterface $connection): string
    {
        /** @var Connection $connection */
        return $connection->getDriverName();
    }

    private function grammar(ConnectionInterface $connection): Grammar
    {
        /** @var Connection $connection */
        return $connection->getQueryGrammar();
    }

    private function quoteSqlite(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function onlineDdl(): bool
    {
        return config('bitemporal.backfill.online_ddl', true) === true;
    }

    private function suppressSqliteWarning(): bool
    {
        return config('bitemporal.backfill.suppress_sqlite_warning', false) === true;
    }
}
