<?php

namespace Tests\Feature;

use App\Filament\Pages\DailyDecision;
use App\Filament\Pages\LayFavorito;
use App\Filament\Pages\LayZebra;
use App\Models\User;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\PunterMatchPickService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Cobre as duas telas de LAY de placar seco, que compartilham LayPlacarSeco e a blade. */
class LayPlacarSecoPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        // As páginas leem o histórico por OraclyCache. Sem fixar o store aqui, o teste pode
        // cair no cache de arquivo da aplicação e enxergar 43 mil linhas reais no lugar dos
        // duplos, derrubando as contagens de forma intermitente.
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        // reload() também consulta favoritos; sem este duplo a exceção do Postgres cai no
        // catch da página e zera a lista, mascarando o que o teste mede.
        $this->instance(FavoritesService::class, new class
        {
            public function get(): array
            {
                return ['leagues' => [], 'countries' => []];
            }
        });

        // PunterMatchPickService é final; as páginas chamam ->history() e ->upcoming(), então
        // um duplo por duck typing basta e evita tocar o Postgres.
        $this->instance(PunterMatchPickService::class, new class
        {
            public function history(int $limit = 20000): array
            {
                return [
                    // Mandante favorito FORTE (1,55), BTTS ~62%: passa em todo perfil e no
                    // corte de favorito forte da zebra. Placar 2x1, green nas quatro pernas.
                    self::row('Forte', 1.55, 2.50, 1.55, 4.20, 2, 1),
                    // Favorito FRACO (2,60), mesmo BTTS: cai no corte de favorito fraco.
                    // Placar 1x0 do mandante: red na perna de 1 gol do favorito.
                    self::row('Fraco', 1.55, 2.50, 2.60, 3.10, 1, 0),
                    // Favorito é o VISITANTE (1,70) e a zebra vence 2x0: red na perna de
                    // 2 gols da zebra, sem a qual a odd justa daquele lado seria infinita.
                    self::row('Zebra', 1.55, 2.50, 4.00, 1.70, 2, 0),
                    // BTTS ~50%: não passa em perfil nenhum.
                    self::row('Nenhum', 1.90, 1.90, 1.70, 4.50, 0, 2),
                ];
            }

            public function upcoming(string $date): array
            {
                return [[
                    'matchKey' => 'k', 'matchDate' => $date, 'matchLabel' => '12/09 16:00 A x B',
                    'kickoffAt' => $date.'T19:30:00+00:00',
                    'homeTeam' => 'Futuro', 'awayTeam' => 'Visitante', 'competition' => 'Liga',
                    'oddHome' => 1.80, 'oddDraw' => 3.40, 'oddAway' => 4.20,
                    // panel_fixtures só traz o lado "sim" do BTTS: escala crua.
                    'oddBttsYes' => 1.50, 'oddBttsNo' => null,
                    'homeGoals' => null, 'awayGoals' => null,
                ]];
            }

            private static function row(string $home, float $bYes, float $bNo, float $oHome, float $oAway, int $gHome, int $gAway): array
            {
                return [
                    'matchKey' => $home, 'matchDate' => '2026-09-0'.strlen($home),
                    'homeTeam' => $home, 'awayTeam' => 'Adversario', 'competition' => 'Liga',
                    'oddHome' => $oHome, 'oddAway' => $oAway, 'oddDraw' => 3.40,
                    'oddBttsYes' => $bYes, 'oddBttsNo' => $bNo,
                    'homeGoals' => $gHome, 'awayGoals' => $gAway,
                ];
            }
        });
    }

    public static function pages(): array
    {
        return [
            'favorito' => [LayFavorito::class, '/admin/lay-favorito', 'LAY favorito vence a zero'],
            'zebra' => [LayZebra::class, '/admin/lay-zebra', 'LAY zebra vence a zero'],
        ];
    }

    #[DataProvider('pages')]
    public function test_each_page_is_reachable_at_its_slug(string $page, string $url, string $title): void
    {
        $this->get($url)->assertOk()->assertSee($title);
    }

    /**
     * A página tem que RENDERIZAR a tabela, não só devolver 200.
     *
     * Uma refatoração já apagou a propriedade $view da classe: o Filament seguiu servindo 200
     * com o cabeçalho do painel e nada do conteúdo, e todo teste que olhava só o estado do
     * Livewire continuou verde. Este olha o HTML.
     */
    #[DataProvider('pages')]
    public function test_each_page_actually_renders_its_table(string $page, string $url, string $title): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('oracly-picks', $html, 'a tabela de picks não renderizou');
        $this->assertStringContainsString('oracly-verdict', $html, 'o painel de odd justa não renderizou');

        // O corpo tem linhas de verdade, vindas do duplo de upcoming.
        preg_match('/<table class="oracly-table oracly-picks">.*?<\/table>/s', $html, $m);
        $this->assertNotEmpty($m, 'a tabela de picks não foi encontrada no HTML');
        $this->assertStringContainsString('Futuro', $m[0]);

        // E as duas pernas daquele lado aparecem no cabeçalho.
        foreach (Livewire::test($page)->instance()->legOptions as $label) {
            $this->assertStringContainsString($label, $m[0]);
        }
    }

    public function test_both_pages_sit_at_the_top_of_the_daily_group(): void
    {
        foreach ([LayFavorito::class, LayZebra::class] as $page) {
            $this->assertSame('Operação diária', $page::getNavigationGroup(), $page);
        }

        // Acima de DailyDecision, que ocupa o 0 e era o primeiro do grupo.
        $this->assertLessThan(LayZebra::getNavigationSort(), LayFavorito::getNavigationSort());
        $this->assertLessThan(DailyDecision::getNavigationSort(), LayZebra::getNavigationSort());

        // E a ordem se confirma no menu de verdade, não só nos estáticos.
        $nav = $this->get('/admin/favorites')->assertOk()->getContent();
        $nav = substr($nav, (int) strpos($nav, 'fi-sidebar-nav'));
        $this->assertLessThan(strpos($nav, '/admin/daily-decision"'), strpos($nav, '/admin/lay-favorito"'));
        $this->assertLessThan(strpos($nav, '/admin/daily-decision"'), strpos($nav, '/admin/lay-zebra"'));
    }

    #[DataProvider('pages')]
    public function test_both_modes_render(string $page, string $url, string $title): void
    {
        foreach (['upcoming', 'history'] as $mode) {
            Livewire::test($page)->call('setMode', $mode)->assertSet('mode', $mode)->assertSuccessful();
        }
    }

    #[DataProvider('pages')]
    public function test_each_page_lists_only_its_own_two_legs(string $page, string $url, string $title): void
    {
        $legs = array_keys(Livewire::test($page)->instance()->legOptions);
        $expected = str_contains($title, 'favorito') ? ['fav1', 'fav2'] : ['dog1', 'dog2'];

        $this->assertSame($expected, $legs);
    }

    #[DataProvider('pages')]
    public function test_the_rows_carry_only_the_legs_of_that_side(string $page, string $url, string $title): void
    {
        $rows = Livewire::test($page)->call('setMode', 'history')->call('setSignalProfile', 'balanced')->instance()->filteredRows;
        $side = str_contains($title, 'favorito') ? 'favourite' : 'underdog';

        $this->assertNotEmpty($rows);
        $this->assertSame([$side], array_values(array_unique(array_column($rows, 'legSide'))));
    }

    public function test_the_recommended_leg_differs_between_the_two_pages(): void
    {
        // No favorito o filtro trabalha e a perna de 1 gol concentra o ganho; na zebra ele
        // não trabalha, e sobra a perna mais rara.
        $this->assertSame('fav1', Livewire::test(LayFavorito::class)->instance()->offeredLeg);
        $this->assertSame('dog2', Livewire::test(LayZebra::class)->instance()->offeredLeg);
    }

    public function test_only_the_zebra_page_exposes_the_favourite_odd_filter(): void
    {
        $this->assertFalse(Livewire::test(LayFavorito::class)->instance()->usesFavouriteOddFilter());
        $this->assertTrue(Livewire::test(LayZebra::class)->instance()->usesFavouriteOddFilter());

        // O filtro é inerte na tela do favorito, mesmo se chamado à força.
        Livewire::test(LayFavorito::class)->call('setFavouriteOddFilter', 'strong')->assertSet('favouriteOddFilter', 'all');
    }

    public function test_the_favourite_odd_filter_narrows_the_zebra_history(): void
    {
        $page = Livewire::test(LayZebra::class)->call('setMode', 'history')->call('setSignalProfile', 'balanced');

        // Três jogos qualificados, duas pernas cada.
        $this->assertCount(6, $page->instance()->filteredRows);

        $page->call('setFavouriteOddFilter', 'strong');
        $rows = $page->instance()->filteredRows;
        $this->assertCount(4, $rows);
        $this->assertSame(['Forte', 'Zebra'], array_values(array_unique(array_column($rows, 'homeTeam'))));

        $page->call('setFavouriteOddFilter', 'weak');
        $this->assertSame(['Fraco'], array_values(array_unique(array_column($page->instance()->filteredRows, 'homeTeam'))));

        $page->call('setFavouriteOddFilter', 'inexistente');
        $this->assertSame('weak', $page->instance()->favouriteOddFilter);
    }

    public function test_each_page_has_its_own_fair_odds_and_history_stats(): void
    {
        $fav = Livewire::test(LayFavorito::class)->call('setMode', 'history')->instance();
        $zebra = Livewire::test(LayZebra::class)->call('setMode', 'history')->instance();

        $this->assertSame(['fav1', 'fav2'], array_keys($fav->fairOdds));
        $this->assertSame(['dog1', 'dog2'], array_keys($zebra->fairOdds));

        // As estatísticas por perfil também são separadas por lado.
        $this->assertSame(['fav1', 'fav2'], array_keys($fav->profileStats['balanced']));
        $this->assertSame(['dog1', 'dog2'], array_keys($zebra->profileStats['balanced']));
    }

    public function test_the_daily_list_uses_the_raw_btts_scale(): void
    {
        $rows = Livewire::test(LayFavorito::class)->call('setMode', 'upcoming')->call('setSignalProfile', 'balanced')->instance()->filteredRows;

        // 1/1.50 = 66,7% na escala crua, acima do corte cru de 65. Um jogo, duas pernas.
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['bttsIsRaw']);
        $this->assertEqualsWithDelta(66.6667, $rows[0]['bttsProbability'], 1e-3);
    }

    public function test_the_offered_odd_verdict_compares_against_the_fair_odd(): void
    {
        $page = Livewire::test(LayZebra::class)->call('setMode', 'history')->call('setSignalProfile', 'baseline');
        $this->assertNull($page->instance()->offeredVerdict);

        // 1 red em 3 jogos: frequência 1/3, odd justa 3,00.
        $fair = $page->instance()->fairOdds['dog2'];
        $this->assertEqualsWithDelta(3.0, $fair, 1e-9);

        $page->set('offeredOdd', $fair - 1);
        $this->assertSame('enter', $page->instance()->offeredVerdict['verdict']);

        $page->set('offeredOdd', $fair + 1);
        $this->assertSame('skip', $page->instance()->offeredVerdict['verdict']);
    }

    public function test_an_invalid_hour_filter_falls_back_to_all(): void
    {
        Livewire::test(LayFavorito::class)->call('setHourFilter', '99:00')->assertSet('hourFilter', 'all');
    }
}
