<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Lints;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Boot\BootLint;

/**
 * writes.compaction_excluded_columns lists a column that is not an
 * Eloquent-timestamp column. Compaction will silently merge segments that
 * differ only on that column, and diffKnowledge will report no change across
 * the merged window.
 */
final class BootLintCompactionExcludesDomainColumn implements BootLint
{
    public function check(Model $model): ?string
    {
        $excluded = config('bitemporal.writes.compaction_excluded_columns', []);

        if (! is_array($excluded)) {
            return null;
        }

        $timestamps = array_filter([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()], is_string(...));

        $domainColumns = array_values(array_filter(
            $excluded,
            static fn (mixed $column): bool => is_string($column) && ! in_array($column, $timestamps, true),
        ));

        if ($domainColumns === []) {
            return null;
        }

        return 'writes.compaction_excluded_columns contains non-timestamp column(s): '
            .implode(', ', $domainColumns).'. Compaction will merge segments differing only on these.';
    }
}
