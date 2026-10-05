<?php

namespace Shirahcan\CalendarClient\Tests\Laravel;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\Laravel\Spec\SpecBuilder as B;

/** K5: one rules-to-spec mapping for every product; each product difference is an option. */
class SpecBuilderTest extends TestCase
{
    private const ZONE = 'America/Edmonton';

    private function row(array $over): array
    {
        return array_merge(['kind' => 'weekly', 'day' => 1, 'start' => '09:00', 'end' => '12:00', 'tz' => self::ZONE, 'duration' => 30, 'buffer' => 0], $over);
    }

    private function build(array $rows, array $o = []): array
    {
        return B::build($rows, $o + ['zone' => self::ZONE, 'today' => CarbonImmutable::parse('2026-10-01')]);
    }

    public function test_weekly_rows_carry_their_validity_and_past_rows_are_left_out(): void
    {
        $out = $this->build([$this->row(['from' => '2026-10-10', 'until' => '2026-10-20']), $this->row(['day' => 2, 'until' => '2026-09-01'])]);

        $this->assertSame([['days' => ['mon'], 'start' => '09:00', 'end' => '12:00', 'valid_from' => '2026-10-10', 'valid_until' => '2026-10-20']], $out['spec']['weekly']);
        $this->assertSame(self::ZONE, $out['spec']['timezone']['zone']);
    }

    public function test_with_periods_on_only_period_rows_count_and_take_the_periods_dates(): void
    {
        $out = $this->build([$this->row(['period' => 7, 'day' => 2]), $this->row(['day' => 3])], ['periods_on' => true, 'periods' => [7 => ['from' => '2026-10-05', 'to' => null]]]);

        $this->assertSame([['days' => ['tue'], 'start' => '09:00', 'end' => '12:00', 'valid_from' => '2026-10-05']], $out['spec']['weekly']);
    }

    public function test_override_rows_on_one_date_merge_and_an_empty_date_closes_only_when_asked(): void
    {
        $rows = [
            $this->row(['kind' => 'date', 'date' => '2026-10-12', 'start' => '10:00', 'end' => '12:00']),
            $this->row(['kind' => 'date', 'date' => '2026-10-12', 'start' => '11:00', 'end' => '14:00']),
            $this->row(['kind' => 'date', 'date' => '2026-10-13', 'start' => null, 'end' => null]),
        ];

        $this->assertSame([['date' => '2026-10-12', 'windows' => [['10:00', '14:00']]]], $this->build($rows)['spec']['overrides']);
        $this->assertSame(['date' => '2026-10-13', 'windows' => []], $this->build($rows, ['empty_date_closes' => true])['spec']['overrides'][1]);
    }

    public function test_blocks_keep_their_span_or_their_clock_times(): void
    {
        $out = $this->build([
            $this->row(['kind' => 'block', 'date' => '2026-10-12', 'to' => '2026-10-16', 'start' => null, 'end' => null]),
            $this->row(['kind' => 'block', 'date' => '2026-10-20', 'start' => '22:00', 'end' => '02:00']),
        ]);

        $this->assertSame([['from' => '2026-10-12', 'to' => '2026-10-16'], ['start' => '2026-10-20T22:00', 'end' => '2026-10-21T02:00']], $out['spec']['blocks']);
    }

    public function test_a_window_past_midnight_is_kept_or_skipped_by_option(): void
    {
        $rows = [$this->row(['start' => '18:00', 'end' => '00:00']), $this->row(['day' => 2, 'start' => '22:00', 'end' => '02:00'])];

        $this->assertSame([['days' => ['mon'], 'start' => '18:00', 'end' => '24:00']], $this->build($rows)['spec']['weekly'], 'until midnight, by default');
        $this->assertCount(2, $this->build($rows, ['cross_midnight' => true])['spec']['weekly']);
        $this->assertArrayNotHasKey('weekly', $this->build($rows, ['midnight_end_of_day' => false])['spec'], 'an engine that reads 00:00 as the same day drops it');
    }

    public function test_mixed_zones_are_refused_and_mixed_lengths_and_buffers_noted(): void
    {
        $this->assertSame(['mixed_zones:America/Edmonton,UTC'], $this->build([$this->row([]), $this->row(['day' => 2, 'tz' => 'UTC'])])['problems']);

        $out = $this->build([$this->row([]), $this->row(['day' => 2, 'duration' => 60, 'buffer' => 10]), $this->row(['day' => 3])], ['min_buffer' => 5]);
        $this->assertSame(30, $out['duration']);
        $this->assertSame(10, $out['buffer']);
        $this->assertSame(['mixed_durations:30,60', 'mixed_buffers:5,10'], $out['notes']);
    }

    public function test_a_rule_zone_unlike_the_profile_is_noted_and_used(): void
    {
        $out = $this->build([$this->row(['tz' => 'Africa/Lagos'])], ['zone' => 'America/Toronto']);

        $this->assertSame('Africa/Lagos', $out['spec']['timezone']['zone']);
        $this->assertSame(['rules_zone_differs_from_profile:Africa/Lagos!=America/Toronto'], $out['notes']);
    }

    public function test_day_gaps_carry_each_days_own_buffer_only_when_buffers_differ(): void
    {
        $rows = [
            $this->row(['day' => 1, 'buffer' => 15]),
            $this->row(['day' => 4, 'buffer' => 2]),
            // 2026-10-15 is a Thursday: its override takes the larger of its own and Thursday's.
            $this->row(['kind' => 'date', 'date' => '2026-10-15', 'start' => '13:00', 'end' => '15:00', 'buffer' => 0]),
        ];
        $out = $this->build($rows, ['day_gaps' => true, 'min_buffer' => 5]);

        $this->assertSame([15, 5], array_column($out['spec']['weekly'], 'gap'));
        $this->assertSame(5, $out['spec']['overrides'][0]['gap']);
        $this->assertSame(15, $out['buffer']);

        // Off by default, and silent when every buffer agrees.
        $this->assertArrayNotHasKey('gap', $this->build($rows)['spec']['weekly'][0]);
        $same = $this->build([$this->row(['buffer' => 10]), $this->row(['day' => 2, 'buffer' => 10])], ['day_gaps' => true]);
        $this->assertArrayNotHasKey('gap', $same['spec']['weekly'][0]);
    }

    public function test_no_rows_is_an_empty_spec_in_the_profile_zone(): void
    {
        $this->assertSame(['schema' => 1, 'timezone' => ['zone' => self::ZONE]], $this->build([])['spec']);
    }
}
