<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;
use Vusys\Bitemporal\TimelineSegment;

final class TimelineSegmentTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function segment(
        string $from,
        ?string $to,
        array $attributes = ['amount' => 1000, 'currency' => 'GBP'],
        bool $retraction = false,
    ): TimelineSegment {
        return new TimelineSegment(
            Spell::between($from, $to),
            null,
            $attributes,
            $retraction,
        );
    }

    public function test_anti_row_alias(): void
    {
        $this->assertFalse($this->segment('2026-01-01', '2026-06-01')->isAntiRow());
        $this->assertTrue($this->segment('2026-01-01', '2026-06-01', [], true)->isAntiRow());
    }

    public function test_has_same_attributes_ignores_spell(): void
    {
        $a = $this->segment('2026-01-01', '2026-06-01');
        $b = $this->segment('2026-06-01', '2026-09-01');

        $this->assertTrue($a->hasSameAttributesAs($b));
        $this->assertFalse($a->equals($b));
    }

    public function test_has_same_attributes_is_key_order_independent(): void
    {
        $a = $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000, 'currency' => 'GBP']);
        $b = $this->segment('2026-01-01', '2026-06-01', ['currency' => 'GBP', 'amount' => 1000]);

        $this->assertTrue($a->hasSameAttributesAs($b));
    }

    public function test_anti_row_never_equal_to_non_anti_row(): void
    {
        $normal = $this->segment('2026-01-01', '2026-06-01', []);
        $anti = $this->segment('2026-01-01', '2026-06-01', [], true);

        $this->assertFalse($normal->hasSameAttributesAs($anti));
    }

    public function test_has_same_dimensions(): void
    {
        $a = $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000, 'currency' => 'GBP']);
        $b = $this->segment('2026-01-01', '2026-06-01', ['amount' => 2000, 'currency' => 'GBP']);
        $c = $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000, 'currency' => 'USD']);

        $this->assertTrue($a->hasSameDimensionsAs($b, ['currency']));
        $this->assertFalse($a->hasSameDimensionsAs($c, ['currency']));
        $this->assertTrue($a->hasSameDimensionsAs($c, []));
    }

    public function test_with_valid_spell(): void
    {
        $segment = $this->segment('2026-01-01', '2026-06-01');
        $moved = $segment->withValidSpell(Spell::between('2026-02-01', '2026-07-01'));

        $this->assertTrue($moved->validSpell->equals(Spell::between('2026-02-01', '2026-07-01')));
        $this->assertSame($segment->attributes, $moved->attributes);
        $this->assertSame($segment->isRetraction, $moved->isRetraction);
    }

    public function test_with_attributes(): void
    {
        $segment = $this->segment('2026-01-01', '2026-06-01');
        $changed = $segment->withAttributes(['amount' => 1200]);

        $this->assertSame(['amount' => 1200], $changed->attributes);
        $this->assertTrue($segment->validSpell->equals($changed->validSpell));
    }

    public function test_equals_considers_all_fields(): void
    {
        $base = new TimelineSegment(
            Spell::between('2026-01-01', '2026-06-01'),
            Spell::between('2026-01-01', null),
            ['amount' => 1000],
        );

        $this->assertTrue($base->equals(new TimelineSegment(
            Spell::between('2026-01-01', '2026-06-01'),
            Spell::between('2026-01-01', null),
            ['amount' => 1000],
        )));

        $this->assertFalse($base->equals(new TimelineSegment(
            Spell::between('2026-01-01', '2026-07-01'),
            Spell::between('2026-01-01', null),
            ['amount' => 1000],
        )));

        $this->assertFalse($base->equals(new TimelineSegment(
            Spell::between('2026-01-01', '2026-06-01'),
            null,
            ['amount' => 1000],
        )));

        $this->assertFalse($base->equals(new TimelineSegment(
            Spell::between('2026-01-01', '2026-06-01'),
            Spell::between('2026-01-01', null),
            ['amount' => 1200],
        )));

        $this->assertFalse($base->equals(new TimelineSegment(
            Spell::between('2026-01-01', '2026-06-01'),
            Spell::between('2026-01-01', null),
            ['amount' => 1000],
            true,
        )));
    }

    public function test_to_row(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'recorded_from' => 'recorded_from',
            'recorded_to' => 'recorded_to',
            'is_retraction' => 'is_retraction',
        ];

        $segment = new TimelineSegment(
            Spell::between('2026-01-01', '2026-06-01'),
            Spell::between('2026-01-01', null),
            ['amount' => 1000, 'currency' => 'GBP'],
        );

        $row = $segment->toRow($columnMap);

        $this->assertSame(1000, $row['amount']);
        $this->assertSame('GBP', $row['currency']);
        $this->assertInstanceOf(CarbonImmutable::class, $row['valid_from']);
        $this->assertTrue($row['valid_from']->equalTo(CarbonImmutable::parse('2026-01-01')));
        $this->assertInstanceOf(CarbonImmutable::class, $row['valid_to']);
        $this->assertTrue($row['valid_to']->equalTo(CarbonImmutable::parse('2026-06-01')));
        $this->assertInstanceOf(CarbonImmutable::class, $row['recorded_from']);
        $this->assertNull($row['recorded_to']);
        $this->assertFalse($row['is_retraction']);
    }

    public function test_to_row_temporal_only_omits_recorded(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'recorded_from' => 'recorded_from',
            'recorded_to' => 'recorded_to',
            'is_retraction' => 'is_retraction',
        ];

        $row = $this->segment('2026-01-01', '2026-06-01')->toRow($columnMap);

        $this->assertArrayNotHasKey('recorded_from', $row);
        $this->assertArrayNotHasKey('recorded_to', $row);
    }

    public function test_to_array(): void
    {
        $segment = new TimelineSegment(
            Spell::between('2026-01-01', '2026-06-01'),
            null,
            ['amount' => 1000],
            false,
        );

        $array = $segment->toArray();

        $this->assertSame(['amount' => 1000], $array['attributes']);
        $this->assertNull($array['recorded_spell']);
        $this->assertFalse($array['is_retraction']);
        $this->assertArrayHasKey('from', $array['valid_spell']);
    }
}
