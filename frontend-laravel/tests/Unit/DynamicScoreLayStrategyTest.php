<?php

namespace Tests\Unit;

use App\Oracly\Services\DynamicScoreLayStrategy;
use App\Oracly\Support\MarketPoisson;
use PHPUnit\Framework\TestCase;

class DynamicScoreLayStrategyTest extends TestCase
{
    private DynamicScoreLayStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new DynamicScoreLayStrategy;
    }

    /** Favorito mandante a 1,80, over 2,5 equilibrado. */
    private function homeFavourite(?int $home = null, ?int $away = null): array
    {
        return [
            'oddHome' => 1.80, 'oddDraw' => 3.60, 'oddAway' => 4.50,
            'oddOver25' => 1.95, 'oddUnder25' => 1.90,
            'homeGoals' => $home, 'awayGoals' => $away,
        ];
    }

    /** O mesmo jogo com os lados trocados. */
    private function awayFavourite(?int $home = null, ?int $away = null): array
    {
        return [...$this->homeFavourite($home, $away), 'oddHome' => 4.50, 'oddAway' => 1.80];
    }

    public function test_orienta_pelo_favorito_e_devolve_placar_em_casa_fora(): void
    {
        $this->assertSame('home', $this->strategy->favouriteSide($this->homeFavourite()));
        $this->assertSame('away', $this->strategy->favouriteSide($this->awayFavourite()));

        $home = $this->strategy->expectedGoals($this->homeFavourite());
        $away = $this->strategy->expectedGoals($this->awayFavourite());
        $this->assertEqualsWithDelta($home['favourite'], $away['favourite'], 1e-6);
        $this->assertGreaterThan($home['underdog'], $home['favourite']);

        $ratios = ['M|mid|0-1' => 0.5];
        $this->assertSame('M|mid', $this->strategy->cell($this->homeFavourite(), $home));
        $this->assertSame('0-1', $this->strategy->candidates($this->homeFavourite(), $ratios)[0]['score']);
        $this->assertSame('1-0', $this->strategy->candidates($this->awayFavourite(), $ratios)[0]['score']);
    }

    public function test_chance_calibrada_e_poisson_vezes_razao(): void
    {
        $goals = $this->strategy->expectedGoals($this->homeFavourite());
        $model = MarketPoisson::poisson(0, $goals['favourite']) * MarketPoisson::poisson(1, $goals['underdog']) * 100;

        $candidate = $this->strategy->candidates($this->homeFavourite(), ['M|mid|0-1' => 0.8])[0];

        $this->assertEqualsWithDelta($model, $candidate['modelProbability'], 1e-9);
        $this->assertEqualsWithDelta($model * 0.8, $candidate['probability'], 1e-9);
        $this->assertEqualsWithDelta(100 / ($model * 0.8), $candidate['fairOdd'], 1e-9);
    }

    public function test_razao_exige_amostra_e_puxa_para_um(): void
    {
        $few = array_fill(0, DynamicScoreLayStrategy::MIN_CELL_ENTRIES - 1, $this->homeFavourite(2, 1));
        $this->assertSame([], $this->strategy->learnRatios($few));

        // Nunca sai 0x1: a razão cai bem abaixo de 1, mas não a zero por causa da pseudo-contagem.
        $ratios = $this->strategy->learnRatios(array_fill(0, 400, $this->homeFavourite(2, 1)));
        $this->assertGreaterThan(0, $ratios['M|mid|0-1']);
        $this->assertLessThan(0.5, $ratios['M|mid|0-1']);
        $this->assertGreaterThan(1, $ratios['M|mid|2-1']);

        // Placar laydado pelo lado do favorito: jogos com favorito visitante contam no mesmo grupo.
        $mirrored = $this->strategy->learnRatios(array_fill(0, 400, $this->awayFavourite(1, 2)));
        $this->assertEqualsWithDelta($ratios['M|mid|2-1'], $mirrored['M|mid|2-1'], 1e-9);
    }

    public function test_pick_respeita_pernas_razao_e_odd_justa(): void
    {
        $row = $this->homeFavourite();
        // Chances do modelo neste jogo: 0x0 7,08% · 0x1 6,92% · 0x2 3,38%.
        $ratios = ['M|mid|0-1' => 0.70, 'M|mid|0-2' => 0.75, 'M|mid|0-0' => 0.84, 'M|mid|2-1' => 1.10];

        // wide: uma perna só, a de menor razão.
        $this->assertSame(['0-1'], array_column($this->strategy->pick($row, 'wide', $ratios), 'score'));
        // balanced: 0x0 fica fora pela razão (0,84 > 0,80); 0x2 entra com justa 39,4.
        $this->assertSame(['0-1', '0-2'], array_column($this->strategy->pick($row, 'balanced', $ratios), 'score'));
        $this->assertSame(['0-1', '0-2'], array_column($this->strategy->pick($row, 'strong', $ratios), 'score'));
        // Com razão 0,60 o 0x2 é o mais superestimado, mas a justa passa de 40: sai pela odd.
        $this->assertSame(['0-1'], array_column($this->strategy->pick($row, 'balanced', [...$ratios, 'M|mid|0-2' => 0.60]), 'score'));
        $this->assertSame([], $this->strategy->pick($row, 'strong', ['M|mid|0-1' => 0.76]));
        $this->assertSame([], $this->strategy->pick($row, 'inexistente', $ratios));
    }

    public function test_resultado_por_perna(): void
    {
        $this->assertSame('red', $this->strategy->resultForLeg($this->homeFavourite(0, 1), '0-1'));
        $this->assertSame('green', $this->strategy->resultForLeg($this->homeFavourite(1, 0), '0-1'));
        $this->assertNull($this->strategy->resultForLeg($this->homeFavourite(), '0-1'));
    }
}
