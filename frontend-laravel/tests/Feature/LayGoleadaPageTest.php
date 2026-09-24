<?php

namespace Tests\Feature;

use App\Filament\Pages\LayDoisADois;
use App\Filament\Pages\LayGoleada;
use App\Models\LayOddQuote;
use App\Models\User;
use App\Oracly\Services\PunterMatchPickService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** A tela herda LayPlacarUnico; aqui só o que muda com um evento que depende do lado favorito. */
class LayGoleadaPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Mesmo motivo de LayPlacarUnicoPageTest: sem store array o cache de arquivo real vaza.
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        $this->instance(PunterMatchPickService::class, new class
        {
            public function history(int $limit = 20000): array
            {
                return [
                    // Favorito mandante a 1,55, jogo aberto: ~8% de goleada, passa nos três perfis.
                    self::row('Aberto', 1.55, 4.20, 6.00, 1.65, 2.20, 2, 0, '2026-09-01'),
                    self::row('Goleada', 1.55, 4.20, 6.00, 1.65, 2.20, 4, 0, '2026-09-02'),
                    // Favorito visitante; o mandante azarão é quem goleia: green.
                    self::row('Zebra', 6.00, 4.20, 1.55, 1.65, 2.20, 4, 0, '2026-09-03'),
                    // Favorito fortíssimo: ~16% de goleada, acima de todo perfil.
                    self::row('Fortissimo', 1.25, 6.50, 13.0, 1.60, 2.35, 5, 0, '2026-09-04'),
                ];
            }

            public function upcoming(string $date): array
            {
                return [
                    [...self::row('Futuro', 1.55, 4.20, 6.00, 1.65, null, null, null, $date),
                        'matchKey' => $date.'|Futuro|Visitante', 'kickoffAt' => $date.' 19:00:00', 'matchLabel' => '14/09 16:00 Futuro x Visitante'],
                    // Sem odd de over 2,5: sem chance própria, fica fora de todo perfil.
                    [...self::row('SemOver', 1.55, 4.20, 6.00, null, null, null, null, $date),
                        'matchKey' => $date.'|SemOver|Visitante', 'kickoffAt' => $date.' 22:00:00', 'matchLabel' => '14/09 19:00 SemOver x Visitante'],
                ];
            }

            public function finalScores(array $matches): array
            {
                return [
                    '2026-09-10|Futuro|Visitante' => ['homeGoals' => 4, 'awayGoals' => 0, 'oddHome' => 1.55, 'oddAway' => 6.00],
                    '2026-09-10|Zebra|Visitante' => ['homeGoals' => 4, 'awayGoals' => 0, 'oddHome' => 6.00, 'oddAway' => 1.55],
                ];
            }

            private static function row(string $home, float $oHome, float $oDraw, float $oAway, ?float $over, ?float $under, ?int $gHome, ?int $gAway, string $date): array
            {
                return [
                    'matchKey' => $home, 'matchDate' => $date,
                    'homeTeam' => $home, 'awayTeam' => 'Visitante', 'competition' => 'Liga',
                    'oddHome' => $oHome, 'oddDraw' => $oDraw, 'oddAway' => $oAway,
                    'oddOver25' => $over, 'oddUnder25' => $under,
                    'homeGoals' => $gHome, 'awayGoals' => $gAway,
                ];
            }
        });
    }

    public function test_the_page_renders_the_daily_list_with_the_rout_wording(): void
    {
        $html = $this->get('/admin/lay-goleada')->assertOk()->assertSee('LAY Goleada')->getContent();

        preg_match('/<table class="oracly-table oracly-lay">.*?<\/table>/s', $html, $m);
        $this->assertNotEmpty($m, 'a tabela de jogos não renderizou');
        $this->assertStringContainsString('Chance de goleada', $m[0]);
        $this->assertStringContainsString('Futuro', $m[0]);
        $this->assertStringNotContainsString('SemOver', $m[0]);
        $this->assertStringContainsString('handicap −3,5', $html);
    }

    public function test_it_opens_in_the_baseline_profile_and_sits_above_the_score_pages(): void
    {
        Livewire::test(LayGoleada::class)->assertSet('signalProfile', 'baseline');
        $this->assertLessThan(LayDoisADois::getNavigationSort(), LayGoleada::getNavigationSort());
    }

    public function test_history_is_red_only_when_the_favourite_routs(): void
    {
        $rows = Livewire::test(LayGoleada::class)->call('setMode', 'history')->assertSuccessful()->instance()->filteredRows;

        $this->assertSame(['Zebra' => 'green', 'Goleada' => 'red', 'Aberto' => 'green'], array_column($rows, 'result', 'homeTeam'));
    }

    public function test_profile_stats_and_the_game_price_come_from_the_model(): void
    {
        $page = Livewire::test(LayGoleada::class)->instance();

        $this->assertSame(3, $page->currentStats['entries']);
        $this->assertEqualsWithDelta(100 / 3, $page->currentStats['frequency'], 1e-9);

        $row = collect($page->filteredRows)->sole();
        $this->assertSame('Futuro', $row['homeTeam']);
        $this->assertFalse($row['priceIsCohort']);
        $this->assertEqualsWithDelta(8.0, $row['priceProbability'], 0.5);
        $this->assertEqualsWithDelta(100 / $row['priceProbability'], $row['fairOdd'], 1e-9);
    }

    public function test_a_registered_odd_is_settled_from_the_favourite_side(): void
    {
        $page = Livewire::test(LayGoleada::class);
        $row = collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro');
        $page->set("quoteInputs.{$row['quoteHash']}", '11,5')->call('saveQuote', $row['quoteHash']);

        $quote = LayOddQuote::sole();
        $this->assertSame('lay_goleada', $quote->strategy);
        $this->assertSame(11.5, $quote->offered_odd);

        // O jogo do duplo terminou 4x0 com o mandante favorito: red. O mesmo 4x0 com o mandante azarão é green.
        $quote->update(['match_date' => '2026-09-10']);
        LayOddQuote::create([...$quote->only(['user_id', 'strategy', 'away_team', 'profile', 'probability', 'fair_odd', 'offered_odd']),
            'match_key' => 'zebra', 'match_date' => '2026-09-10', 'home_team' => 'Zebra']);

        $results = array_column(Livewire::test(LayGoleada::class)->instance()->quotes, 'result', 'homeTeam');
        $this->assertSame(['Zebra' => 'green', 'Futuro' => 'red'], $results);
    }
}
