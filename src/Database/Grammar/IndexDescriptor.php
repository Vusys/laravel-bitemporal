<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Database\Grammar;

/**
 * A faithful snapshot of a package-managed overlap index, captured before it is
 * dropped so withoutIndexes() can recreate it identically on exit — regardless
 * of the model's column-name overrides or how the emit macro built it.
 */
final readonly class IndexDescriptor
{
    /**
     * @param  array<int, string>  $columns  ordered index columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
    ) {}
}
