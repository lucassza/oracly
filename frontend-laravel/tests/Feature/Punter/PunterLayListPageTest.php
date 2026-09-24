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

    /**
     * panel_fixtures não tem coluna de horário, mas match_label traz o horário embutido
     * (ver PunterMatchPickService::parseKickoffAt) — a lista diária de lay_casa_fora e
     * lay_scores agora mostra a hora real de cada jogo, igual já acontecia pro lay_2x2_0x1.
     */
    public function test_lista_diaria_mostra_horario_real_para_lay_casa_fora_e_lay_scores(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['lay_casa_fora', 'lay_scores'] as $market) {
            $component = Livewire::test(PunterLayList::class)
                ->call('setMarket', $market)
                ->call('setMode', 'upcoming');

            $rows = $component->get('rows');
            if (count($rows) === 0) {
                continue;
            }
            foreach ($rows as $row) {
                $this->assertArrayHasKey('kickoffAt', $row, "Linha de {$market} sem kickoffAt.");
            }
        }
    }

    /**
     * As tabs de hora (antes só em lay_2x2_0x1) agora existem pros 3 mercados na lista
     * diária, já que os 3 têm kickoffAt real. Filtrar por uma hora tem que sobrar só jogos
     * daquela hora Brasília — nunca mais linhas do que sem filtro.
     */
    public function test_tabs_de_hora_filtram_a_lista_diaria_nos_3_mercados(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['lay_2x2_0x1', 'lay_casa_fora', 'lay_scores'] as $market) {
            $component = Livewire::test(PunterLayList::class)
                ->call('setMarket', $market)
                ->call('setMode', 'upcoming');

            $hours = $component->get('hours');
            if (count($hours) === 0) {
                continue;
            }

            $totalUnfiltered = count($component->get('filteredRows'));
            $filtered = $component->call('setHourFilter', $hours[0]);
            $rows = $filtered->get('filteredRows');

            $this->assertNotEmpty($rows, "Filtrar {$market} pela hora {$hours[0]} não deveria zerar a lista.");
            $this->assertLessThanOrEqual($totalUnfiltered, count($rows));
            foreach ($rows as $row) {
                $this->assertSame($hours[0], \App\Oracly\Support\BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']), "Linha fora da hora {$hours[0]} em {$market}.");
            }
        }
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
        foreach (app(PunterMatchPickService::class)->history(60000) as $row) {
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

        $actual = count($component->instance()->historyRows);
        $this->assertEqualsWithDelta($expected, $actual, 5, "Esperado ~{$expected}, veio {$actual} — diferença grande demais pra ser só sync do cron.");
    }

    /**
     * LAY 0x1/1x0 é sempre a base (1 linha por partida). As outras 3 sub-estratégias só
     * entram como perna extra quando a probabilidade daquele placar está no top 5% mais
     * seguro da própria estratégia — mesmos cortes de PunterLayList::EXTRA_LEG_PROBABILITY_CUTOFFS,
     * medidos via punter:backtest sobre o histórico (ver commit que introduziu isso).
     */
    private const EXTRA_LEG_PROBABILITY_CUTOFFS = [
        'against2' => 0.001824,
        'against31' => 0.006079,
        'against3' => 0.001316,
    ];

    /**
     * Recalcula (base against1 sempre + extras qualificadas pelo corte) e confere que bate
     * com o que a página mostra no histórico.
     */
    public function test_historico_lay_scores_bate_com_o_criterio(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $against1 = new AgainstOneGoalStrategy;
        $extras = [
            'against2' => new AgainstTwoGoalsStrategy,
            'against31' => new AgainstThreeOneStrategy,
            'against3' => new AgainstThreeGoalsStrategy,
        ];
        $expected = 0;
        foreach (app(PunterMatchPickService::class)->history(60000) as $row) {
            if ($row['finalScore'] === null || $row['homeGoalsAverage'] === null || $row['awayGoalsAverage'] === null) {
                continue;
            }
            if ($against1->choice($row) !== null) {
                $expected++;
            }
            foreach ($extras as $key => $strategy) {
                $choice = $strategy->choice($row);
                if ($choice !== null && $choice['probability'] <= self::EXTRA_LEG_PROBABILITY_CUTOFFS[$key]) {
                    $expected++;
                }
            }
        }
        $this->assertGreaterThan(0, $expected, 'Pré-condição: precisa haver histórico apurado com médias de gols.');

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_scores')
            ->call('setMode', 'history');

        $actual = count($component->instance()->historyRows);
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

        $hitsFt = array_map(fn (array $r): bool => $r['hit'], $ft->instance()->historyRows);
        $hitsHt = array_map(fn (array $r): bool => $r['hit'], $ht->instance()->historyRows);

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
     * LAY 0x1/1x0 nunca some da lista (é a base, sem corte) — toda partida tem pelo menos essa
     * aposta. As outras 3 só aparecem, marcadas como perna "extra", quando muito seguras pra
     * aquela partida específica (top 5% da própria estratégia). Testamos travar sempre as
     * mesmas 3 (88,3%) e escolher dinamicamente a de maior risco por partida (83-85%) — as
     * duas formas ficaram piores que ancorar em against1 e só somar extra quando muito
     * confiante (90,8% medido).
     */
    public function test_placar_exato_sempre_tem_a_base_0x1_e_so_soma_extra_quando_muito_confiante(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_scores')
            ->call('setMode', 'history');
        $rows = $component->instance()->historyRows;

        $byFixtureBets = [];
        foreach ($rows as $row) {
            $byFixtureBets[$row['fixtureKey']][] = $row;
        }
        $this->assertNotEmpty($byFixtureBets);

        foreach ($byFixtureBets as $fixtureKey => $bets) {
            $baseBets = array_filter($bets, fn (array $b): bool => ! $b['isExtra']);
            $this->assertCount(1, $baseBets, "Partida {$fixtureKey} deveria ter exatamente 1 aposta base (0x1/1x0).");
            foreach ($baseBets as $base) {
                $this->assertContains($base['bet'], ['LAY 0x1', 'LAY 1x0'], "Base inesperada na partida {$fixtureKey}: {$base['bet']}.");
            }
            foreach (array_filter($bets, fn (array $b): bool => $b['isExtra']) as $extra) {
                $this->assertLessThanOrEqual(self::EXTRA_LEG_PROBABILITY_CUTOFFS[match (true) {
                    str_contains((string) $extra['bet'], '0x2') || str_contains((string) $extra['bet'], '2x0') => 'against2',
                    str_contains((string) $extra['bet'], '3x1') || str_contains((string) $extra['bet'], '1x3') => 'against31',
                    default => 'against3',
                }], $extra['probability'], "Perna extra na partida {$fixtureKey} ({$extra['bet']}) passou do corte de confiança.");
            }
        }

        // A assertividade conjunta bate com o critério puro (base sempre + extras qualificadas).
        $against1 = new AgainstOneGoalStrategy;
        $extras = [
            'against2' => new AgainstTwoGoalsStrategy,
            'against31' => new AgainstThreeOneStrategy,
            'against3' => new AgainstThreeGoalsStrategy,
        ];
        $byFixture = [];
        foreach (app(PunterMatchPickService::class)->history(60000) as $row) {
            if ($row['finalScore'] === null || $row['homeGoalsAverage'] === null || $row['awayGoalsAverage'] === null) {
                continue;
            }
            $baseChoice = $against1->choice($row);
            if ($baseChoice === null) {
                continue;
            }
            $byFixture[$row['matchKey']][] = $baseChoice['score'] !== $row['finalScore'];
            foreach ($extras as $key => $strategy) {
                $choice = $strategy->choice($row);
                if ($choice !== null && $choice['probability'] <= self::EXTRA_LEG_PROBABILITY_CUTOFFS[$key]) {
                    $byFixture[$row['matchKey']][] = $choice['score'] !== $row['finalScore'];
                }
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
        $this->assertEqualsWithDelta($expectedHitRate, $joint['hitRate'], 1.0, 'Assertividade conjunta divergiu do critério puro (base + extras qualificadas).');
    }
}
