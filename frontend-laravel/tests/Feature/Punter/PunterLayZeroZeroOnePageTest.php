<?php

namespace Tests\Feature\Punter;

use App\Filament\Pages\PunterLayList;
use App\Filament\Pages\PunterLayZeroZeroOne;
use App\Models\LayOddQuote;
use App\Models\User;
use App\Oracly\Services\PunterLaySignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class PunterLayZeroZeroOnePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        // Sem store array o cache de arquivo real vaza entre testes (ver LayPlacarUnicoPageTest).
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        // PunterLaySignalService é final: a página chama sem type hint, então um duplo basta.
        $this->instance(PunterLaySignalService::class, new class
        {
            public function forDate(string $date): array
            {
                return [
                    self::signal('hoje-1', 'lay_0x1', '2026-09-23 22:00:00+00', 1.50, null, null),
                    self::signal('hoje-2', 'lay_0x1', '2026-09-23 22:30:00+00', 1.65, null, null),
                    // Outro radar: não entra nesta tela.
                    self::signal('hoje-3', 'lay_2x2', '2026-09-23 23:00:00+00', 1.30, null, null),
                ];
            }

            public function history(int $limit = 5000): array
            {
                return [
                    self::signal('g1', 'lay_0x1', '2025-05-01 18:00:00+00', 1.50, 2, 1),
                    self::signal('g2', 'lay_0x1', '2026-05-02 18:00:00+00', 1.50, 1, 0),
                    self::signal('nil', 'lay_0x1', '2026-05-03 18:00:00+00', 1.50, 0, 0),
                    self::signal('one', 'lay_0x1', '2026-05-04 18:00:00+00', 1.50, 0, 1),
                    self::signal('2x2', 'lay_2x2', '2026-05-05 18:00:00+00', 1.50, 2, 2),
                ];
            }

            private static function signal(string $key, string $radar, string $kickoff, float $oddHome, ?int $ft, ?int $fa): array
            {
                return [
                    'matchKey' => $key, 'radar' => $radar, 'kickoffAt' => $kickoff,
                    'country' => 'BRAZIL', 'competition' => 'Serie A',
                    'homeTeam' => 'Casa '.$key, 'awayTeam' => 'Fora '.$key,
                    'oddHome' => $oddHome, 'oddAway' => 6.0,
                    'ftHome' => $ft, 'ftAway' => $fa, 'htHome' => null, 'htAway' => null,
                    'settled' => $ft !== null,
                ];
            }
        });
    }

    public function test_page_renders_the_day_signals_with_suggested_stakes(): void
    {
        $this->get('/admin/punter-lay-00-01')
            ->assertOk()
            ->assertSee('LAY 0x0 + 0x1')
            ->assertSee('Casa hoje-1')
            ->assertSee('R$ 10,00')
            ->assertSee('R$ 7,41')
            ->assertDontSee('Casa hoje-3');
    }

    public function test_it_sits_right_below_the_punter_lay_list(): void
    {
        $this->assertGreaterThan(PunterLayList::getNavigationSort(), PunterLayZeroZeroOne::getNavigationSort());
    }

    public function test_history_counts_only_settled_lay_0x1_signals_of_the_year(): void
    {
        $page = Livewire::test(PunterLayZeroZeroOne::class)
            ->call('setMode', 'history')
            ->call('setRule', 'all')
            ->call('setHistoryYear', '2026')
            ->assertSuccessful();

        $stats = $page->instance()->historyStats;
        $this->assertSame(3, $stats['entries']);
        $this->assertSame(1, $stats['greens']);
        $this->assertSame(1, $stats['redNil']);
        $this->assertSame(1, $stats['redOne']);
        $this->assertSame(['2025', '2026'], array_column($page->instance()->yearStats, 'year'));
    }

    public function test_saving_quotes_stores_one_row_per_leg_and_they_feed_the_history(): void
    {
        $page = Livewire::test(PunterLayZeroZeroOne::class);
        $hash = PunterLayZeroZeroOne::quoteHash('hoje-1');

        $page->set("quoteInputs.{$hash}.nil", '11')
            ->set("quoteInputs.{$hash}.one", '14,5')
            ->call('saveQuote', $hash);

        $quotes = LayOddQuote::query()->orderBy('strategy')->get();
        $this->assertSame(['lay_00_01_nil', 'lay_00_01_one'], $quotes->pluck('strategy')->all());
        $this->assertSame([11.0, 14.5], $quotes->pluck('offered_odd')->all());

        $row = collect($page->instance()->upcomingRows)->firstWhere('matchKey', 'hoje-1');
        $this->assertTrue($row['oddsTyped']);
        // No histórico falso 2 dos 4 sinais são red (0x0 e 0x1): a essas odds o red não se paga.
        $this->assertSame('skip', $row['verdict']['verdict']);
        $this->assertLessThan(0, $row['verdict']['expectedReturn']);
    }

    public function test_saving_requires_both_odds(): void
    {
        $hash = PunterLayZeroZeroOne::quoteHash('hoje-1');

        Livewire::test(PunterLayZeroZeroOne::class)
            ->set("quoteInputs.{$hash}.nil", '11')
            ->call('saveQuote', $hash);

        $this->assertSame(0, LayOddQuote::count());
    }

    public function test_registered_odds_replace_the_simulation_odds_in_history(): void
    {
        LayOddQuote::create([
            'user_id' => auth()->id(), 'strategy' => 'lay_00_01_nil', 'match_key' => 'g2',
            'match_date' => '2026-05-02', 'home_team' => 'Casa g2', 'away_team' => 'Fora g2',
            'profile' => 'all', 'offered_odd' => 9.0,
        ]);
        LayOddQuote::create([
            'user_id' => auth()->id(), 'strategy' => 'lay_00_01_one', 'match_key' => 'g2',
            'match_date' => '2026-05-02', 'home_team' => 'Casa g2', 'away_team' => 'Fora g2',
            'profile' => 'all', 'offered_odd' => 12.0,
        ]);

        $page = Livewire::test(PunterLayZeroZeroOne::class)
            ->call('setMode', 'history')
            ->call('setRule', 'all')
            ->call('setHistoryYear', '2026');

        $g2 = collect($page->instance()->selectedHistory)->firstWhere('matchKey', 'g2');
        $this->assertTrue($g2['realOdds']);
        $this->assertSame(['nil' => 9.0, 'one' => 12.0], $g2['odds']);
        $this->assertSame(1, $page->instance()->historyStats['realOdds']);
        $this->assertSame('green', collect($page->instance()->quotes)->firstWhere('matchKey', 'g2')['result']);
    }
}
