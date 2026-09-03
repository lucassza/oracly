<?php

namespace Tests\Feature\Punter;

use App\Filament\Pages\PunterLayList;
use App\Models\User;
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

    public function test_historico_lay_casa_fora_bate_com_o_backtest_no_perfil_balanced(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_casa_fora')
            ->call('setMode', 'history')
            ->call('setProfileFilter', 'balanced');

        // Medido via `php artisan punter:backtest-lay-casa-fora`: balanced fora (2221) + casa (506).
        $this->assertCount(2727, $component->get('historyRows'));
    }

    public function test_historico_lay_scores_bate_com_o_backtest(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(PunterLayList::class)
            ->call('setMarket', 'lay_scores')
            ->call('setMode', 'history');

        // Medido via `php artisan punter:backtest-lay-scores`: 17617 partidas válidas x 4 estratégias
        // (cada uma sempre produz um pick, então toda partida válida gera exatamente 4 linhas).
        $this->assertCount(17617 * 4, $component->get('historyRows'));
    }
}
