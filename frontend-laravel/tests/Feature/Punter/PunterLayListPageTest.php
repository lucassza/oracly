<?php

namespace Tests\Feature\Punter;

use App\Filament\Pages\PunterLayList;
use App\Models\User;
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

    /**
     * Mesma ideia acima, mas para o mercado de placar exato — reaproveita a mesma seleção
     * padrão da página (3 das 4 estratégias Poisson: sem against1/0x1-1x0, a mais fraca —
     * ver test_selecao_de_ate_3_estrategias_de_placar_exato abaixo).
     */
    public function test_historico_lay_scores_bate_com_o_criterio(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $strategies = [
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
    /**
     * "Melhor da hora" numera (1, 2, 3...) mas não corta mais — a lista mostra todo mundo,
     * só o badge 👑/🔥/● é que só aparece pros 3 primeiros de cada hora.
     */
    public function test_lay_2x2_0x1_numera_o_rank_por_hora_sem_cortar_a_lista(): void
    {
        $this->actingAs(User::factory()->create());

        $unranked = app(\App\Oracly\Services\PunterLaySignalService::class)->forDate(\App\Oracly\Support\BrasiliaDate::today());

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_2x2_0x1')
            ->call('setMode', 'upcoming');
        $rows = $component->get('rows');

        // O rank não reduz o volume: mesma quantidade de linhas que o service devolve sem filtro.
        $this->assertCount(count($unranked), $rows);

        $byHour = [];
        foreach ($rows as $row) {
            $hour = \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');
            $byHour[$hour][] = $row['rank'];
        }

        foreach ($byHour as $hour => $ranks) {
            // A lista exibida ordena por horário exato do kickoff, não por rank — dentro de uma
            // hora Brasília os ranks aparecem espalhados. O que importa é o CONJUNTO: cada hora
            // tem exatamente uma vez cada rank de 1 até o total de jogos daquela hora.
            sort($ranks);
            $this->assertSame(range(1, count($ranks)), $ranks, "Ranks não formam 1..N na hora {$hour}.");
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

    /**
     * "Só os 3 melhores da hora" reduz o volume (mantém só rank 1/2/3) sem tirar ninguém
     * que já não fosse rank>3 — e continua batendo com o mesmo critério de segurança.
     */
    public function test_toggle_only_top_of_hour_filtra_pra_rank_1_2_3(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $off = Livewire::test(PunterLayList::class)->call('setMarket', 'lay_2x2_0x1')->call('setMode', 'history');
        $on = Livewire::test(PunterLayList::class)->call('setMarket', 'lay_2x2_0x1')->call('setMode', 'history')->call('toggleOnlyTopOfHour');

        $on->assertSet('onlyTopOfHour', true);

        $totalOff = count($off->get('filteredHistoryRows'));
        $totalOn = count($on->get('filteredHistoryRows'));
        $this->assertLessThan($totalOff, $totalOn, 'O filtro devia reduzir o volume.');

        foreach ($on->get('filteredHistoryRows') as $row) {
            $this->assertLessThanOrEqual(3, $row['rank'], 'Achei uma linha com rank > 3 com o filtro ligado.');
        }
    }

    /**
     * As 4 sub-estratégias de placar exato podem gerar até 4 apostas na mesma partida — nunca
     * mais de uma delas falha por jogo (os 4 placares são sempre distintos), então a assertividade
     * CONJUNTA (nenhuma das apostas escolhidas erra) é sensível a QUAIS 3 entram na combinação.
     * Testamos deixar o usuário escolher e também escolher dinamicamente a "melhor" por partida
     * (maior probabilidade bruta, ou por percentil normalizado por estratégia) — as duas formas
     * dinâmicas deram pior resultado (83-85%) que simplesmente travar SEMPRE a mesma combinação
     * fixa sem against1/0x1-1x0 (88,3%), então não há seleção nenhuma: a página já indica o
     * "melhor 3" fixo, sem configuração.
     */
    public function test_placar_exato_usa_sempre_a_mesma_combinacao_fixa_de_3_estrategias(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_scores')
            ->call('setMode', 'history');

        $bets = array_unique(array_map(fn (array $r): string => (string) $r['bet'], $component->get('historyRows')));
        sort($bets);
        $this->assertSame(['LAY 0x2', 'LAY 0x3', 'LAY 1x3', 'LAY 2x0', 'LAY 3x0', 'LAY 3x1'], $bets, 'A combinação fixa deve cobrir só against2/against31/against3 — nunca 0x1/1x0.');

        // A assertividade conjunta bate com o critério puro sobre exatamente essas 3 estratégias.
        $strategies = [
            new AgainstTwoGoalsStrategy,
            new AgainstThreeOneStrategy,
            new AgainstThreeGoalsStrategy,
        ];
        $byFixture = [];
        foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
            if ($row['finalScore'] === null || $row['homeGoalsAverage'] === null || $row['awayGoalsAverage'] === null) {
                continue;
            }
            foreach ($strategies as $strategy) {
                $choice = $strategy->choice($row);
                if ($choice === null) {
                    continue;
                }
                $byFixture[$row['matchKey']][] = $choice['score'] !== $row['finalScore'];
            }
        }
        $expectedWins = 0;
        foreach ($byFixture as $hits) {
            if (! in_array(false, $hits, true)) {
                $expectedWins++;
            }
        }
        $expectedHitRate = count($byFixture) > 0 ? round($expectedWins / count($byFixture) * 100, 4) : null;

        $joint = $component->get('jointAccuracyStats');
        $this->assertEqualsWithDelta(count($byFixture), $joint['entries'], 20);
        $this->assertEqualsWithDelta($expectedHitRate, $joint['hitRate'], 1.0, 'Assertividade conjunta divergiu do critério puro pra essa combinação fixa.');
    }
}
