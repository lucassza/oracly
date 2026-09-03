<?php

namespace Tests\Unit;

use App\Oracly\Services\PunterLayCasaForaStrategy;
use PHPUnit\Framework\TestCase;

class PunterLayCasaForaStrategyTest extends TestCase
{
    public function test_it_lays_the_underdog_when_home_is_the_favorite(): void
    {
        $choice = (new PunterLayCasaForaStrategy)->choice([
            'oddHome' => 1.25, 'oddAway' => 9.0, 'punterFlagsLayFora' => false, 'punterFlagsLayCasa' => false,
        ]);

        $this->assertSame('fora', $choice['side']);
        $this->assertSame(1.25, $choice['favoriteOdd']);
        $this->assertSame(9.0, $choice['underdogOdd']);
        $this->assertFalse($choice['punterAgrees']);
    }

    public function test_it_lays_the_underdog_when_away_is_the_favorite(): void
    {
        $choice = (new PunterLayCasaForaStrategy)->choice([
            'oddHome' => 6.5, 'oddAway' => 1.40, 'punterFlagsLayCasa' => true, 'punterFlagsLayFora' => false,
        ]);

        $this->assertSame('casa', $choice['side']);
        $this->assertSame(1.40, $choice['favoriteOdd']);
        $this->assertTrue($choice['punterAgrees']);
    }

    public function test_it_returns_null_without_two_valid_odds(): void
    {
        $strategy = new PunterLayCasaForaStrategy;

        $this->assertNull($strategy->choice(['oddHome' => null, 'oddAway' => 2.0]));
        $this->assertNull($strategy->choice(['oddHome' => 0, 'oddAway' => 2.0]));
        $this->assertNull($strategy->choice(['oddHome' => 1.0, 'oddAway' => 2.0]));
        $this->assertNull($strategy->choice(['oddHome' => 2.0, 'oddAway' => 2.0]));
    }

    public function test_matches_profile_applies_the_favorite_odd_cutoff(): void
    {
        $strategy = new PunterLayCasaForaStrategy;
        $strongFavorite = ['oddHome' => 1.25, 'oddAway' => 9.0];
        $mediumFavorite = ['oddHome' => 1.65, 'oddAway' => 4.0];
        $weakFavorite = ['oddHome' => 2.5, 'oddAway' => 2.6];

        $this->assertTrue($strategy->matchesProfile($strongFavorite, 'strong'));
        $this->assertTrue($strategy->matchesProfile($strongFavorite, 'balanced'));
        $this->assertTrue($strategy->matchesProfile($strongFavorite, 'baseline'));

        $this->assertFalse($strategy->matchesProfile($mediumFavorite, 'strong'));
        $this->assertFalse($strategy->matchesProfile($mediumFavorite, 'balanced'));
        $this->assertTrue($strategy->matchesProfile($mediumFavorite, 'baseline'));

        $this->assertFalse($strategy->matchesProfile($weakFavorite, 'baseline'));
    }

    public function test_matches_profile_is_false_for_an_unknown_profile_or_no_choice(): void
    {
        $strategy = new PunterLayCasaForaStrategy;

        $this->assertFalse($strategy->matchesProfile(['oddHome' => 1.25, 'oddAway' => 9.0], 'inexistente'));
        $this->assertFalse($strategy->matchesProfile(['oddHome' => 2.0, 'oddAway' => 2.0], 'baseline'));
    }

    public function test_result_reads_the_matching_side_column(): void
    {
        $strategy = new PunterLayCasaForaStrategy;
        $row = ['resultLayFora' => 'green', 'resultLayCasa' => 'red'];

        $this->assertSame('green', $strategy->result($row, 'fora'));
        $this->assertSame('red', $strategy->result($row, 'casa'));
    }

    public function test_result_reads_the_ht_column_when_period_is_ht(): void
    {
        $strategy = new PunterLayCasaForaStrategy;
        $row = [
            'resultLayFora' => 'green', 'resultLayCasa' => 'red',
            'resultHtLayFora' => 'red', 'resultHtLayCasa' => 'green',
        ];

        // FT (padrão) e HT são colunas independentes — o mesmo lado pode acertar num período
        // e errar no outro (green no FT não implica green no HT, nem o contrário).
        $this->assertSame('green', $strategy->result($row, 'fora', 'ft'));
        $this->assertSame('red', $strategy->result($row, 'fora', 'ht'));
        $this->assertSame('red', $strategy->result($row, 'casa', 'ft'));
        $this->assertSame('green', $strategy->result($row, 'casa', 'ht'));
    }

    public function test_result_is_null_when_the_column_is_missing(): void
    {
        $strategy = new PunterLayCasaForaStrategy;

        $this->assertNull($strategy->result([], 'fora', 'ht'));
        $this->assertNull($strategy->result(['resultLayFora' => 'green'], 'fora', 'ht'));
    }
}
