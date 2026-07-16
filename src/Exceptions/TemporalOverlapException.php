<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

final class TemporalOverlapException extends TemporalException
{
    /**
     * Primary keys of the rows a streaming backfill inserted before the
     * post-import audit failed, so callers can recover via
     * forceDeleteHistory(ids: ...).
     *
     * @var array<int, mixed>
     */
    private array $insertedIds = [];

    public static function betweenSegments(int $first, int $second): self
    {
        return new self("timeline segments at positions {$first} and {$second} overlap in valid time");
    }

    /**
     * @param  array<int, mixed>  $insertedIds
     */
    public static function afterBackfillAudit(array $insertedIds): self
    {
        $exception = new self(
            'streaming backfill produced overlapping current-known rows; the import is committed but inconsistent. '
            .'Recover the listed rows with forceDeleteHistory(ids: ...) via getInsertedIds().',
        );
        $exception->insertedIds = $insertedIds;

        return $exception;
    }

    /**
     * @return array<int, mixed>
     */
    public function getInsertedIds(): array
    {
        return $this->insertedIds;
    }
}
