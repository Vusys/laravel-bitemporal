<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Boot\Mutation;

use Vusys\Bitemporal\Boot\Lints\BootLintCompactionExcludesDomainColumn;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the surviving mutants in BootLintCompactionExcludesDomainColumn::check.
 *
 * Equivalent mutants (not targeted):
 *  - UnwrapArrayFilter on `array_filter([getCreatedAtColumn(), getUpdatedAtColumn()],
 *    is_string(...))`: those accessors always return strings for a normal model,
 *    so the is_string filter removes nothing.
 *  - UnwrapArrayValues on the domain-column filter: the result is only used in a
 *    `=== []` test and `implode(', ', ...)`, both of which ignore array keys.
 */
final class BootLintCompactionExcludesDomainColumnMutationTest extends IntegrationTestCase
{
    public function test_returns_null_when_only_timestamp_columns_are_excluded(): void
    {
        // created_at is one of the Eloquent timestamp columns, so it must be
        // filtered out and produce no lint. Kills the ArrayItemRemoval that drops
        // created_at from $timestamps, the UnwrapArrayFilter that drops the
        // domain-column filter, and the LogicalAnd -> LogicalOr flip.
        config()->set('bitemporal.writes.compaction_excluded_columns', ['created_at']);

        $this->assertNull(new BootLintCompactionExcludesDomainColumn()->check(new ProductPrice));
    }

    public function test_ignores_non_string_excluded_entries(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', [123]);

        $this->assertNull(new BootLintCompactionExcludesDomainColumn()->check(new ProductPrice));
    }

    public function test_exact_message_for_a_domain_column(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        $this->assertSame(
            'writes.compaction_excluded_columns contains non-timestamp column(s): amount. '
            .'Compaction will merge segments differing only on these.',
            new BootLintCompactionExcludesDomainColumn()->check(new ProductPrice),
        );
    }

    public function test_returns_null_when_config_is_not_an_array(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', 'amount');

        $this->assertNull(new BootLintCompactionExcludesDomainColumn()->check(new ProductPrice));
    }
}
