<?php

namespace Tests\Unit;

use App\Oracly\Services\AgainstFavouriteCleanSheetStrategy;
use PHPUnit\Framework\TestCase;

class AgainstFavouriteCleanSheetStrategyTest extends TestCase
{
    private AgainstFavouriteCleanSheetStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AgainstFavouriteCleanSheetStrategy();
    }

    /** Linha que passa em todos os perfis: mandante favorito, BTTS alto, placar 2x1. */
    private function strongRow(): array
    {
        return [
            'oddHome' => 1.80,
            'oddAway' => 4.20,
            'oddBttsYes' => 1.55,
            'oddBttsNo' => 2.50,
            'homeGoals' => 2,
            'awayGoals' => 1,
        ];
    }

    public function test_the_favourite_is_the_side_with_the_lower_match_odd(): void
    {
        $this->assertSame('home', $this->strategy->favourite($this->strongRow()));
        $this->assertSame('away', $this->strategy->favourite([...$this->strongRow(), 'oddHome' => 4.20, 'oddAway' => 1.80]));
    }

    public function test_tied_or_missing_odds_have_no_favourite_instead_of_defaulting_to_home(): void
    {
        $this->assertNull($this->strategy->favourite([...$this->strongRow(), 'oddHome' => 2.00, 'oddAway' => 2.00]));
        $this->assertNull($this->strategy->favourite([...$this->strongRow(), 'oddHome' => null]));
        $this->assertNull($this->strategy->favourite([...$this->strongRow(), 'oddAway' => 'n/a']));
        // Odd <= 1.0 não existe num mercado real e denuncia coluna suja.
        $this->assertNull($this->strategy->favourite([...$this->strongRow(), 'oddHome' => 1.00]));
    }

    public function test_the_lay_score_follows_the_favourite_orientation(): void
    {
        $home = $this->strongRow();
        $away = [...$home, 'oddHome' => 4.20, 'oddAway' => 1.80];

        $this->assertSame('1-0', $this->strategy->layScore($home, 1));
        $this->assertSame('2-0', $this->strategy->layScore($home, 2));
        $this->assertSame('0-1', $this->strategy->layScore($away, 1));
        $this->assertSame('0-2', $this->strategy->layScore($away, 2));
    }

    public function test_only_the_declared_legs_produce_a_score(): void
    {
        $this->assertNull($this->strategy->layScore($this->strongRow(), 3));
        $this->assertNull($this->strategy->layScore($this->strongRow(), 0));
        $this->assertNull($this->strategy->layScore($this->strongRow(), 1, 'ninguem'));
        $this->assertNull($this->strategy->layScoreForLeg($this->strongRow(), 'fav9'));
    }

    public function test_the_underdog_side_mirrors_the_favourite_orientation(): void
    {
        $home = $this->strongRow();
        $away = [...$home, 'oddHome' => 4.20, 'oddAway' => 1.80];

        // Mandante favorito: laydar o azarão significa laydar o visitante vencendo a zero.
        $this->assertSame('0-1', $this->strategy->layScore($home, 1, 'underdog'));
        $this->assertSame('0-2', $this->strategy->layScore($home, 2, 'underdog'));
        $this->assertSame('1-0', $this->strategy->layScore($away, 1, 'underdog'));
        $this->assertSame('2-0', $this->strategy->layScore($away, 2, 'underdog'));
    }

    public function test_an_away_favourite_flips_every_scoreline(): void
    {
        // Caso real que denunciou o rótulo antigo: Al Faisaly 4,20 x Al Ittihad 1,73.
        // O favorito é o visitante, então ele vencer a zero por 1 gol é 0-1, não 1-0.
        $row = [...$this->strongRow(), 'oddHome' => 4.20, 'oddAway' => 1.73];

        $this->assertSame('away', $this->strategy->favourite($row));
        $this->assertSame('0-1', $this->strategy->layScoreForLeg($row, 'fav1'));
        $this->assertSame('0-2', $this->strategy->layScoreForLeg($row, 'fav2'));
        $this->assertSame('1-0', $this->strategy->layScoreForLeg($row, 'dog1'));
        $this->assertSame('2-0', $this->strategy->layScoreForLeg($row, 'dog2'));
    }

    public function test_every_leg_label_names_its_side_and_avoids_the_colliding_form(): void
    {
        // O rótulo tem que nomear o sujeito ("favorito vence 1 a 0") e nunca usar a forma
        // NxN, que é a da coluna de placar em ordem casa-fora e colidia com ela.
        foreach (AgainstFavouriteCleanSheetStrategy::LEGS as $leg => $parts) {
            $side = $parts['side'] === 'favourite' ? 'favorito' : 'azarão';
            $this->assertStringContainsString($side, $parts['label'], $leg);
            $this->assertDoesNotMatchRegularExpression('/\d\s*[x-]\s*\d/u', $parts['label'], $leg);
        }
    }

    public function test_the_recommended_leg_is_the_one_the_filter_works_on(): void
    {
        $recommended = AgainstFavouriteCleanSheetStrategy::RECOMMENDED_LEG;

        // Recomendada é uma perna real e declarada.
        $this->assertArrayHasKey($recommended, AgainstFavouriteCleanSheetStrategy::LEGS);

        // O critério é o ganho do filtro, não a assertividade: fav1 tem o maior lift...
        $lift = AgainstFavouriteCleanSheetStrategy::BTTS_LIFT;
        $this->assertSame($recommended, array_keys($lift, max($lift))[0]);

        // ...e NÃO é a perna de maior acerto, que é dog2 (96,60%, sem ganho do filtro).
        $this->assertNotSame('dog2', $recommended);
        $this->assertLessThan(0, $lift['dog2']);

        // Todas as pernas têm lift declarado, e o sinal bate com BTTS_DISCRIMINATES.
        $this->assertSame(array_keys(AgainstFavouriteCleanSheetStrategy::LEGS), array_keys($lift));
        foreach (AgainstFavouriteCleanSheetStrategy::BTTS_DISCRIMINATES as $leg => $separates) {
            $this->assertSame($separates, $lift[$leg] >= 3.0, $leg);
        }
    }

    public function test_the_recommendation_reason_does_not_claim_higher_accuracy(): void
    {
        // A justificativa não pode virar "acerta mais": ao mesmo desconto sobre a odd justa
        // as quatro rendem igual, e prometer assertividade aqui seria enganoso.
        $reason = AgainstFavouriteCleanSheetStrategy::RECOMMENDED_REASON;
        $this->assertNotSame('', $reason);
        $this->assertStringContainsString('7,6', $reason);
        $this->assertStringContainsString('odd justa', $reason);
    }

    public function test_the_four_legs_resolve_by_key(): void
    {
        $row = $this->strongRow();
        // strongRow tem o mandante como favorito (1,80 contra 4,20).
        $expected = ['fav1' => '1-0', 'fav2' => '2-0', 'dog1' => '0-1', 'dog2' => '0-2'];

        foreach ($expected as $leg => $score) {
            $this->assertSame($score, $this->strategy->layScoreForLeg($row, $leg), $leg);
            $this->assertNotNull($this->strategy->resultForLeg($row, $leg), $leg);
        }
    }

    public function test_the_underdog_leg_labels_where_btts_stops_discriminating(): void
    {
        // Regra de produto medida: no azarão o filtro varia 2pp (1x0) e inverte no 2x0.
        $this->assertTrue(AgainstFavouriteCleanSheetStrategy::BTTS_DISCRIMINATES['fav1']);
        $this->assertFalse(AgainstFavouriteCleanSheetStrategy::BTTS_DISCRIMINATES['dog1']);
        $this->assertFalse(AgainstFavouriteCleanSheetStrategy::BTTS_DISCRIMINATES['dog2']);
        $this->assertSame(array_keys(AgainstFavouriteCleanSheetStrategy::LEGS), array_keys(AgainstFavouriteCleanSheetStrategy::BTTS_DISCRIMINATES));
    }

    public function test_each_side_has_its_own_portfolio(): void
    {
        // 2x1: nenhuma das quatro pernas bate.
        $row = $this->strongRow();
        $this->assertSame('green', $this->strategy->portfolioResult($row, 'favourite'));
        $this->assertSame('green', $this->strategy->portfolioResult($row, 'underdog'));

        // 0x1 derruba só a carteira do azarão (mandante é o favorito).
        $underdogWin = [...$row, 'homeGoals' => 0, 'awayGoals' => 1];
        $this->assertSame('green', $this->strategy->portfolioResult($underdogWin, 'favourite'));
        $this->assertSame('red', $this->strategy->portfolioResult($underdogWin, 'underdog'));
    }

    public function test_the_btts_probability_removes_the_bookmaker_margin(): void
    {
        // 1/1.55 = 0.645161…, 1/2.50 = 0.4; a normalização tira os 4,5% de margem.
        $expected = (1 / 1.55) / (1 / 1.55 + 1 / 2.50) * 100;
        $this->assertEqualsWithDelta($expected, $this->strategy->bttsProbability($this->strongRow()), 1e-12);
        // Sem normalizar seria 64,5%: a diferença desloca os cortes de perfil.
        $this->assertLessThan(1 / 1.55 * 100, $this->strategy->bttsProbability($this->strongRow()));
    }

    public function test_the_btts_probability_falls_back_to_the_model_prediction(): void
    {
        $row = [...$this->strongRow(), 'oddBttsYes' => null, 'oddBttsNo' => null, 'bttsProbability' => 64.0];
        $this->assertSame(64.0, $this->strategy->bttsProbability($row));
    }

    public function test_the_btts_probability_rejects_odds_at_or_below_one(): void
    {
        $row = [...$this->strongRow(), 'oddBttsYes' => 1.00];
        $this->assertNull($this->strategy->bttsProbability($row));
    }

    public function test_green_when_the_final_score_differs_and_red_when_it_matches(): void
    {
        $row = $this->strongRow();
        $this->assertSame('green', $this->strategy->result($row, 1));

        $this->assertSame('red', $this->strategy->result([...$row, 'homeGoals' => 1, 'awayGoals' => 0], 1));
        $this->assertSame('red', $this->strategy->result([...$row, 'homeGoals' => 2, 'awayGoals' => 0], 2));
    }

    public function test_a_two_nil_win_is_green_for_the_one_goal_leg(): void
    {
        // Cada perna cobre um placar exato: 2x0 não invalida o lay de 1x0.
        $row = [...$this->strongRow(), 'homeGoals' => 2, 'awayGoals' => 0];
        $this->assertSame('green', $this->strategy->result($row, 1));
        $this->assertSame('red', $this->strategy->result($row, 2));
    }

    public function test_a_missing_score_is_discarded_instead_of_counted_as_a_loss(): void
    {
        $this->assertNull($this->strategy->result([...$this->strongRow(), 'homeGoals' => null], 1));
        $this->assertNull($this->strategy->portfolioResult([...$this->strongRow(), 'awayGoals' => null]));
    }

    public function test_the_portfolio_is_red_when_any_leg_is_red(): void
    {
        $this->assertSame('green', $this->strategy->portfolioResult($this->strongRow()));
        $this->assertSame('red', $this->strategy->portfolioResult([...$this->strongRow(), 'homeGoals' => 1, 'awayGoals' => 0]));
        $this->assertSame('red', $this->strategy->portfolioResult([...$this->strongRow(), 'homeGoals' => 2, 'awayGoals' => 0]));
    }

    public function test_each_profile_requires_its_own_cutoff(): void
    {
        $row = $this->strongRow();
        $this->assertTrue($this->strategy->matchesProfile($row, 'baseline'));
        $this->assertTrue($this->strategy->matchesProfile($row, 'balanced'));
        $this->assertTrue($this->strategy->matchesProfile($row, 'strong'));

        // BTTS ~53%: passa em nada.
        $weakBtts = [...$row, 'oddBttsYes' => 1.90, 'oddBttsNo' => 1.90];
        $this->assertFalse($this->strategy->matchesProfile($weakBtts, 'baseline'));

        // BTTS ~58%: passa só no baseline.
        $midBtts = [...$row, 'oddBttsYes' => 1.65, 'oddBttsNo' => 2.30];
        $this->assertTrue($this->strategy->matchesProfile($midBtts, 'baseline'));
        $this->assertFalse($this->strategy->matchesProfile($midBtts, 'balanced'));

        // Favorito fraco derruba só o strong.
        $weakFavourite = [...$row, 'oddHome' => 2.60, 'oddAway' => 3.10];
        $this->assertTrue($this->strategy->matchesProfile($weakFavourite, 'balanced'));
        $this->assertFalse($this->strategy->matchesProfile($weakFavourite, 'strong'));
    }

    public function test_a_match_without_a_favourite_matches_no_profile(): void
    {
        $row = [...$this->strongRow(), 'oddHome' => 2.00, 'oddAway' => 2.00];
        foreach (array_keys(AgainstFavouriteCleanSheetStrategy::PROFILES) as $profile) {
            $this->assertFalse($this->strategy->matchesProfile($row, $profile), $profile);
        }
    }

    public function test_every_declared_profile_is_handled(): void
    {
        // Garante que nenhum perfil novo caia no ramo default do match sem ser notado.
        foreach (array_keys(AgainstFavouriteCleanSheetStrategy::PROFILES) as $profile) {
            $this->assertTrue($this->strategy->matchesProfile($this->strongRow(), $profile), $profile);
        }
        $this->assertFalse($this->strategy->matchesProfile($this->strongRow(), 'inexistente'));
    }

    public function test_the_fair_lay_odd_is_the_inverse_of_the_score_frequency(): void
    {
        // 6,62% de ocorrência medida no corte de BTTS >= 60% → odd justa ~15,1.
        $this->assertEqualsWithDelta(15.1057, AgainstFavouriteCleanSheetStrategy::fairLayOdd(6.62), 1e-4);
        $this->assertNull(AgainstFavouriteCleanSheetStrategy::fairLayOdd(0.0));
        $this->assertNull(AgainstFavouriteCleanSheetStrategy::fairLayOdd(null));
    }
}
