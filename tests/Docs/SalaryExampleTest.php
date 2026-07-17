<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Docs\Models\Compensation;
use Vusys\Bitemporal\Tests\Docs\Models\Employee;

/**
 * Worked example: salary & compensation history (docs/16-example-salary.md).
 *
 * The whole domain turns on one question at each write: did the world change,
 * or was our record wrong? `changeEffectiveFrom` opens a new truth going
 * forward; `correct` rewrites a window while keeping the superseded belief on
 * the recorded axis so payroll stays reproducible.
 */
final class SalaryExampleTest extends DocsTestCase
{
    private function makeEmployee(): Employee
    {
        return Employee::query()->create(['name' => 'Ada']);
    }

    /**
     * Seed an open-ended base salary so the "raise" and "correction" sections
     * have a prior segment to act on.
     */
    private function seedBase(Employee $employee, int $amount, string $validFrom, string $recordedAt): void
    {
        CarbonImmutable::setTestNow($recordedAt);

        $employee->compensation()
            ->forDimensions(['component' => 'base'])
            ->changeEffectiveFrom(
                attributes: ['annual_amount' => $amount],
                validFrom: $validFrom,
            );
    }

    public function test_a_raise_that_starts_next_month_is_a_forward_change(): void
    {
        $employee = $this->makeEmployee();
        $this->seedBase($employee, 58_000, '2025-01-01', '2025-01-01 00:00:00');

        CarbonImmutable::setTestNow('2026-07-15 00:00:00');
        $employee->compensation()
            ->forDimensions(['component' => 'base'])
            ->changeEffectiveFrom(
                attributes: ['annual_amount' => 62_000],
                validFrom: '2026-08-01',
            );

        // Old rate still applies in July; the new rate opens on 1 August.
        $this->assertEquals(58_000, $employee->compensation()->forDimensions(['component' => 'base'])->validAt('2026-07-15')->currentKnowledge()->sole()->annual_amount);
        $this->assertEquals(62_000, $employee->compensation()->forDimensions(['component' => 'base'])->validAt('2026-08-15')->currentKnowledge()->sole()->annual_amount);
    }

    public function test_a_raise_that_should_have_started_months_ago_is_a_correction(): void
    {
        $employee = $this->makeEmployee();
        $this->seedBase($employee, 58_000, '2025-01-01', '2025-01-01 00:00:00');

        // The £62,000 should have taken effect on 1 May, not 1 August.
        CarbonImmutable::setTestNow('2026-08-15 00:00:00');
        $employee->compensation()
            ->forDimensions(['component' => 'base'])
            ->correct(
                attributes: ['annual_amount' => 62_000],
                validFrom: '2026-05-01',
                validTo: '2026-08-01',
            );

        // Current timeline shows £62,000 from May.
        $this->assertEquals(62_000, $employee->compensation()->forDimensions(['component' => 'base'])->validAt('2026-06-01')->currentKnowledge()->sole()->annual_amount);

        // But the belief we held (and paid) in May is still on the recorded axis.
        $asBelievedInMay = $employee->compensation()
            ->forDimensions(['component' => 'base'])
            ->validAt('2026-06-01')
            ->knownAt('2026-06-05')
            ->sole();
        $this->assertEquals(58_000, $asBelievedInMay->annual_amount);
    }

    public function test_reproducing_a_payroll_run_with_the_as_of_lens(): void
    {
        $employee = $this->makeEmployee();
        $this->seedBase($employee, 58_000, '2025-01-01', '2025-01-01 00:00:00');

        // The correction lands in August, after the May run was filed.
        CarbonImmutable::setTestNow('2026-08-15 00:00:00');
        $employee->compensation()
            ->forDimensions(['component' => 'base'])
            ->correct(
                attributes: ['annual_amount' => 62_000],
                validFrom: '2026-05-01',
                validTo: '2026-08-01',
            );

        // Reproduce the May run exactly as it was known on the original run date.
        $mayAsFiled = TemporalLens::asOf(
            validAt: '2026-05-31',      // pay effective at month-end
            knownAt: '2026-06-05',      // as the system believed it on the original run date
            callback: fn () => $employee->compensation()
                ->forDimensions(['component' => 'base'])
                ->sole()
                ->annual_amount,        // 58000 — what was actually filed
        );

        $this->assertEquals(58_000, $mayAsFiled);
    }

    public function test_expected_current_attributes_guard_against_a_lost_update(): void
    {
        $employee = $this->makeEmployee();

        // Bonus currently sits at £5,000.
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $employee->compensation()
            ->forDimensions(['component' => 'bonus'])
            ->changeEffectiveFrom(attributes: ['annual_amount' => 5_000], validFrom: '2026-01-01');

        // A write expecting a stale value fails loudly rather than clobbering.
        $this->expectException(TemporalWriteConflictException::class);

        $employee->compensation()
            ->forDimensions(['component' => 'bonus'])
            ->correct(
                attributes: ['annual_amount' => 8_000],
                validFrom: '2026-01-01',
                expectedCurrentAttributes: ['annual_amount' => 9_999],  // no longer matches
            );
    }

