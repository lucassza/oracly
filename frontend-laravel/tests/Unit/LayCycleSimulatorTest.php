<?php

namespace Tests\Unit;

use App\Oracly\Support\LayCycleSimulator;
use PHPUnit\Framework\TestCase;

class LayCycleSimulatorTest extends TestCase
{
    /** @return list<array{date: string, return: float}> */
    private static function entries(array $returns, string $month = '2026-01'): array
    {
        return array_map(fn (float $r): array => ['date' => $month.'-01', 'return' => $r], $returns);
    }

    public function test_cycle_until_double_banks_the_profit_and_restarts(): void
    {
        // +50% duas vezes: 100 → 150 → 225, dobrou. Próxima volta a 100 e ganha 50.
        $run = LayCycleSimulator::run(self::entries([0.5, 0.5, 0.5]), 100);

        $this->assertEqualsWithDelta(175.0, $run['result'], 0.001);
        $this->assertSame(1, $run['completed']);
        $this->assertSame(0, $run['broken']);
        $this->assertSame(0.0, $run['maxDrawdown']);
    }

    public function test_red_loses_the_stake_of_the_moment_including_the_cycle_profit(): void
    {
        // 100 → 110 (+10); red de −100% sobre 110 → resultado −100; volta a 100 e ganha 10.
        $run = LayCycleSimulator::run(self::entries([0.1, -1.0, 0.1]), 100);

        $this->assertEqualsWithDelta(-90.0, $run['result'], 0.001);
        $this->assertSame(0, $run['completed']);
        $this->assertSame(1, $run['broken']);
        $this->assertEqualsWithDelta(110.0, $run['maxDrawdown'], 0.001);
    }

    public function test_fixed_length_closes_after_n_greens(): void
    {
        // Ciclo de 2: 100 → 110 → 121 fecha (+21); próxima volta a 100.
        $run = LayCycleSimulator::run(self::entries([0.1, 0.1, 0.1]), 100, 2);

        $this->assertEqualsWithDelta(31.0, $run['result'], 0.001);
        $this->assertSame(1, $run['completed']);
    }

    public function test_months_add_up_to_the_result(): void
    {
        $entries = [...self::entries([0.1, 0.1], '2026-01'), ...self::entries([-0.5], '2026-02')];
        $run = LayCycleSimulator::run($entries, 100);

        $this->assertSame(['2026-01', '2026-02'], array_keys($run['months']));
        $this->assertEqualsWithDelta($run['result'], array_sum($run['months']), 0.001);
    }
}
