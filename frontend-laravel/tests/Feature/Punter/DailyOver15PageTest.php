<?php

namespace Tests\Feature\Punter;

use App\Filament\Pages\DailyOver15;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Integração real com o schema punter (RefreshDatabase só cobre o sqlite padrão).
 */
class DailyOver15PageTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_carrega_via_http_para_usuario_autenticado(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/daily-over15')
            ->assertOk()
            ->assertSee('Over 1.5 FT com valor');
    }

    /** @return list<list<string>> */
    public static function modes(): array
    {
        return [['upcoming'], ['history']];
    }

    #[DataProvider('modes')]
    public function test_cada_modo_e_perfil_renderiza_sem_erro(string $mode): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(DailyOver15::class)->call('setMode', $mode)->assertSet('mode', $mode);
        foreach (array_keys(DailyOver15::PROFILES) as $profile) {
            $component->call('setProfile', $profile)->assertSuccessful();
        }
    }

    public function test_historico_mostra_retorno_por_perfil(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(DailyOver15::class)->call('setMode', 'history')->call('setProfile', 'all');
        $stats = $component->instance()->profileStats;

        $this->assertGreaterThan(1000, $stats['all']['all']['entries'], 'Pré-condição: match_history com odd de over 1,5.');
        $this->assertLessThanOrEqual($stats['all']['all']['entries'], $stats['balanced']['all']['entries']);
        $this->assertLessThanOrEqual($stats['balanced']['all']['entries'], $stats['strong']['all']['entries']);
        $this->assertNotEmpty($component->instance()->filteredRows);
    }

    public function test_veredito_compara_a_odd_da_exchange_com_a_minima(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test(DailyOver15::class)->instance();

        $row = ['rowId' => 'abc', 'probability' => 0.80, 'minEntryOdd' => 1.2674];

        $page->offeredOdds = ['abc' => '1,30'];
        $this->assertSame('enter', $page->verdictFor($row)['verdict']);

        $page->offeredOdds = ['abc' => '1.25'];
        $this->assertSame('skip', $page->verdictFor($row)['verdict']);
        $this->assertLessThan(0, $page->verdictFor($row)['edge']);

        $page->offeredOdds = ['abc' => ''];
        $this->assertNull($page->verdictFor($row));
    }
}
