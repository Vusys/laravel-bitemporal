<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

use Throwable;

/**
 * A withoutIndexes() online-DDL operation could not be performed: it was invoked
 * inside a transaction (which forbids PostgreSQL's CREATE INDEX CONCURRENTLY and
 * makes dropping indexes unsafe), or a package index could not be recreated on
 * exit.
 */
final class TemporalOnlineDdlException extends TemporalException
{
    public static function insideTransaction(int $level): self
    {
        return new self(
            "TemporalLens::withoutIndexes() cannot run inside a transaction (level {$level}); ".
            'it drops and recreates indexes, and PostgreSQL CREATE INDEX CONCURRENTLY forbids a '.
            'transaction block. Call it outside any open transaction — the callback may open its own.',
        );
    }

    public static function recreateFailed(string $index, string $ddl, ?string $extra, Throwable $previous): self
    {
        $message = "Failed to recreate package index '{$index}' after withoutIndexes(); the table is ".
            "left without it and must be rebuilt manually. DDL: {$ddl}";

        if ($extra !== null && $extra !== '') {
            $message .= ' '.$extra;
        }

        return new self($message, 0, $previous);
    }
}
