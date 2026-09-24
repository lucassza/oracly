<?php

namespace Tests\Feature;

use App\Filament\Pages\Over05Ht;
use App\Models\User;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\FirstHalfGoalsHistoryService;
use App\Oracly\Services\FirstHalfGoalsStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Over05HtPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // reload() também consulta favoritos; sem este duplo a exceção do Postgres cai no
        // catch da página e zera a lista inteira, mascarando o que este teste mede.
        $this->instance(FavoritesService::class, new class
        {
            public function get(): array
            {
                return ['leagues' => [], 'countries' => []];
            }
        });
        // FirstHalfGoalsHistoryService é final; a página só chama ->history(), então
        // um duplo por duck typing basta e evita tocar o Postgres.
        $this->instance(FirstHalfGoalsHistoryService::class, new class
        {
            public function history(int $limit = 20000, bool $cached = true): array
            {
                return [
                    // Passa em strong (e portanto em balanced e baseline).
                    self::row('Forte', 78, 0.9, 0.8, 55, false, true),
                    // Passa em balanced, reprova em strong (média de 1º tempo baixa).
                    self::row('Média', 72, 0.7, 0.7, 46, false, true),
                    // Só baseline.
                    self::row('Fraca', 71, 0.3, 0.3, 20, false, false),
                    // Passa em strong mas vem da trilha backfill.
                    self::row('Backfill', 79, 1.0, 0.9, 60, true, true),
                ];
            }

            /** @return array<string, mixed> */
            private static function row(string $home, float $pred, float $fhHome, float $fhAway, float $over15Ht, bool $backfilled, bool $hit): array
            {
                return [
                    'providerMatchId' => $home,
                    'kickoffAt' => '2026-08-20T18:00:00.000Z',
                    'country' => 'Brasil',
                    'competition' => 'Série A',
                    'homeTeam' => $home,
                    'awayTeam' => 'Visitante',
                    'probability' => $pred,
                    'over15HtProbability' => $over15Ht,
                    'firstHalfHomeGoalsAverage' => $fhHome,
                    'firstHalfAwayGoalsAverage' => $fhAway,
                    'halftimeHomeScore' => $hit ? 1 : 0,
                    'halftimeAwayScore' => 0,
                    'homeScore' => $hit ? 2 : 0,
                    'awayScore' => 1,
                    'hit' => $hit,
                    'usedBackfilledFeatures' => $backfilled,
                ];
            }
        });
    }

    public function test_the_history_screen_renders_with_the_profile_chips(): void
    {
        Livewire::test(Over05Ht::class)
            ->call('setMode', 'history')
            ->assertOk()
            ->assertSee('Ataque confirmado')
            ->assertSee('Sinal forte')
            ->assertSee('Somente dados limpos');
    }

    public function test_choosing_a_profile_narrows_the_list(): void
    {
        $page = Livewire::test(Over05Ht::class)->call('setMode', 'history')->call('setMinProbability', 60);

        // Backfill fica de fora por padrão, então baseline vê 3 das 4 linhas.
        $this->assertCount(3, $page->instance()->filteredRows);

        $page->call('setSignalProfile', 'balanced');
        $this->assertCount(2, $page->instance()->filteredRows);

        $page->call('setSignalProfile', 'strong');
        $this->assertCount(1, $page->instance()->filteredRows);
        $this->assertSame('Forte', $page->instance()->filteredRows[0]['homeTeam']);
    }

    public function test_the_backfill_toggle_brings_the_retrofitted_rows_back(): void
    {
        $page = Livewire::test(Over05Ht::class)
            ->call('setMode', 'history')
            ->call('setMinProbability', 60)
            ->call('setSignalProfile', 'strong');

        $this->assertCount(1, $page->instance()->filteredRows);

        $page->call('setBackfillFilter', 1);
        $this->assertCount(2, $page->instance()->filteredRows);
    }

    public function test_the_cutoff_stats_follow_the_selected_profile(): void
    {
        $page = Livewire::test(Over05Ht::class)->call('setMode', 'history')->call('setSignalProfile', 'strong');

        $stats = $page->instance()->cutoffStats;

        // Só a linha "Forte" (78%) entra, e ela é green.
        $this->assertSame(1, $stats[75]['entries']);
        $this->assertSame(1, $stats[75]['wins']);
        $this->assertSame(0, $stats[80]['entries']);
    }

    public function test_an_unknown_profile_is_ignored(): void
    {
        $page = Livewire::test(Over05Ht::class)->call('setSignalProfile', 'inexistente');

        $this->assertSame('baseline', $page->instance()->signalProfile);
        $this->assertArrayHasKey('baseline', FirstHalfGoalsStrategy::PROFILES);
    }
}
