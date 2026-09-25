<?php

namespace Tests\Unit;

use App\Oracly\Services\NilNilZeroOneLayStrategy;
use PHPUnit\Framework\TestCase;

class NilNilZeroOneLayStrategyTest extends TestCase
{
    /** Mesma aposta da Betfair em Azerbaijão x Tajiquistão (23/09): R$100 de risco em cada perna. */
    public function test_stakes_split_the_liability_by_each_odd(): void
    {
        $stakes = NilNilZeroOneLayStrategy::stakes(100, 11.0, 14.5);

        $this->assertEqualsWithDelta(10.0, $stakes['nil'], 0.001);
        $this->assertEqualsWithDelta(7.407, $stakes['one'], 0.001);
    }

    public function test_result_is_red_only_on_nil_nil_or_zero_one(): void
    {
        $this->assertSame('red_nil', NilNilZeroOneLayStrategy::result(0, 0));
        $this->assertSame('red_one', NilNilZeroOneLayStrategy::result('0', '1'));
        $this->assertSame('green', NilNilZeroOneLayStrategy::result(1, 0));
        $this->assertSame('green', NilNilZeroOneLayStrategy::result(2, 1));
        $this->assertNull(NilNilZeroOneLayStrategy::result(null, 1));
    }

    public function test_realized_return_matches_the_exchange_screen(): void
    {
        // Green: (10 + 7,41) × (1 − 6,5%) = 16,28. Red 0x0: −100 + 7,41. Red 0x1: −100 + 10.
        $this->assertEqualsWithDelta(0.1628, NilNilZeroOneLayStrategy::realizedReturn('green', 11.0, 14.5), 0.0001);
        $this->assertEqualsWithDelta(-0.9259, NilNilZeroOneLayStrategy::realizedReturn('red_nil', 11.0, 14.5), 0.0001);
        $this->assertEqualsWithDelta(-0.9000, NilNilZeroOneLayStrategy::realizedReturn('red_one', 11.0, 14.5), 0.0001);
    }

    public function test_expected_return_weights_each_outcome(): void
    {
        $expected = NilNilZeroOneLayStrategy::expectedReturn(0.06, 0.04, 11.0, 14.5);

        $this->assertEqualsWithDelta(0.90 * 0.162778 - 0.06 * 0.925926 - 0.04 * 0.9, $expected, 0.0001);
    }

    public function test_verdict_enters_only_with_positive_return_and_odds_within_the_cap(): void
    {
        $this->assertSame('enter', NilNilZeroOneLayStrategy::verdict(0.06, 0.04, 11.0, 14.5)['verdict']);
        // Ainda positivo, mas 0x0 acima de 13.
        $this->assertSame('thin', NilNilZeroOneLayStrategy::verdict(0.06, 0.04, 14.0, 14.5)['verdict']);
        // No lay, odd alta paga pouco no green: com 25 e 30 o red não se paga.
        $this->assertSame('skip', NilNilZeroOneLayStrategy::verdict(0.06, 0.04, 25.0, 30.0)['verdict']);
    }
}
