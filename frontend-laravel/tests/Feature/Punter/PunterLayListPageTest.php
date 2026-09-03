<?php

namespace Tests\Feature\Punter;

use App\Filament\Pages\PunterLayList;
use App\Models\User;
use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeGoalsStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\PunterLayCasaForaStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\OraclyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Integração real com o schema punter (mesmo Postgres do SokkerPRO — RefreshDatabase só
 * cobre o sqlite padrão). Confere que os 4 combos mercado × modo carregam sem erro.
 */
class PunterLayListPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_carrega_via_http_para_usuario_autenticado(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/punter-lay-list')
            ->assertOk()
            ->assertSee('Lista LAY');
    }

    /** @return list<list<string>> */
    public static function marketModeCombos(): array
    {
        return [
            ['lay_2x2_0x1', 'upcoming'],
            ['lay_2x2_0x1', 'history'],
            ['lay_casa_fora', 'upcoming'],
            ['lay_casa_fora', 'history'],
            ['lay_scores', 'upcoming'],
            ['lay_scores', 'history'],
        ];
    }

    #[DataProvider('marketModeCombos')]
    public function test_cada_combinacao_de_mercado_e_modo_renderiza_sem_erro(string $market, string $mode): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', $market)
            ->call('setMode', $mode)
            ->assertSet('market', $market)
            ->assertSet('mode', $mode);

        $component->assertSuccessful();
    }

    /**
     * Recalcula a mesma contagem (dinamicamente, não um número fixo) para confirmar que a página
     * bate com o que o próprio critério de PunterLayCasaForaStrategy produziria direto sobre
     * punter.match_history. Tolerância pequena de propósito: a base sincroniza via cron entre a
     * leitura manual abaixo e a leitura da página, então um punhado de linhas pode mudar no meio
     * do teste — uma divergência grande (não ±5) ainda pega regressão de verdade.
     */
    public function test_historico_lay_casa_fora_bate_com_o_criterio_no_perfil_balanced(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $strategy = new PunterLayCasaForaStrategy;
        $expected = 0;
        foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
            $choice = $strategy->choice($row);
            if ($choice === null || ! $strategy->matchesProfile($row, 'balanced')) {
                continue;
            }
            if ($strategy->result($row, $choice['side']) !== null) {
                $expected++;
            }
        }
        $this->assertGreaterThan(0, $expected, 'Pré-condição: precisa haver histórico apurado com odds válidas.');

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_casa_fora')
            ->call('setMode', 'history')
            ->call('setProfileFilter', 'balanced');

        $actual = count($component->get('historyRows'));
        $this->assertEqualsWithDelta($expected, $actual, 5, "Esperado ~{$expected}, veio {$actual} — diferença grande demais pra ser só sync do cron.");
    }

    /** Mesma ideia acima, mas para o mercado de placar exato (as 4 estratégias Poisson). */
    public function test_historico_lay_scores_bate_com_o_criterio(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $strategies = [
            new AgainstOneGoalStrategy,
            new AgainstTwoGoalsStrategy,
            new AgainstThreeOneStrategy,
            new AgainstThreeGoalsStrategy,
        ];
        $expected = 0;
        foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
            if ($row['finalScore'] === null || $row['homeGoalsAverage'] === null || $row['awayGoalsAverage'] === null) {
                continue;
            }
            foreach ($strategies as $strategy) {
                if ($strategy->choice($row) !== null) {
                    $expected++;
                }
            }
        }
        $this->assertGreaterThan(0, $expected, 'Pré-condição: precisa haver histórico apurado com médias de gols.');

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_scores')
            ->call('setMode', 'history');

        $actual = count($component->get('historyRows'));
        $this->assertEqualsWithDelta($expected, $actual, 20, "Esperado ~{$expected}, veio {$actual} — diferença grande demais pra ser só sync do cron.");
    }

    /**
     * "Melhor da hora" — mesmo padrão da DailyLayList (SokkerPRO): no máximo 3 picks por hora
     * Brasília, com rank 1/2/3. Só existe para lay_2x2_0x1 (único mercado Punter com kickoff
     * de verdade — os outros dois só têm data, sem hora).
     */
    public function test_lay_2x2_0x1_agrupa_no_maximo_3_picks_por_hora(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_2x2_0x1')
            ->call('setMode', 'upcoming');

        $byHour = [];
        foreach ($component->get('rows') as $row) {
            $hour = \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');
            $byHour[$hour][] = $row['rank'];
        }

        foreach ($byHour as $hour => $ranks) {
            $this->assertLessThanOrEqual(3, count($ranks), "Mais de 3 picks na hora {$hour}.");
            $this->assertSame(range(1, count($ranks)), $ranks, "Ranks fora de ordem na hora {$hour}.");
        }
    }

    /**
     * O toggle HT/FT muda o critério de apuração (mesma escolha de lado/placar, resultado
     * diferente) — as duas leituras não podem dar o mesmo array de hits.
     */
    public function test_toggle_ht_ft_muda_a_apuracao_do_historico_lay_casa_fora(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $ft = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_casa_fora')->call('setMode', 'history')
            ->call('setProfileFilter', 'strong')->call('setPeriodFilter', 'ft');
        $ht = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_casa_fora')->call('setMode', 'history')
            ->call('setProfileFilter', 'strong')->call('setPeriodFilter', 'ht');

        $ft->assertSet('periodFilter', 'ft');
        $ht->assertSet('periodFilter', 'ht');

        $hitsFt = array_map(fn (array $r): bool => $r['hit'], $ft->get('historyRows'));
        $hitsHt = array_map(fn (array $r): bool => $r['hit'], $ht->get('historyRows'));

        $this->assertNotEmpty($hitsFt);
        $this->assertNotEmpty($hitsHt);
        $this->assertNotSame($hitsFt, $hitsHt, 'HT e FT deram exatamente os mesmos acertos — o toggle não teria efeito nenhum.');
    }
}
