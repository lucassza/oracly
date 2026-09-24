<?php

namespace Tests\Unit;

use App\Oracly\Services\AgainstNilNilStrategy;
use App\Oracly\Support\LayPricing;
use PHPUnit\Framework\TestCase;

class AgainstNilNilStrategyTest extends TestCase
{
    private AgainstNilNilStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AgainstNilNilStrategy();
    }

    /** Mandante muito favorito (~73% sem margem), jogo aberto. */
    private function strongFavouriteRow(): array
    {
        return [
            'oddHome' => 1.30, 'oddDraw' => 5.50, 'oddAway' => 10.00,
            'oddOver25' => 1.60, 'oddUnder25' => 2.35,
            'oddUnder05' => 13.0, 'oddOver05' => 1.03,
            'homeGoals' => 2, 'awayGoals' => 0,
        ];
    }

    /** Jogo equilibrado e travado. */
    private function tightRow(): array
    {
        return [...$this->strongFavouriteRow(), 'oddHome' => 2.70, 'oddDraw' => 3.00, 'oddAway' => 2.90, 'oddOver25' => 2.40, 'oddUnder25' => 1.55];
    }

    public function test_profiles_cut_on_the_favourite_win_probability(): void
    {
        $favourite = $this->strategy->favouriteProbability($this->strongFavouriteRow());
        $this->assertGreaterThan(0.70, $favourite);

        foreach (array_keys(AgainstNilNilStrategy::PROFILES) as $profile) {
            $this->assertTrue($this->strategy->matchesProfile($this->strongFavouriteRow(), $profile), $profile);
            $this->assertFalse($this->strategy->matchesProfile($this->tightRow(), $profile), $profile);
        }
        $this->assertFalse($this->strategy->matchesProfile($this->strongFavouriteRow(), 'inexistente'));
    }

    public function test_a_game_without_over_price_still_qualifies_but_has_no_own_probability(): void
    {
        $row = [...$this->strongFavouriteRow(), 'oddOver25' => null];

        $this->assertTrue($this->strategy->matchesProfile($row, 'strong'));
        $this->assertNull($this->strategy->probability($row));
        $this->assertSame(['probability' => null, 'profiles' => ['baseline', 'balanced', 'strong']], $this->strategy->evaluate($row));
    }

    public function test_the_calibration_lowers_nil_nil_for_a_strong_favourite_relative_to_raw_poisson(): void
    {
        $raw = $this->strategy->scoreProbability($this->strongFavouriteRow());
        $calibrated = $this->strategy->probability($this->strongFavouriteRow());

        $this->assertLessThan($raw, $calibrated);
        // Jogo travado tem muito mais 0x0 que o jogo do favorito forte.
        $this->assertGreaterThan($calibrated * 2, $this->strategy->probability($this->tightRow()));
    }

    public function test_evaluate_matches_the_individual_calls(): void
    {
        $row = $this->strongFavouriteRow();
        $evaluation = $this->strategy->evaluate($row);

        $this->assertEqualsWithDelta($this->strategy->probability($row), $evaluation['probability'], 1e-12);
        $this->assertSame(['baseline', 'balanced', 'strong'], $evaluation['profiles']);
    }

    public function test_market_probability_removes_the_margin_proportionally(): void
    {
        $expected = (1 / 13.0) / (1 / 13.0 + 1 / 1.03) * 100;

        $this->assertEqualsWithDelta($expected, $this->strategy->marketProbability($this->strongFavouriteRow()), 1e-9);
        $this->assertNull($this->strategy->marketProbability([...$this->strongFavouriteRow(), 'oddOver05' => null]));
    }

    public function test_only_a_goalless_final_is_red(): void
    {
        $this->assertSame('red', $this->strategy->result([...$this->tightRow(), 'homeGoals' => 0, 'awayGoals' => 0]));
        $this->assertSame('green', $this->strategy->result([...$this->tightRow(), 'homeGoals' => 1, 'awayGoals' => 0]));
        $this->assertNull($this->strategy->result([...$this->tightRow(), 'awayGoals' => null]));
    }

    public function test_lay_pricing_breaks_even_near_ninety_five_percent_of_fair_with_commission(): void
    {
        $fair = LayPricing::fairOdd(4.0);
        $this->assertEqualsWithDelta(25.0, $fair, 1e-9);
        $this->assertEqualsWithDelta(22.5, LayPricing::maxEntryOdd($fair), 1e-9);

        // Na odd justa, a comissão deixa o retorno negativo; na máxima de entrada, positivo.
        $this->assertLessThan(0, LayPricing::expectedReturn(4.0, $fair));
        $this->assertGreaterThan(0, LayPricing::expectedReturn(4.0, LayPricing::maxEntryOdd($fair)));
        $this->assertNull(LayPricing::expectedReturn(4.0, 1.0));

        // Green paga 1/(odd − 1) menos comissão; red custa a responsabilidade inteira.
        $this->assertEqualsWithDelta((1 - LayPricing::COMMISSION) / 24, LayPricing::realizedReturn(true, 25.0), 1e-12);
        $this->assertSame(-1.0, LayPricing::realizedReturn(false, 25.0));
    }
}
