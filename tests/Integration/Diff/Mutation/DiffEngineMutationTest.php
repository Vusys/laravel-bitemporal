<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Diff\Mutation;

use Vusys\Bitemporal\Diff\DiffEngine;
use Vusys\Bitemporal\Diff\TemporalDiffPair;
use Vusys\Bitemporal\Diff\TemporalRetraction;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Fixtures\Models\Supplier;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins DiffEngine::compare behaviour by feeding hand-built model bags directly,
 * giving exact control over keys, dimensions, and attribute sets. Pure
 * in-memory — no database writes.
 */
final class DiffEngineMutationTest extends IntegrationTestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function price(array $attributes): ProductPrice
    {
        return (new ProductPrice)->forceFill($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function dimensioned(array $attributes): ProductPriceWithDimensions
    {
        return (new ProductPriceWithDimensions)->forceFill($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function address(array $attributes): Address
    {
        return (new Address)->forceFill($attributes);
    }

    private function priceMeta(): TemporalEntityMetadata
    {
        return (new ProductPrice)->temporalMetadata();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(int $id, int $productId, int $amount, string $currency, array $extra = []): array
    {
        return [
            'id' => $id,
            'product_id' => $productId,
            'amount' => $amount,
            'currency' => $currency,
            'valid_from' => '2026-02-01 00:00:00',
            'valid_to' => null,
            'recorded_from' => '2026-01-01 00:00:00',
            'recorded_to' => null,
            'is_retraction' => false,
            ...$extra,
        ];
    }

    public function test_added_row_does_not_short_circuit_later_changed_rows(): void
    {
        // Kills Continue_ -> break: an added row early in the "to" set must not
        // stop later rows from being classified as changed.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP'))];
        $to = [
            $this->price($this->row(2, 1, 500, 'GBP', ['valid_from' => '2026-01-01 00:00:00'])),
            $this->price($this->row(3, 1, 1200, 'GBP')),
        ];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $this->assertCount(1, $diff->added);
        $this->assertCount(1, $diff->changed);
    }

    public function test_removed_rows_are_detected(): void
    {
        // Kills Foreach_ ([] loop) and MethodCallRemoval ($removed->push).
        $from = [
            $this->price($this->row(1, 1, 1000, 'GBP')),
            $this->price($this->row(2, 1, 1500, 'GBP', ['valid_from' => '2026-03-01 00:00:00'])),
        ];
        $to = [$this->price($this->row(3, 1, 1000, 'GBP'))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $this->assertCount(1, $diff->removed);
        $this->assertCount(1, $diff->unchanged);
        $this->assertSame(1500, $diff->removed->first()?->getAttribute('amount'));
    }

    public function test_dimensions_are_part_of_the_match_key(): void
    {
        // Kills matchKey Foreach_ ([] over dimensions): two rows sharing
        // valid_from but differing by the currency dimension are distinct keys.
        $meta = (new ProductPriceWithDimensions)->temporalMetadata();

        $base = [
            'id' => 1, 'product_id' => 1, 'amount' => 1000, 'currency' => 'GBP',
            'valid_from' => '2026-02-01 00:00:00', 'valid_to' => null,
            'recorded_from' => '2026-01-01 00:00:00', 'recorded_to' => null,
            'is_retraction' => false,
        ];

        $from = [$this->dimensioned($base)];
        $to = [
            $this->dimensioned($base),
            $this->dimensioned([...$base, 'id' => 2, 'currency' => 'USD', 'amount' => 2000]),
        ];

        $diff = DiffEngine::compare($from, $to, $meta);

        // With the dimension in the key: GBP unchanged, USD added, nothing changed.
        $this->assertCount(1, $diff->added);
        $this->assertCount(1, $diff->unchanged);
        $this->assertCount(0, $diff->changed);
    }

    public function test_all_differing_attributes_are_reported(): void
    {
        // Kills ArrayOneItem (truncate changed set to one): both amount and
        // currency differ and both must be reported.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP'))];
        $to = [$this->price($this->row(1, 1, 1200, 'USD'))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $pair = $diff->changed->first();
        $this->assertInstanceOf(TemporalDiffPair::class, $pair);
        $this->assertContains('amount', $pair->changedAttributes);
        $this->assertContains('currency', $pair->changedAttributes);
        $this->assertCount(2, $pair->changedAttributes);
    }

    public function test_single_attribute_change_has_no_duplicates(): void
    {
        // Kills UnwrapArrayUnique: without array_unique the union of from/to
        // columns would list "amount" twice.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP'))];
        $to = [$this->price($this->row(1, 1, 1200, 'GBP'))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $pair = $diff->changed->first();
        $this->assertInstanceOf(TemporalDiffPair::class, $pair);
        $this->assertSame(['amount'], $pair->changedAttributes);
    }

    public function test_primary_key_is_not_a_comparable_column(): void
    {
        // Kills comparableColumns ArrayItemRemoval (drop getKeyName): rows that
        // differ only by primary key are unchanged.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP'))];
        $to = [$this->price($this->row(2, 1, 1000, 'GBP'))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $this->assertCount(1, $diff->unchanged);
        $this->assertCount(0, $diff->changed);
    }

    public function test_recorded_columns_are_not_compared(): void
    {
        // Kills UnwrapArrayFilter (drop the reserved-column exclusion): rows
        // differing only in recorded_from must read as unchanged.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP', ['recorded_from' => '2026-01-01 00:00:00']))];
        $to = [$this->price($this->row(1, 1, 1000, 'GBP', ['recorded_from' => '2026-02-01 00:00:00']))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $this->assertCount(1, $diff->unchanged);
        $this->assertCount(0, $diff->changed);
    }

    public function test_belongs_to_foreign_key_is_not_comparable(): void
    {
        // Kills entityColumns LogicalNot, InstanceOf_ (BelongsTo false / [] ),
        // and SpreadRemoval of entityColumns: rows differing only by the
        // belongsTo foreign key must read as unchanged.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP'))];
        $to = [$this->price($this->row(1, 2, 1000, 'GBP'))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $this->assertCount(1, $diff->unchanged);
        $this->assertCount(0, $diff->changed);
    }

    public function test_asymmetric_attribute_sets_are_both_compared(): void
    {
        // Kills SpreadOneItem (from/to keys -> [..][0]) and ArrayItemRemoval
        // (columns = [...to only]): a column present in only one side, placed
        // after the first key, must still be compared.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP', ['extra_from' => 'F']))];
        $to = [$this->price($this->row(1, 1, 1000, 'GBP', ['extra_to' => 'T']))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $pair = $diff->changed->first();
        $this->assertInstanceOf(TemporalDiffPair::class, $pair);
        $this->assertContains('extra_from', $pair->changedAttributes);
        $this->assertContains('extra_to', $pair->changedAttributes);
    }

    public function test_morph_owner_id_is_not_comparable(): void
    {
        // Kills entityColumns InstanceOf_ (MorphTo false) and SpreadOneItem
        // ([...entityColumns][0] drops owner_id): morph rows differing only by
        // owner_id read as unchanged.
        $meta = (new Address)->temporalMetadata();

        $base = [
            'id' => 1, 'owner_type' => Customer::class, 'owner_id' => 1, 'label' => 'HQ',
            'valid_from' => '2026-02-01 00:00:00', 'valid_to' => null,
            'recorded_from' => '2026-01-01 00:00:00', 'recorded_to' => null,
            'is_retraction' => false,
        ];

        $from = [$this->address($base)];
        $to = [$this->address([...$base, 'owner_id' => 2])];

        $diff = DiffEngine::compare($from, $to, $meta);

        $this->assertCount(1, $diff->unchanged);
        $this->assertCount(0, $diff->changed);
    }

    public function test_a_window_that_became_a_retraction_is_classified_as_retracted(): void
    {
        // Issue #76: a window withdrawn between the two knowledge dates
        // (is_retraction false -> true, value -> null) is a retraction, not a
        // value update. It must land in `retracted`, not `changed`.
        $from = [$this->price($this->row(1, 1, 1000, 'GBP'))];
        $to = [$this->price($this->row(2, 1, 1000, 'GBP', ['amount' => null, 'is_retraction' => true]))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $this->assertCount(1, $diff->retracted);
        $this->assertCount(0, $diff->changed);
        $this->assertCount(0, $diff->added);

        $retraction = $diff->retracted->first();
        $this->assertInstanceOf(TemporalRetraction::class, $retraction);
        // Both sides are preserved: the anti-row now and the value row at kA.
        $this->assertTrue($retraction->to->getAttribute('is_retraction'));
        $this->assertSame(1000, $retraction->from?->getAttribute('amount'));
        $this->assertFalse($diff->isEmpty());
    }

    public function test_a_retraction_only_on_the_to_side_is_retracted_not_added(): void
    {
        // A retraction present only on the "to" side is a withdrawal recorded
        // between the two dates, not a newly added value. There is no earlier
        // side, so `from` is null.
        $to = [$this->price($this->row(1, 1, 1000, 'GBP', ['amount' => null, 'is_retraction' => true]))];

        $diff = DiffEngine::compare([], $to, $this->priceMeta());

        $this->assertCount(1, $diff->retracted);
        $this->assertCount(0, $diff->added);

        $retraction = $diff->retracted->first();
        $this->assertInstanceOf(TemporalRetraction::class, $retraction);
        $this->assertNull($retraction->from);
        $this->assertFalse($diff->isEmpty());
    }

    public function test_a_window_retracted_on_both_sides_is_not_re_reported_as_retracted(): void
    {
        // Already an anti-row on the "from" side: no NEW withdrawal happened, so
        // this stays on the ordinary changed/unchanged paths, not `retracted`.
        $retracted = ['amount' => null, 'is_retraction' => true];
        $from = [$this->price($this->row(1, 1, 1000, 'GBP', $retracted))];
        $to = [$this->price($this->row(2, 1, 1000, 'GBP', $retracted))];

        $diff = DiffEngine::compare($from, $to, $this->priceMeta());

        $this->assertCount(0, $diff->retracted);
        $this->assertCount(1, $diff->unchanged);
    }

    public function test_morph_owner_type_is_not_comparable(): void
    {
        // Kills entityColumns ArrayItemRemoval on the MorphTo branch (drops the
        // morph type column): morph rows differing only by owner_type read as
        // unchanged.
        $meta = (new Address)->temporalMetadata();

        $base = [
            'id' => 1, 'owner_type' => Customer::class, 'owner_id' => 1, 'label' => 'HQ',
            'valid_from' => '2026-02-01 00:00:00', 'valid_to' => null,
            'recorded_from' => '2026-01-01 00:00:00', 'recorded_to' => null,
            'is_retraction' => false,
        ];

        $from = [$this->address($base)];
        $to = [$this->address([...$base, 'owner_type' => Supplier::class])];

        $diff = DiffEngine::compare($from, $to, $meta);

        $this->assertCount(1, $diff->unchanged);
        $this->assertCount(0, $diff->changed);
    }
}
