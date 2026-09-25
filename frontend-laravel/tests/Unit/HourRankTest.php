<?php

namespace Tests\Unit;

use App\Oracly\Support\HourRank;
use PHPUnit\Framework\TestCase;

class HourRankTest extends TestCase
{
    /** Kickoffs em UTC; Brasília = UTC−3. */
    private static function rows(): array
    {
        return [
            ['id' => 'a', 'kickoffAt' => '2026-09-06 18:00:00+00', 'oddHome' => 1.60, 'oddAway' => 5.0],
            ['id' => 'b', 'kickoffAt' => '2026-09-06 18:00:00+00', 'oddHome' => 1.45, 'oddAway' => 6.0],
            ['id' => 'c', 'kickoffAt' => '2026-09-06 18:30:00+00', 'oddHome' => 1.42, 'oddAway' => 7.0],
            ['id' => 'd', 'kickoffAt' => '2026-09-06 21:00:00+00', 'oddHome' => 1.55, 'oddAway' => 5.5],
        ];
    }

    public function test_rank_numbers_each_pick_inside_its_brasilia_hour(): void
    {
        $ranks = array_column(HourRank::rank(self::rows(), HourRank::bestOdd(...)), 'rank', 'id');

        $this->assertSame(['c' => 1, 'b' => 2, 'a' => 3, 'd' => 1], $ranks);
    }

    public function test_select_rules(): void
    {
        $ids = fn (string $rule): array => array_column(HourRank::select(self::rows(), $rule), 'id');

        $this->assertSame(['b', 'a', 'c', 'd'], $ids('all'));
        $this->assertSame(['b', 'c', 'd'], $ids('per_kickoff'));
        $this->assertSame(['c', 'd'], $ids('best_of_hour'));
        // c começa 30 min depois de b, que ainda está em jogo.
        $this->assertSame(['b', 'd'], $ids('no_overlap'));
    }
}
