<?php

namespace Tests\Unit;

use App\Oracly\Services\AgainstTwoTwoStrategy;
use PHPUnit\Framework\TestCase;

class AgainstTwoTwoStrategyTest extends TestCase
{
    private AgainstTwoTwoStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AgainstTwoTwoStrategy();
    }

    /** Jogo equilibrado e aberto: o perfil de 2x2 mais provável. */
    private function openRow(): array
    {
        return [
            'oddHome' => 2.60,
            'oddDraw' => 3.60,
            'oddAway' => 2.60,
            'oddOver25' => 1.55,
            'oddUnder25' => 2.50,
            'homeGoals' => 1,
            'awayGoals' => 0,
        ];
    }

    /** Favorito forte num jogo de poucos gols: o 2x2 fica raro. */
    private function lopsidedRow(): array
    {
        return [...$this->openRow(), 'oddHome' => 1.25, 'oddDraw' => 6.00, 'oddAway' => 12.00, 'oddOver25' => 2.30, 'oddUnder25' => 1.62];
    }

    public function test_expected_goals_reproduce_the_over_price_and_split_evenly_when_the_match_odds_are_even(): void
    {
        $goals = $this->strategy->expectedGoals($this->openRow());
        $total = $goals['home'] + $goals['away'];
        $pOver = 1 - exp(-$total) * (1 + $total + $total ** 2 / 2);

        $this->assertEqualsWithDelta((1 / 1.55) / (1 / 1.55 + 1 / 2.50), $pOver, 1e-6);
        $this->assertEqualsWithDelta($goals['home'], $goals['away'], 1e-6);
    }

    public function test_the_favourite_gets_the_larger_share_of_the_goals(): void
    {
        $home = $this->strategy->expectedGoals($this->lopsidedRow());
        $away = $this->strategy->expectedGoals([...$this->lopsidedRow(), 'oddHome' => 12.00, 'oddAway' => 1.25]);

        $this->assertGreaterThan($home['away'] * 3, $home['home']);
        $this->assertEqualsWithDelta($home['home'], $away['away'], 1e-6);
    }

    public function test_a_balanced_open_match_has_a_higher_two_two_probability_than_a_lopsided_tight_one(): void
    {
        $open = $this->strategy->scoreProbability($this->openRow());
        $lopsided = $this->strategy->scoreProbability($this->lopsidedRow());

        $this->assertGreaterThan(5.0, $open);
        $this->assertLessThan(2.5, $lopsided);
    }

    public function test_profiles_are_nested_by_the_probability_cut(): void
    {
        $this->assertFalse($this->strategy->matchesProfile($this->openRow(), 'baseline'));

        foreach (array_keys(AgainstTwoTwoStrategy::PROFILES) as $profile) {
            $this->assertTrue($this->strategy->matchesProfile($this->lopsidedRow(), $profile));
        }

        $this->assertFalse($this->strategy->matchesProfile($this->lopsidedRow(), 'inexistente'));
    }

    public function test_without_the_under_price_the_margin_comes_from_the_measured_average(): void
    {
        $withUnder = $this->strategy->scoreProbability($this->openRow());
        $overOnly = $this->strategy->scoreProbability([...$this->openRow(), 'oddUnder25' => null]);

        $this->assertNotNull($overOnly);
        $this->assertEqualsWithDelta($withUnder, $overOnly, 0.3);
    }

    public function test_missing_or_dirty_odds_have_no_probability_instead_of_a_guess(): void
    {
        $this->assertNull($this->strategy->scoreProbability([...$this->openRow(), 'oddOver25' => null]));
        $this->assertNull($this->strategy->scoreProbability([...$this->openRow(), 'oddDraw' => null]));
        $this->assertNull($this->strategy->scoreProbability([...$this->openRow(), 'oddHome' => 1.00]));
        $this->assertNull($this->strategy->scoreProbability([...$this->openRow(), 'oddAway' => 'n/a']));
        $this->assertFalse($this->strategy->matchesProfile([...$this->lopsidedRow(), 'oddOver25' => null], 'baseline'));
    }

    public function test_extreme_over_prices_stay_inside_the_search_range(): void
    {
        $probability = $this->strategy->scoreProbability([...$this->openRow(), 'oddOver25' => 1.01, 'oddUnder25' => 30.0]);

        $this->assertNotNull($probability);
        $this->assertGreaterThan(0.0, $probability);
    }

    public function test_only_a_final_two_two_is_red(): void
    {
        $this->assertSame('red', $this->strategy->result([...$this->openRow(), 'homeGoals' => 2, 'awayGoals' => 2]));
        $this->assertSame('green', $this->strategy->result([...$this->openRow(), 'homeGoals' => 2, 'awayGoals' => 1]));
        $this->assertSame('green', $this->strategy->result([...$this->openRow(), 'homeGoals' => 0, 'awayGoals' => 0]));
        $this->assertNull($this->strategy->result([...$this->openRow(), 'homeGoals' => null]));
    }

    public function test_fair_lay_odd_is_the_inverse_of_the_observed_frequency(): void
    {
        $this->assertEqualsWithDelta(26.91, AgainstTwoTwoStrategy::fairLayOdd(3.716), 0.01);
        $this->assertNull(AgainstTwoTwoStrategy::fairLayOdd(0.0));
        $this->assertNull(AgainstTwoTwoStrategy::fairLayOdd(null));
    }
}
