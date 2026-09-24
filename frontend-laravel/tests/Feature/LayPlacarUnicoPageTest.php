<?php

namespace Tests\Feature;

use App\Filament\Pages\DailyDecision;
use App\Filament\Pages\LayDoisADois;
use App\Filament\Pages\LayFavorito;
use App\Filament\Pages\LayZeroAZero;
use App\Models\LayOddQuote;
use App\Models\User;
use App\Oracly\Services\PunterMatchPickService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Cobre as duas telas de lay de placar único, que compartilham LayPlacarUnico e a blade. */
class LayPlacarUnicoPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Mesmo motivo de LayPlacarSecoPageTest: sem store array o cache de arquivo real vaza.
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        $this->instance(PunterMatchPickService::class, new class
        {
            public function history(int $limit = 20000): array
            {
                return [
                    // Favorito fortíssimo, jogo aberto: passa em todo perfil das duas telas. 3x0.
                    self::row('Forte', 1.25, 6.50, 13.0, 1.60, 2.35, 3, 0, '2026-09-01'),
                    // Mesmo perfil, termina 2x2: red no LAY 2x2.
                    self::row('Empate', 1.25, 6.50, 13.0, 1.60, 2.35, 2, 2, '2026-09-02'),
                    // Mesmo perfil, termina 0x0: red no LAY 0x0.
                    self::row('Zerado', 1.25, 6.50, 13.0, 1.60, 2.35, 0, 0, '2026-09-03'),
                    // Equilibrado e aberto: não passa em perfil nenhum de nenhuma das telas.
                    self::row('Parelho', 2.60, 3.60, 2.60, 1.55, 2.50, 1, 1, '2026-09-04'),
                ];
            }

            public function upcoming(string $date): array
            {
                return [
                    [...self::row('Futuro', 1.25, 6.50, 13.0, 1.60, null, null, null, $date),
                        'matchKey' => $date.'|Futuro|Visitante', 'kickoffAt' => $date.' 19:00:00', 'matchLabel' => '14/09 16:00 Futuro x Visitante'],
                    // Sem odd de over 2,5: entra no 0x0 pelo favorito, com preço de coorte.
                    [...self::row('SemOver', 1.25, 6.50, 13.0, null, null, null, null, $date),
                        'matchKey' => $date.'|SemOver|Visitante', 'kickoffAt' => $date.' 22:00:00', 'matchLabel' => '14/09 19:00 SemOver x Visitante'],
                ];
            }

            public function finalScores(array $matches): array
            {
                return ['2026-09-10|Futuro|Visitante' => ['homeGoals' => 0, 'awayGoals' => 0]];
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

    public static function pages(): array
    {
        return [
            '2x2' => [LayDoisADois::class, '/admin/lay-dois-a-dois', 'LAY 2x2', 'balanced'],
            '0x0' => [LayZeroAZero::class, '/admin/lay-zero-a-zero', 'LAY 0x0', 'strong'],
        ];
    }

    #[DataProvider('pages')]
    public function test_each_page_renders_its_table_and_quote_panel(string $page, string $url, string $title, string $profile): void
    {
        $html = $this->get($url)->assertOk()->assertSee($title)->getContent();

        preg_match('/<table class="oracly-table oracly-lay">.*?<\/table>/s', $html, $m);
        $this->assertNotEmpty($m, 'a tabela de jogos não renderizou');
        $this->assertStringContainsString('Futuro', $m[0]);
        $this->assertStringContainsString('Suas odds registradas', $html);
    }

    #[DataProvider('pages')]
    public function test_each_page_opens_in_the_profile_the_validation_supports(string $page, string $url, string $title, string $profile): void
    {
        Livewire::test($page)->assertSet('signalProfile', $profile);
    }

    public function test_both_pages_sit_above_the_clean_sheet_pages(): void
    {
        $this->assertLessThan(LayZeroAZero::getNavigationSort(), LayDoisADois::getNavigationSort());
        $this->assertLessThan(LayFavorito::getNavigationSort(), LayZeroAZero::getNavigationSort());
        $this->assertLessThan(DailyDecision::getNavigationSort(), LayZeroAZero::getNavigationSort());
    }

    #[DataProvider('pages')]
    public function test_history_mode_lists_only_games_in_the_profile_with_results(string $page, string $url, string $title, string $profile): void
    {
        $rows = Livewire::test($page)->call('setMode', 'history')->assertSuccessful()->instance()->filteredRows;

        $this->assertSame(['Zerado', 'Empate', 'Forte'], array_column($rows, 'homeTeam'));
        $expectedRed = $page === LayDoisADois::class ? 'Empate' : 'Zerado';
        foreach ($rows as $row) {
            $this->assertSame($row['homeTeam'] === $expectedRed ? 'red' : 'green', $row['result']);
        }
    }

    #[DataProvider('pages')]
    public function test_profile_stats_measure_frequency_and_fair_odd_on_the_whole_history(string $page, string $url, string $title, string $profile): void
    {
        $stats = Livewire::test($page)->instance()->currentStats;

        // Três jogos no perfil, um red: o placar saiu em 1/3, odd justa 3,00, máxima 2,70.
        $this->assertSame(3, $stats['entries']);
        $this->assertEqualsWithDelta(100 / 3, $stats['frequency'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $stats['fairOdd'], 1e-9);
        $this->assertEqualsWithDelta(2.7, $stats['maxEntryOdd'], 1e-9);
    }

    public function test_a_game_without_over_price_falls_back_to_the_cohort_price_on_the_nil_nil_page(): void
    {
        $rows = collect(Livewire::test(LayZeroAZero::class)->instance()->filteredRows)->keyBy('homeTeam');

        $this->assertFalse($rows['Futuro']['priceIsCohort']);
        $this->assertTrue($rows['SemOver']['priceIsCohort']);
        $this->assertEqualsWithDelta(3.0, $rows['SemOver']['fairOdd'], 1e-9);

        // No 2x2 o perfil corta na própria chance, então jogo sem over 2,5 nem aparece.
        $this->assertSame(['Futuro'], array_column(Livewire::test(LayDoisADois::class)->instance()->filteredRows, 'homeTeam'));
    }

    public function test_the_verdict_compares_the_typed_odd_with_the_max_entry_odd(): void
    {
        $page = Livewire::test(LayZeroAZero::class);
        $row = collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro');
        $hash = $row['quoteHash'];

        $page->set("quoteInputs.{$hash}", number_format($row['fairOdd'] * 0.85, 2, ',', ''));
        $this->assertSame('enter', collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro')['verdict']['verdict']);

        $page->set("quoteInputs.{$hash}", (string) ($row['fairOdd'] * 0.95));
        $this->assertSame('thin', collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro')['verdict']['verdict']);

        $page->set("quoteInputs.{$hash}", (string) ($row['fairOdd'] * 1.2));
        $verdict = collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro')['verdict'];
        $this->assertSame('skip', $verdict['verdict']);
        $this->assertLessThan(0, $verdict['expectedReturn']);

        $page->set("quoteInputs.{$hash}", 'abc');
        $this->assertNull(collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro')['verdict']);
    }

    public function test_registering_an_odd_saves_it_with_the_price_of_the_moment_and_prefills_on_reload(): void
    {
        $page = Livewire::test(LayZeroAZero::class);
        $row = collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro');

        $page->set("quoteInputs.{$row['quoteHash']}", '18,5')->call('saveQuote', $row['quoteHash']);

        $quote = LayOddQuote::sole();
        $this->assertSame($this->user->id, $quote->user_id);
        $this->assertSame('lay_0x0', $quote->strategy);
        $this->assertSame('strong', $quote->profile);
        $this->assertSame(18.5, $quote->offered_odd);
        $this->assertEqualsWithDelta($row['fairOdd'], $quote->fair_odd, 1e-9);

        // Registrar de novo o mesmo jogo atualiza em vez de duplicar.
        $page->set("quoteInputs.{$row['quoteHash']}", '17')->call('saveQuote', $row['quoteHash']);
        $this->assertSame(17.0, LayOddQuote::sole()->offered_odd);

        // Outra instância da tela já abre com a odd digitada; a outra estratégia não enxerga.
        $this->assertSame(17.0, Livewire::test(LayZeroAZero::class)->instance()->quoteInputs[$row['quoteHash']]);
        $this->assertSame([], Livewire::test(LayDoisADois::class)->instance()->quotes);
    }

    public function test_an_invalid_odd_is_not_saved(): void
    {
        $page = Livewire::test(LayZeroAZero::class);
        $hash = collect($page->instance()->filteredRows)->firstWhere('homeTeam', 'Futuro')['quoteHash'];

        $page->set("quoteInputs.{$hash}", '0,9')->call('saveQuote', $hash);
        $page->set("quoteInputs.{$hash}", '')->call('saveQuote', $hash);

        $this->assertSame(0, LayOddQuote::count());
    }

    public function test_the_quote_summary_settles_results_and_measures_the_ratio_to_fair(): void
    {
        $base = ['user_id' => $this->user->id, 'strategy' => 'lay_0x0', 'home_team' => 'Futuro', 'away_team' => 'Visitante', 'profile' => 'strong', 'probability' => 4.0, 'fair_odd' => 25.0];
        // Apurado pelo duplo como 0x0: red.
        LayOddQuote::create([...$base, 'match_key' => 'a', 'match_date' => '2026-09-10', 'offered_odd' => 20.0]);
        // Ainda sem placar: pendente.
        LayOddQuote::create([...$base, 'match_key' => 'b', 'match_date' => '2026-09-11', 'home_team' => 'Outro', 'offered_odd' => 25.0]);
        // De outro usuário: não entra.
        LayOddQuote::create([...$base, 'user_id' => User::factory()->create()->id, 'match_key' => 'c', 'match_date' => '2026-09-10', 'offered_odd' => 10.0]);

        $page = Livewire::test(LayZeroAZero::class)->instance();
        $summary = $page->quoteSummary;

        $this->assertSame(2, $summary['count']);
        $this->assertEqualsWithDelta(0.9, $summary['medianRatio'], 1e-9);
        $this->assertEqualsWithDelta(50.0, $summary['enterShare'], 1e-9);
        $this->assertSame(1, $summary['settled']);
        $this->assertSame(1, $summary['reds']);
        $this->assertEqualsWithDelta(-100.0, $summary['realizedReturn'], 1e-9);
        // Mais recente primeiro: o pendente (dia 11) vem antes do apurado (dia 10).
        $this->assertSame([null, 'red'], array_column($page->quotes, 'result'));
    }

    public function test_a_quote_can_only_be_deleted_by_its_owner(): void
    {
        $base = ['strategy' => 'lay_0x0', 'match_date' => '2026-09-10', 'home_team' => 'A', 'away_team' => 'B', 'profile' => 'strong', 'offered_odd' => 20.0];
        $mine = LayOddQuote::create([...$base, 'user_id' => $this->user->id, 'match_key' => 'mine']);
        $theirs = LayOddQuote::create([...$base, 'user_id' => User::factory()->create()->id, 'match_key' => 'theirs']);

        Livewire::test(LayZeroAZero::class)->call('deleteQuote', $theirs->id)->call('deleteQuote', $mine->id);

        $this->assertSame(['theirs'], LayOddQuote::pluck('match_key')->all());
    }

    #[DataProvider('pages')]
    public function test_invalid_profile_and_hour_are_ignored(string $page, string $url, string $title, string $profile): void
    {
        Livewire::test($page)
            ->call('setSignalProfile', 'inexistente')->assertSet('signalProfile', $profile)
            ->call('setHourFilter', '99:00')->assertSet('hourFilter', 'all');
    }
}
