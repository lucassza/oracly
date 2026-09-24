<?php

namespace Tests\Feature;

use App\Filament\Pages\LayPlacarDinamico;
use App\Models\LayOddQuote;
use App\Models\User;
use App\Oracly\Services\PunterMatchPickService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Base simulada: 400 jogos de 2025 no mesmo grupo (favorito 1,80, jogo médio) em que a zebra
 * vence por pouco bem menos do que o Poisson prevê, e 20 jogos na validação. A escolha fica
 * previsível: 0x1 no perfil de uma perna, 0x1 + 0x2 nos de duas.
 */
class LayPlacarDinamicoPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        // Mesmo motivo de LayPlacarUnicoPageTest: sem store array o cache de arquivo real vaza.
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        $this->instance(PunterMatchPickService::class, new class
        {
            public function history(int $limit = 20000): array
            {
                $rows = [];
                // O modelo prevê 6,92% de 0x1 e 3,38% de 0x2 neste jogo. Com 18 e 10 ocorrências (a
                // lista do dia aprende nos 420 jogos) as razões ficam ~0,68 e ~0,78, com odd justa
                // ~21 e ~38 — dentro dos limites dos perfis.
                for ($i = 0; $i < 400; $i++) {
                    [$gHome, $gAway] = $i < 18 ? [0, 1] : ($i < 28 ? [0, 2] : [2, 1]);
                    $rows[] = self::row("Treino{$i}", '2025-01-01', 1.80, 4.50, $gHome, $gAway);
                }
                for ($i = 0; $i < 19; $i++) {
                    $rows[] = self::row("Valida{$i}", '2026-09-01', 1.80, 4.50, 2, 1);
                }
                // Validação com favorito visitante e a zebra (mandante) vencendo 1x0: red no lay 1x0.
                $rows[] = self::row('ZebraVence', '2026-09-02', 4.50, 1.80, 1, 0);

                return $rows;
            }

            public function upcoming(string $date): array
            {
                return [
                    [...self::row('Mandante', $date, 1.80, 4.50, null, null), 'kickoffAt' => $date.' 19:00:00'],
                    [...self::row('Azarao', $date, 4.50, 1.80, null, null), 'kickoffAt' => $date.' 21:00:00'],
                ];
            }

            public function finalScores(array $matches): array
            {
                return ['2026-09-10|Mandante|Visitante' => ['homeGoals' => 0, 'awayGoals' => 1, 'oddHome' => 1.80, 'oddAway' => 4.50]];
            }

            private static function row(string $home, string $date, float $oHome, float $oAway, ?int $gHome, ?int $gAway): array
            {
                return [
                    'matchKey' => $date.'|'.$home.'|Visitante', 'matchDate' => $date,
                    'homeTeam' => $home, 'awayTeam' => 'Visitante', 'competition' => 'Liga',
                    'oddHome' => $oHome, 'oddDraw' => 3.60, 'oddAway' => $oAway,
                    'oddOver25' => 1.95, 'oddUnder25' => 1.90,
                    'homeGoals' => $gHome, 'awayGoals' => $gAway,
                ];
            }
        });
    }

    public function test_pagina_carrega_e_fica_no_topo_da_operacao_diaria(): void
    {
        $this->get('/admin/lay-placar-dinamico')->assertOk()->assertSee('LAY de placar exato dinâmico')->assertSee('Mandante');
        Livewire::test(LayPlacarDinamico::class)->assertSet('profile', 'wide');
        $this->assertLessThan(\App\Filament\Pages\LayGoleada::getNavigationSort(), LayPlacarDinamico::getNavigationSort());
    }

    public function test_placar_escolhido_segue_o_lado_do_favorito(): void
    {
        $rows = Livewire::test(LayPlacarDinamico::class)->call('setProfile', 'balanced')->instance()->filteredRows;
        $legs = array_map(fn (array $row): array => array_column($row['legs'], 'score'), array_column($rows, null, 'homeTeam'));

        // A zebra vencendo por pouco: 0x1 com o mandante favorito, 1x0 com o visitante favorito.
        $this->assertContains('0-1', $legs['Mandante']);
        $this->assertContains('1-0', $legs['Azarao']);
        $this->assertCount(2, $legs['Mandante']);
    }

    public function test_historico_usa_so_a_validacao_e_apura_por_perna(): void
    {
        $page = Livewire::test(LayPlacarDinamico::class)->call('setMode', 'history')->call('setProfile', 'wide')->assertSuccessful()->instance();

        $rows = array_column($page->filteredRows, null, 'homeTeam');
        $this->assertArrayNotHasKey('Treino0', $rows);
        $this->assertSame('ZebraVence', $page->filteredRows[0]['homeTeam']);

        $stats = $page->profileStats['wide'];
        $this->assertSame(20, $stats['matches']);
        $this->assertSame(1, $stats['matches'] - (int) round($stats['matchHitRate'] / 100 * $stats['matches']));
    }

    public function test_registra_duas_pernas_do_mesmo_jogo_e_apura_cada_uma(): void
    {
        $page = Livewire::test(LayPlacarDinamico::class)->call('setProfile', 'balanced');
        $row = collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Mandante');
        [$first, $second] = $row['legs'];

        $page->set("quoteInputs.{$first['hash']}", '15,5')->call('saveQuote', $first['hash'])
            ->set("quoteInputs.{$second['hash']}", '30')->call('saveQuote', $second['hash']);

        $this->assertSame(2, LayOddQuote::count());
        $this->assertEqualsCanonicalizing(
            ['lay_dinamico:'.$first['score'], 'lay_dinamico:'.$second['score']],
            LayOddQuote::pluck('strategy')->all(),
        );

        LayOddQuote::query()->update(['match_date' => '2026-09-10', 'match_key' => '2026-09-10|Mandante|Visitante']);
        $quotes = array_column(Livewire::test(LayPlacarDinamico::class)->instance()->quotes, 'result', 'score');

        // Terminou 0x1: red só na perna do 0x1.
        $this->assertSame('red', $quotes['0-1']);
        $this->assertSame('green', $quotes[$first['score'] === '0-1' ? $second['score'] : $first['score']]);

        $page = Livewire::test(LayPlacarDinamico::class);
        $page->call('deleteQuote', LayOddQuote::first()->id);
        $this->assertSame(1, LayOddQuote::count());
    }
}
