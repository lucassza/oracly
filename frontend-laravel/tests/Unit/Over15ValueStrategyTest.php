<?php

namespace Tests\Unit;

use App\Oracly\Services\Over15ValueStrategy;
use PHPUnit\Framework\TestCase;

class Over15ValueStrategyTest extends TestCase
{
    private Over15ValueStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new Over15ValueStrategy;
    }

    public function test_tira_a_margem_com_os_dois_lados_do_mercado(): void
    {
        // 1/1,25 = 0,80 e 1/4,00 = 0,25: book 1,05, sem margem 0,80/1,05.
        $probability = $this->strategy->fairProbability(['oddOver15' => 1.25, 'oddUnder15' => 4.00]);

        $this->assertEqualsWithDelta(0.80 / 1.05, $probability, 1e-9);
    }

    public function test_so_com_o_over_divide_pelo_overround_da_faixa(): void
    {
        $this->assertEqualsWithDelta((1 / 1.30) / 1.0690, $this->strategy->fairProbability(['oddOver15' => 1.30]), 1e-9);
        $this->assertEqualsWithDelta((1 / 1.10) / 1.0772, $this->strategy->fairProbability(['oddOver15' => 1.10]), 1e-9);
        $this->assertNull($this->strategy->fairProbability(['oddOver15' => 0]));
    }

    public function test_odd_minima_de_entrada_zera_o_retorno_esperado_com_comissao(): void
    {
        $min = Over15ValueStrategy::minEntryOdd(0.80);

        $this->assertEqualsWithDelta(1 + 0.20 / (0.80 * 0.935), $min, 1e-9);
        $this->assertEqualsWithDelta(0.0, Over15ValueStrategy::expectedValue(0.80, $min), 1e-9);
        $this->assertGreaterThan(0, Over15ValueStrategy::expectedValue(0.80, $min + 0.01));
    }

    public function test_resultado_pelo_placar_final(): void
    {
        $this->assertSame('green', $this->strategy->result(['homeGoals' => 1, 'awayGoals' => 1]));
        $this->assertSame('red', $this->strategy->result(['homeGoals' => 1, 'awayGoals' => 0]));
        $this->assertNull($this->strategy->result(['homeGoals' => null, 'awayGoals' => 0]));
    }

    public function test_edge_da_liga_exige_amostra_e_encolhe_na_probabilidade(): void
    {
        // Mercado precifica 50% (1,90 / 1,90 sem margem) e a liga acerta 60%.
        $history = $this->league('Liga A', 200, 120);
        $edges = $this->strategy->leagueEdges($history);

        $this->assertEqualsWithDelta(0.10, $edges['Liga A']['edge'], 1e-9);
        $this->assertEqualsWithDelta(0.10 * 200 / 500, $edges['Liga A']['shrunkEdge'], 1e-9);
        $this->assertEqualsWithDelta(0.5 + 0.04, $this->strategy->probability(['oddOver15' => 1.90, 'oddUnder15' => 1.90], $edges['Liga A']), 1e-9);

        $small = $this->strategy->leagueEdges($this->league('Liga B', 99, 99));
        $this->assertNull($small['Liga B']['edge']);
    }

    public function test_edge_acumulado_nao_ve_o_proprio_jogo_nem_o_futuro(): void
    {
        $history = $this->league('Liga A', 101, 101);
        $edges = $this->strategy->rollingLeagueEdges($history);

        $this->assertNull($edges[99]['edge'], 'Antes do centésimo jogo a liga não tem edge.');
        $this->assertSame(100, $edges[100]['entries']);
        $this->assertEqualsWithDelta(0.5, $edges[100]['edge'], 1e-9);
    }

    public function test_perfis_cortam_por_odd_justa_e_edge_da_liga(): void
    {
        $row = ['oddOver15' => 1.30, 'oddUnder15' => 3.60];
        $edge = fn (float $value): array => ['entries' => 500, 'edge' => $value, 'shrunkEdge' => $value / 2];

        $this->assertTrue($this->strategy->matchesProfile($row, 'all', null));
        $this->assertFalse($this->strategy->matchesProfile($row, 'balanced', null));
        $this->assertTrue($this->strategy->matchesProfile($row, 'balanced', $edge(0.02)));
        $this->assertFalse($this->strategy->matchesProfile($row, 'strong', $edge(0.03)));
        $this->assertTrue($this->strategy->matchesProfile($row, 'strong', $edge(0.04)));

        $longOdd = ['oddOver15' => 1.60, 'oddUnder15' => 2.40];
        $this->assertFalse($this->strategy->matchesProfile($longOdd, 'all', null));
    }

    public function test_retorno_apurado_desconta_comissao_so_do_lucro(): void
    {
        $this->assertEqualsWithDelta(0.30 * 0.935, $this->strategy->settledReturn(['homeGoals' => 2, 'awayGoals' => 0], 1.30), 1e-9);
        $this->assertSame(-1.0, $this->strategy->settledReturn(['homeGoals' => 0, 'awayGoals' => 0], 1.30));
    }

    /** @return list<array<string, mixed>> */
    private function league(string $name, int $games, int $greens): array
    {
        $rows = [];
        for ($i = 0; $i < $games; $i++) {
            $rows[] = [
                'competition' => $name, 'oddOver15' => 1.90, 'oddUnder15' => 1.90,
                'homeGoals' => $i < $greens ? 2 : 0, 'awayGoals' => 0,
            ];
        }

        return $rows;
    }
}
