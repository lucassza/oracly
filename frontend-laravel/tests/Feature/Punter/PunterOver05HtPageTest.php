<?php

namespace Tests\Feature\Punter;

use App\Filament\Pages\PunterOver05Ht;
use App\Models\User;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Services\PunterOver05HtStrategy;
use App\Oracly\Support\OraclyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Integração real com o schema punter (mesmo Postgres do SokkerPRO — RefreshDatabase só
 * cobre o sqlite padrão).
 */
class PunterOver05HtPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_carrega_via_http_para_usuario_autenticado(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/punter-over05-ht')
            ->assertOk()
            ->assertSee('Over 0.5 HT');
    }

    /** @return list<list<string>> */
    public static function modes(): array
    {
        return [['upcoming'], ['history']];
    }

    #[DataProvider('modes')]
    public function test_cada_modo_renderiza_sem_erro(string $mode): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(PunterOver05Ht::class)
            ->call('setMode', $mode)
            ->assertSet('mode', $mode)
            ->assertSuccessful();
    }

    /**
     * A lista do dia usa a recomendação do Punter (única fonte disponível pra jogo
     * futuro) — confere que só entram fixtures com a flag marcada.
     */
    public function test_lista_diaria_so_mostra_jogos_recomendados_pelo_punter(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(PunterOver05Ht::class)->call('setMode', 'upcoming');
        $rows = $component->get('rows');

        $strategy = new PunterOver05HtStrategy;
        foreach (app(PunterMatchPickService::class)->upcoming($component->get('date')) as $upcoming) {
            $shouldAppear = $strategy->punterRecommends($upcoming);
            $appears = collect($rows)->contains('matchKey', $upcoming['matchKey']);
            if ($shouldAppear) {
                $this->assertTrue($appears, "Jogo recomendado pelo Punter não apareceu: {$upcoming['matchKey']}");
            }
        }
    }

    /**
     * Recalcula (dinamicamente) a mesma contagem do perfil 'strong' pra confirmar que a
     * página bate com o critério de odd de PunterOver05HtStrategy sobre match_history.
     */
    public function test_historico_bate_com_o_criterio_no_perfil_strong(): void
    {
        $this->actingAs(User::factory()->create());
        OraclyCache::forgetPrefix();

        $strategy = new PunterOver05HtStrategy;
        $expected = 0;
        foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
            if ($strategy->matchesOddProfile($row, 'strong') && $strategy->result($row) !== null) {
                $expected++;
            }
        }
        $this->assertGreaterThan(0, $expected, 'Pré-condição: precisa haver histórico apurado com odd de HT.');

        $component = Livewire::test(PunterOver05Ht::class)
            ->call('setMode', 'history')
            ->call('setProfileFilter', 'strong');

        $actual = count($component->get('historyRows'));
        $this->assertEqualsWithDelta($expected, $actual, 5, "Esperado ~{$expected}, veio {$actual}.");
    }
}
