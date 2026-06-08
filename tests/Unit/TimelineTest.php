<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;
use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;

final class TimelineTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function segment(?string $from, ?string $to, array $attributes = ['amount' => 1000], bool $retraction = false): TimelineSegment
    {
        return new TimelineSegment(
            new Spell($from === null ? null : CarbonImmutable::parse($from), $to === null ? null : CarbonImmutable::parse($to)),
            null,
            $attributes,
            $retraction,
        );
    }

    public function test_empty(): void
    {
        $timeline = Timeline::empty();

        $this->assertTrue($timeline->isEmpty());
        $this->assertCount(0, $timeline);
        $this->assertNull($timeline->head());
        $this->assertNull($timeline->tail());
        $this->assertNull($timeline->openEnded());
        $this->assertNull($timeline->at(CarbonImmutable::parse('2026-01-01')));
    }

    public function test_sorts_segments_on_construction(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-06-01', '2026-09-01'),
            $this->segment('2026-01-01', '2026-06-01'),
        ]);

        $segments = $timeline->segments();
        $this->assertTrue($segments[0]->validSpell->equals(Spell::between('2026-01-01', '2026-06-01')));
        $this->assertTrue($segments[1]->validSpell->equals(Spell::between('2026-06-01', '2026-09-01')));
    }

    public function test_open_start_sorts_first(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01'),
            $this->segment(null, '2026-01-01'),
        ]);

        $this->assertTrue($timeline->head()?->validSpell->isOpenStart());
    }

    public function test_sorts_open_start_segment_from_any_input_position(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-06-01', '2026-09-01'),
            $this->segment(null, '2026-01-01'),
            $this->segment('2026-01-01', '2026-06-01'),
        ]);

        $this->assertTrue($timeline->head()?->validSpell->isOpenStart());
        $this->assertTrue($timeline->tail()?->validSpell->equals(Spell::between('2026-06-01', '2026-09-01')));
    }

    public function test_sorts_open_start_segment_supplied_first(): void
    {
        $timeline = new Timeline([
            $this->segment(null, '2026-01-01'),
            $this->segment('2026-01-01', '2026-06-01'),
        ]);

        $this->assertTrue($timeline->head()?->validSpell->isOpenStart());
    }

    public function test_rejects_two_open_start_segments(): void
    {
        $this->expectException(TemporalOverlapException::class);

        new Timeline([
            $this->segment(null, '2026-03-01'),
            $this->segment(null, '2026-06-01'),
        ]);
    }

    public function test_rejects_overlapping_segments(): void
    {
        $this->expectException(TemporalOverlapException::class);
        $this->expectExceptionMessage('positions 0 and 1');

        new Timeline([
            $this->segment('2026-01-01', '2026-06-01'),
            $this->segment('2026-03-01', '2026-09-01'),
        ]);
    }

    public function test_rejects_open_ended_followed_by_another(): void
    {
        $this->expectException(TemporalOverlapException::class);

        new Timeline([
            $this->segment('2026-01-01', null),
            $this->segment('2026-06-01', '2026-09-01'),
        ]);
    }

    public function test_adjacent_segments_allowed(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01'),
            $this->segment('2026-06-01', '2026-09-01'),
        ]);

        $this->assertCount(2, $timeline);
    }

    public function test_at(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-06-01', null, ['amount' => 1200]),
        ]);

        $this->assertSame(1000, $timeline->at(CarbonImmutable::parse('2026-03-01'))?->attributes['amount']);
        $this->assertSame(1200, $timeline->at(CarbonImmutable::parse('2027-01-01'))?->attributes['amount']);
        $this->assertNull($timeline->at(CarbonImmutable::parse('2025-01-01')));
    }

    public function test_head_tail_open_ended(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01'),
            $this->segment('2026-06-01', null),
        ]);

        $this->assertTrue($timeline->head()?->validSpell->equals(Spell::between('2026-01-01', '2026-06-01')));
        $this->assertTrue($timeline->tail()?->validSpell->isOpenEnded());
        $this->assertTrue($timeline->openEnded()?->validSpell->isOpenEnded());
    }

    public function test_iterator(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01'),
            $this->segment('2026-06-01', '2026-09-01'),
        ]);

        $count = 0;
        foreach ($timeline as $segment) {
            $this->assertInstanceOf(TimelineSegment::class, $segment);
            $count++;
        }
        $this->assertSame(2, $count);
    }

    public function test_during_clips_segments(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-12-01', ['amount' => 1000]),
        ]);

        $clipped = $timeline->during(Spell::between('2026-04-01', '2026-09-01'));

        $this->assertCount(1, $clipped);
        $this->assertTrue($clipped->head()?->validSpell->equals(Spell::between('2026-04-01', '2026-09-01')));
    }

    public function test_during_drops_outside_segments(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-03-01'),
            $this->segment('2026-06-01', '2026-09-01'),
        ]);

        $clipped = $timeline->during(Spell::between('2026-05-01', '2026-07-01'));

        $this->assertCount(1, $clipped);
        $this->assertTrue($clipped->head()?->validSpell->equals(Spell::between('2026-06-01', '2026-07-01')));
    }

    public function test_spans(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01'),
            $this->segment('2026-06-01', '2026-09-01'),
        ]);

        $this->assertTrue($timeline->spans()->equals(Spell::between('2026-01-01', '2026-09-01')));
    }

    public function test_spans_single_segment(): void
    {
        $timeline = new Timeline([$this->segment('2026-01-01', '2026-06-01')]);

        $this->assertTrue($timeline->spans()->equals(Spell::between('2026-01-01', '2026-06-01')));
    }

    public function test_spans_open_start(): void
    {
        $timeline = new Timeline([
            $this->segment(null, '2026-06-01'),
            $this->segment('2026-06-01', '2026-09-01'),
        ]);

        $this->assertTrue($timeline->spans()->equals(Spell::endingAt('2026-09-01')));
    }

    public function test_spans_unbounded_when_open_ended(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01'),
            $this->segment('2026-06-01', null),
        ]);

        $this->assertTrue($timeline->spans()->isOpenEnded());
    }

    public function test_spans_empty_throws(): void
    {
        $this->expectException(TemporalInvalidSpellException::class);

        Timeline::empty()->spans();
    }

    public function test_merge(): void
    {
        $a = new Timeline([
            $this->segment('2026-01-01', '2026-03-01', ['amount' => 1]),
            $this->segment('2026-03-01', '2026-06-01', ['amount' => 2]),
        ]);
        $b = new Timeline([
            $this->segment('2026-06-01', '2026-09-01', ['amount' => 3]),
            $this->segment('2026-09-01', '2026-12-01', ['amount' => 4]),
        ]);

        $merged = $a->merge($b);

        $this->assertCount(4, $merged);

        $amounts = [];
        foreach ($merged as $segment) {
            $amounts[] = $segment->attributes['amount'];
        }
        $this->assertSame([1, 2, 3, 4], $amounts);
    }

    public function test_merge_overlap_throws(): void
    {
        $a = new Timeline([$this->segment('2026-01-01', '2026-06-01')]);
        $b = new Timeline([$this->segment('2026-03-01', '2026-09-01')]);

        $this->expectException(TemporalOverlapException::class);

        $a->merge($b);
    }

    public function test_subtract(): void
    {
        $timeline = new Timeline([$this->segment('2026-01-01', '2026-12-01')]);

        $result = $timeline->subtract(Spell::between('2026-04-01', '2026-08-01'));

        $this->assertCount(2, $result);
        $this->assertTrue($result->segments()[0]->validSpell->equals(Spell::between('2026-01-01', '2026-04-01')));
        $this->assertTrue($result->segments()[1]->validSpell->equals(Spell::between('2026-08-01', '2026-12-01')));
    }

    public function test_apply_correction(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', null, ['amount' => 1000]),
        ]);

        $next = $timeline->applyCorrection(new TimelineSegment(
            Spell::between('2026-04-01', '2026-07-01'),
            null,
            ['amount' => 1200],
        ));

        $expected = new Timeline([
            $this->segment('2026-01-01', '2026-04-01', ['amount' => 1000]),
            $this->segment('2026-04-01', '2026-07-01', ['amount' => 1200]),
            $this->segment('2026-07-01', null, ['amount' => 1000]),
        ]);

        $this->assertTrue($next->equals($expected));
    }

    public function test_apply_correction_rejects_anti_row(): void
    {
        $timeline = new Timeline([$this->segment('2026-01-01', null)]);

        $this->expectException(TemporalInvalidSpellException::class);
        $this->expectExceptionMessage('applyCorrection does not accept anti-row segments');

        $timeline->applyCorrection(new TimelineSegment(Spell::between('2026-04-01', '2026-07-01'), null, [], true));
    }

    public function test_apply_retraction(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', null, ['amount' => 1000]),
        ]);

        $next = $timeline->applyRetraction(Spell::between('2026-04-01', '2026-07-01'));

        $this->assertCount(3, $next);
        $retracted = $next->at(CarbonImmutable::parse('2026-05-01'));
        $this->assertTrue($retracted?->isAntiRow());
        $this->assertSame([], $retracted->attributes);
    }

    public function test_compact_merges_identical_adjacent(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-06-01', '2026-09-01', ['amount' => 1000]),
        ]);

        $compacted = $timeline->compact([]);

        $this->assertCount(1, $compacted);
        $this->assertTrue($compacted->head()?->validSpell->equals(Spell::between('2026-01-01', '2026-09-01')));
    }

    public function test_compact_keeps_different_attributes(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-06-01', '2026-09-01', ['amount' => 1200]),
        ]);

        $this->assertCount(2, $timeline->compact([]));
    }

    public function test_compact_does_not_merge_non_adjacent(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-07-01', '2026-09-01', ['amount' => 1000]),
        ]);

        $this->assertCount(2, $timeline->compact([]));
    }

    public function test_compact_does_not_cross_retraction_boundary(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-03-01', ['amount' => 1000]),
            $this->segment('2026-03-01', '2026-06-01', [], true),
            $this->segment('2026-06-01', '2026-09-01', ['amount' => 1000]),
        ]);

        $this->assertCount(3, $timeline->compact([]));
    }

    public function test_compact_merges_adjacent_anti_rows(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-03-01', [], true),
            $this->segment('2026-03-01', '2026-06-01', [], true),
        ]);

        $this->assertCount(1, $timeline->compact([]));
    }

    public function test_compact_respects_dimensions(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000, 'currency' => 'GBP']),
            $this->segment('2026-06-01', '2026-09-01', ['amount' => 1000, 'currency' => 'GBP']),
        ]);

        $this->assertCount(1, $timeline->compact(['currency']));
    }

    public function test_compact_merges_when_recorded_spells_match(): void
    {
        $recorded = Spell::between('2026-01-01', null);
        $timeline = new Timeline([
            new TimelineSegment(Spell::between('2026-01-01', '2026-06-01'), $recorded, ['amount' => 1000]),
            new TimelineSegment(Spell::between('2026-06-01', '2026-09-01'), $recorded, ['amount' => 1000]),
        ]);

        $this->assertCount(1, $timeline->compact([]));
    }

    public function test_compact_keeps_segments_with_different_recorded_spells(): void
    {
        $timeline = new Timeline([
            new TimelineSegment(Spell::between('2026-01-01', '2026-06-01'), Spell::between('2026-01-01', null), ['amount' => 1000]),
            new TimelineSegment(Spell::between('2026-06-01', '2026-09-01'), Spell::between('2026-02-01', null), ['amount' => 1000]),
        ]);

        $this->assertCount(2, $timeline->compact([]));
    }

    public function test_compact_is_idempotent(): void
    {
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-06-01', '2026-09-01', ['amount' => 1000]),
        ]);

        $once = $timeline->compact([]);
        $twice = $once->compact([]);

        $this->assertTrue($once->equals($twice));
    }

    public function test_equals(): void
    {
        $a = new Timeline([$this->segment('2026-01-01', '2026-06-01')]);
        $b = new Timeline([$this->segment('2026-01-01', '2026-06-01')]);
        $c = new Timeline([$this->segment('2026-01-01', '2026-07-01')]);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertFalse($a->equals(Timeline::empty()));
    }

    public function test_from_rows(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'recorded_from' => 'recorded_from',
            'recorded_to' => 'recorded_to',
            'is_retraction' => 'is_retraction',
        ];

        $timeline = Timeline::fromRows([
            [
                'amount' => 1000,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-06-01',
                'recorded_from' => '2026-01-01',
                'recorded_to' => null,
                'is_retraction' => false,
            ],
            [
                'amount' => 1200,
                'valid_from' => '2026-06-01',
                'valid_to' => null,
                'recorded_from' => '2026-01-01',
                'recorded_to' => null,
                'is_retraction' => false,
            ],
        ], $columnMap);

        $this->assertCount(2, $timeline);
        $this->assertSame(1000, $timeline->head()?->attributes['amount']);
        $this->assertArrayNotHasKey('valid_from', $timeline->head()->attributes);
        $this->assertNotNull($timeline->head()->recordedSpell);
        $this->assertTrue($timeline->head()->recordedSpell->from?->equalTo(CarbonImmutable::parse('2026-01-01')));
        $this->assertTrue($timeline->head()->recordedSpell->isOpenEnded());
    }

    public function test_from_rows_reads_retraction_flag(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'is_retraction' => 'is_retraction',
        ];

        $timeline = Timeline::fromRows([
            ['valid_from' => '2026-01-01', 'valid_to' => '2026-03-01', 'is_retraction' => 1],
            ['amount' => 1000, 'valid_from' => '2026-03-01', 'valid_to' => null, 'is_retraction' => 0],
        ], $columnMap);

        $this->assertTrue($timeline->head()?->isAntiRow());
        $this->assertFalse($timeline->tail()?->isAntiRow());
    }

    public function test_from_rows_defaults_retraction_to_false_when_absent(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'is_retraction' => 'is_retraction',
        ];

        $timeline = Timeline::fromRows([
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null],
        ], $columnMap);

        $this->assertFalse($timeline->head()?->isAntiRow());
    }

    public function test_from_rows_temporal_only(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'is_retraction' => 'is_retraction',
        ];

        $timeline = Timeline::fromRows([
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'is_retraction' => false],
        ], $columnMap);

        $this->assertNull($timeline->head()?->recordedSpell);
    }

    public function test_from_rows_accepts_datetime_objects(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'is_retraction' => 'is_retraction',
        ];

        $timeline = Timeline::fromRows([
            [
                'amount' => 1000,
                'valid_from' => new \DateTimeImmutable('2026-01-01'),
                'valid_to' => null,
                'is_retraction' => false,
            ],
        ], $columnMap);

        $this->assertTrue($timeline->head()?->validSpell->from?->equalTo(CarbonImmutable::parse('2026-01-01')));
    }

    public function test_from_rows_rejects_uninterpretable_value(): void
    {
        $columnMap = [
            'valid_from' => 'valid_from',
            'valid_to' => 'valid_to',
            'is_retraction' => 'is_retraction',
        ];

        $this->expectException(TemporalInvalidSpellException::class);
        $this->expectExceptionMessage('cannot be interpreted as a date');

        Timeline::fromRows([
            ['amount' => 1000, 'valid_from' => true, 'valid_to' => null, 'is_retraction' => false],
        ], $columnMap);
    }

    public function test_to_array(): void
    {
        $timeline = new Timeline([$this->segment('2026-01-01', '2026-06-01', ['amount' => 1000])]);

        $array = $timeline->toArray();

        $this->assertCount(1, $array);
        $this->assertSame(['amount' => 1000], $array[0]['attributes']);
    }
}