    public function test_expected_current_attributes_allow_the_write_when_they_match(): void
    {
        $employee = $this->makeEmployee();

        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $employee->compensation()
            ->forDimensions(['component' => 'bonus'])
            ->changeEffectiveFrom(attributes: ['annual_amount' => 5_000], validFrom: '2026-01-01');

        $employee->compensation()
            ->forDimensions(['component' => 'bonus'])
            ->correct(
                attributes: ['annual_amount' => 8_000],
                validFrom: '2026-01-01',
                expectedCurrentAttributes: ['annual_amount' => 5_000],  // still 5,000 — proceed
            );

        $this->assertEquals(8_000, $employee->compensation()->forDimensions(['component' => 'bonus'])->currentKnowledge()->validAt('2026-06-01')->sole()->annual_amount);
    }

    public function test_base_and_bonus_are_independent_dimensions(): void
    {
        $employee = $this->makeEmployee();

        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $employee->compensation()->forDimensions(['component' => 'base'])->changeEffectiveFrom(['annual_amount' => 58_000], '2026-01-01');
        $employee->compensation()->forDimensions(['component' => 'bonus'])->changeEffectiveFrom(['annual_amount' => 5_000], '2026-01-01');

        // Correcting the bonus never disturbs base pay.
        $employee->compensation()->forDimensions(['component' => 'bonus'])->correct(['annual_amount' => 6_000], validFrom: '2026-01-01');

        $this->assertEquals(58_000, $employee->compensation()->forDimensions(['component' => 'base'])->currentKnowledge()->sole()->annual_amount);
        $this->assertEquals(6_000, $employee->compensation()->forDimensions(['component' => 'bonus'])->currentKnowledge()->sole()->annual_amount);
    }

    public function test_as_timeline_returns_ordered_non_overlapping_segments(): void
    {
        $employee = $this->makeEmployee();
        $this->seedBase($employee, 58_000, '2025-01-01', '2025-01-01 00:00:00');

        CarbonImmutable::setTestNow('2026-07-15 00:00:00');
        $employee->compensation()->forDimensions(['component' => 'base'])->changeEffectiveFrom(['annual_amount' => 62_000], '2026-08-01');

        $timeline = $employee->compensation()
            ->forDimensions(['component' => 'base'])
            ->currentKnowledge()
            ->asTimeline();

        $amounts = [];
        foreach ($timeline as $segment) {
            $amounts[] = $segment->attributes['annual_amount'];
        }

        // Loose comparison — the decimal column reads back as a string.
        $this->assertEquals([58_000, 62_000], $amounts);

        $head = $timeline->head();
        $this->assertNotNull($head);
        $this->assertNotNull($head->validSpell->from);
        $this->assertTrue($head->validSpell->from->equalTo(CarbonImmutable::parse('2025-01-01')));
    }

    public function test_full_history_includes_every_physical_row(): void
    {
        $employee = $this->makeEmployee();
        $this->seedBase($employee, 58_000, '2025-01-01', '2025-01-01 00:00:00');

        // A correction supersedes a belief, so fullHistory() shows more rows
        // than the current-knowledge timeline.
        CarbonImmutable::setTestNow('2026-08-15 00:00:00');
        $employee->compensation()->forDimensions(['component' => 'base'])->correct(['annual_amount' => 62_000], validFrom: '2026-05-01', validTo: '2026-08-01');

        $current = $employee->compensation()->forDimensions(['component' => 'base'])->currentKnowledge()->get();
        $everyBelief = $employee->compensation()->forDimensions(['component' => 'base'])->fullHistory()->get();

        $this->assertGreaterThan($current->count(), $everyBelief->count());
    }

    public function test_backfill_seeds_a_clean_current_knowledge_timeline(): void
    {
        $employee = $this->makeEmployee();

        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        // Flat value columns, no recorded_from — timeline() stamps the recorded
        // axis as "now" and the rows land as a clean current-knowledge history.
        $employee->compensation()
            ->forDimensions(['component' => 'base'])
            ->backfill()
            ->timeline([
                ['annual_amount' => 50_000, 'valid_from' => '2023-01-01', 'valid_to' => '2024-01-01'],
                ['annual_amount' => 54_000, 'valid_from' => '2024-01-01', 'valid_to' => '2025-01-01'],
                ['annual_amount' => 58_000, 'valid_from' => '2025-01-01', 'valid_to' => null],
            ]);

        $this->assertEquals(50_000, $employee->compensation()->forDimensions(['component' => 'base'])->validAt('2023-06-01')->currentKnowledge()->sole()->annual_amount);
        $this->assertEquals(54_000, $employee->compensation()->forDimensions(['component' => 'base'])->validAt('2024-06-01')->currentKnowledge()->sole()->annual_amount);
        $this->assertEquals(58_000, $employee->compensation()->forDimensions(['component' => 'base'])->validAt('2026-06-01')->currentKnowledge()->sole()->annual_amount);
    }
}
