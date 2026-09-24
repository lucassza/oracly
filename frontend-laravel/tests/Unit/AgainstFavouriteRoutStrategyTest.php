<?php

namespace Tests\Unit;

use App\Oracly\Services\AgainstFavouriteRoutStrategy;
use PHPUnit\Framework\TestCase;

class AgainstFavouriteRoutStrategyTest extends TestCase
{
    private AgainstFavouriteRoutStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AgainstFavouriteRoutStrategy();
    }

    /** Mandante favorito forte num jogo aberto: o perfil de goleada mais provável. */
    private function strongHomeRow(): array
    {
        return [
            'oddHome' => 1.25,
            'oddDraw' => 6.50,
            'oddAway' => 11.00,
            'oddOver25' => 1.45,
            'oddUnder25' => 2.80,
            'homeGoals' => 2,
            'awayGoals' => 0,
        ];
    }

    /** Jogo parelho e fechado: goleada rara. */
    private function evenRow(): array
    {
        return [...$this->strongHomeRow(), 'oddHome' => 2.40, 'oddDraw' => 3.10, 'oddAway' => 3.20, 'oddOver25' => 2.20, 'oddUnder25' => 1.70];
    }

    public function test_the_favourite_is_the_side_with_the_lower_odd_and_a_tie_has_none(): void
    {
        $this->assertSame('home', $this->strategy->favouriteSide($this->strongHomeRow()));
        $this->assertSame('away', $this->strategy->favouriteSide([...$this->strongHomeRow(), 'oddHome' => 11.00, 'oddAway' => 1.25]));
        $this->assertNull($this->strategy->favouriteSide([...$this->strongHomeRow(), 'oddAway' => 1.25]));
        $this->assertNull($this->strategy->favouriteSide([...$this->strongHomeRow(), 'oddHome' => null]));
        $this->assertSame(1.25, $this->strategy->favouriteOdd($this->strongHomeRow()));
    }

    public function test_the_margin_is_read_from_the_favourite_side(): void
    {
        $this->assertSame(2, $this->strategy->favouriteMargin($this->strongHomeRow()));
        $this->assertSame(-2, $this->strategy->favouriteMargin([...$this->strongHomeRow(), 'oddHome' => 11.00, 'oddAway' => 1.25]));
        $this->assertNull($this->strategy->favouriteMargin([...$this->strongHomeRow(), 'awayGoals' => null]));
    }

    public function test_only_a_favourite_win_by_four_or_more_is_red(): void
    {
        $this->assertSame('red', $this->strategy->result([...$this->strongHomeRow(), 'homeGoals' => 4, 'awayGoals' => 0]));
        $this->assertSame('red', $this->strategy->result([...$this->strongHomeRow(), 'homeGoals' => 6, 'awayGoals' => 2]));
        $this->assertSame('green', $this->strategy->result([...$this->strongHomeRow(), 'homeGoals' => 4, 'awayGoals' => 1]));
        $this->assertSame('green', $this->strategy->result([...$this->strongHomeRow(), 'homeGoals' => 0, 'awayGoals' => 4]), 'goleada do azarão não é a do favorito');
        $this->assertSame('red', $this->strategy->result([...$this->strongHomeRow(), 'oddHome' => 11.00, 'oddAway' => 1.25, 'homeGoals' => 0, 'awayGoals' => 4]));
        $this->assertNull($this->strategy->result([...$this->strongHomeRow(), 'homeGoals' => null]));
    }

    public function test_a_strong_favourite_in_an_open_match_is_far_likelier_to_rout(): void
    {
        $strong = $this->strategy->probability($this->strongHomeRow());
        $even = $this->strategy->probability($this->evenRow());

        $this->assertGreaterThan(10.0, $strong);
        $this->assertLessThan(3.0, $even);
    }

    public function test_the_probability_does_not_depend_on_which_side_is_home(): void
    {
        $home = $this->strategy->probability($this->strongHomeRow());
        $away = $this->strategy->probability([...$this->strongHomeRow(), 'oddHome' => 11.00, 'oddAway' => 1.25]);

        $this->assertEqualsWithDelta($home, $away, 1e-6);
    }

    public function test_missing_odds_have_no_probability_and_match_no_profile(): void
    {
        $this->assertNull($this->strategy->probability([...$this->strongHomeRow(), 'oddOver25' => null]));
        $this->assertNull($this->strategy->probability([...$this->strongHomeRow(), 'oddAway' => 1.25]));
        $this->assertFalse($this->strategy->matchesProfile([...$this->strongHomeRow(), 'oddOver25' => null], 'baseline'));
    }

    public function test_profiles_are_probability_bands_with_an_inclusive_floor(): void
    {
        [$floor, $ceiling] = AgainstFavouriteRoutStrategy::PROFILE_BANDS['balanced'];

        $this->assertTrue(AgainstFavouriteRoutStrategy::probabilityMatchesProfile($floor, 'balanced'));
        $this->assertFalse(AgainstFavouriteRoutStrategy::probabilityMatchesProfile($ceiling, 'balanced'));
        $this->assertFalse(AgainstFavouriteRoutStrategy::probabilityMatchesProfile($floor - 0.01, 'balanced'));
        $this->assertFalse(AgainstFavouriteRoutStrategy::probabilityMatchesProfile(7.0, 'inexistente'));
        $this->assertFalse(AgainstFavouriteRoutStrategy::probabilityMatchesProfile(null, 'balanced'));
        $this->assertSame(array_keys(AgainstFavouriteRoutStrategy::PROFILES), array_keys(AgainstFavouriteRoutStrategy::PROFILE_BANDS));
    }
}
