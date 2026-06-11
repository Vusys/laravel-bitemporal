<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

/**
 * The active database engine cannot support a requested temporal feature (e.g.
 * PostgreSQL exclusion constraints without btree_gist, or an engine version
 * below the supported minimum).
 */
final class TemporalUnsupportedDatabaseException extends TemporalException
{
    public static function btreeGistMissing(): self
    {
        return new self(
            'btree_gist extension not available; required for exclusion constraints. Run EnableBitemporalExtensions migration as superuser, or set database.create_postgres_extensions = false and install manually.',
        );
    }

    public static function advisoryLocksUnsupported(string $engine): self
    {
        return new self("{$engine} does not support advisory locks; falling back to parent_row.");
    }

    public static function engineVersionBelowMinimum(string $engine, string $version, string $minimum): self
    {
        return new self("{$engine} {$version} below required {$minimum}.");
    }
}
