<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstFavouriteCleanSheetStrategy;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\HistoryCsv;
use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\RanksHourlyOpportunities;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Base das duas telas de LAY de placar seco: LayFavorito e LayZebra.
 *
 * Um lado por tela, com histórico próprio, porque as duas apostas não competem entre si e
 * misturá-las escondia o que separa uma da outra. No favorito o filtro de ambas marcam vale
 * +7,6pp e a odd do favorito é ruído; na zebra é o inverso — o BTTS quase não separa (+2,0pp
 * e −0,5pp) e quem discrimina é a odd do favorito. Cada tela expõe o filtro que funciona nela.
 *
 * Mesmo padrão de AgainstOneGoal e suas três subclasses: a base concentra a lógica, as
 * subclasses trocam só os estáticos de navegação e os hooks. A blade é herdada.
 *
 * Fonte única nos dois modos: Punter. O histórico vem de match_history (44 mil partidas, odd
 * de fechamento) e a lista do dia de panel_fixtures (odd de abertura). O SokkerPRO ficou de
 * fora: a réplica lá mostrou resolução bem menor e a coleta está fora do ar desde 2026-09-02
 * por bloqueio de IP na origem.
 *
 * A tela existe para uma comparação que nenhuma outra faz: assertividade contra PREÇO. Lay de
 * placar exato só é lucro quando a odd oferecida está abaixo da odd justa, por isso a coluna
 * de odd justa e o campo de odd oferecida são o centro da página.
 */
abstract class LayPlacarSeco extends Page
{
    use RanksHourlyOpportunities;

    /** Blade herdada pelas duas subclasses; cada lado muda só os textos, não o layout. */
    protected string $view = 'filament.pages.lay-placar-seco';

    /** 'favourite' ou 'underdog'. @see AgainstFavouriteCleanSheetStrategy::SIDES */
    abstract protected function side(): string;

    /** Texto de abertura, específico de cada lado. */
    abstract public function getSideDescriptionProperty(): string;

    /** Nota que explica a perna recomendada daquele lado. */
    abstract public function getRecommendationNoteProperty(): string;

    abstract protected function historyCsvFilename(): string;

    /** Amostra mínima para recomendar um corte, mesma régua do Over05Ht. */
    private const MIN_SAMPLE_FOR_RECOMMENDATION = 20;

    /**
     * Quantas partidas do histórico a tabela mostra.
     *
     * A base inteira tem 43.824 linhas apuradas e o Livewire serializa toda propriedade
     * pública a cada clique — sem este corte o payload passa de 25 MB por requisição. As
     * estatísticas (getProfileStatsProperty) continuam medidas na base inteira; só a tabela
     * é recortada.
     */
    private const HISTORY_DISPLAY_LIMIT = 500;

    public string $mode = 'upcoming';

    public string $date = '';

    public string $signalProfile = 'balanced';

    public string $hourFilter = 'all';

    /** Odd de lay que a exchange está oferecendo, digitada pelo operador. */
    public ?float $offeredOdd = null;

    public string $offeredLeg = '';

    /** Qual estratégia listar: uma perna, ou todas. */
    public string $legFilter = 'all';

    /** Corte de odd do favorito. Só a tela da zebra o expõe — ver ZEBRA_FAVOURITE_ODD_CUT. */
    public string $favouriteOddFilter = 'all';

    /** @var array<string, string> */
    public const FAVOURITE_ODD_OPTIONS = [
        'all' => 'Qualquer favorito',
        'strong' => 'Favorito forte (odd < 1,90)',
        'weak' => 'Favorito fraco (odd ≥ 1,90)',
    ];

    /** A tela da zebra mostra o corte de odd; a do favorito não, porque lá ele é ruído. */
    public function usesFavouriteOddFilter(): bool
    {
        return $this->side() === 'underdog';
    }

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $historyRows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<string, string> */
    public const SIGNAL_PROFILES = AgainstFavouriteCleanSheetStrategy::PROFILES;

    /** As duas pernas DESTE lado. @return array<string, string> */
    public function getLegOptionsProperty(): array
    {
        return array_map(
            fn (array $leg): string => $leg['label'],
            AgainstFavouriteCleanSheetStrategy::legsForSide($this->side()),
        );
    }

    /** @return array<string, string> */
    public function getLegFilterOptionsProperty(): array
    {
        return ['all' => 'As duas'] + $this->legOptions;
    }

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->offeredLeg = AgainstFavouriteCleanSheetStrategy::recommendedLegForSide($this->side());
        $this->reload();
    }

    public function reload(): void
    {
        try {
            $this->historyRows = $this->mode === 'history' ? $this->buildHistoryRows() : [];
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
            $this->rows = $this->mode === 'history'
                ? $this->historyRows
                : app(PunterMatchPickService::class)->upcoming($this->date);

            if ($this->mode === 'upcoming' && $this->hourFilter !== 'all' && ! in_array($this->hourFilter, $this->hours, true)) {
                $this->hourFilter = 'all';
            }
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler a base Punter')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Janela de exibição do histórico, já recortada. Ver HISTORY_DISPLAY_LIMIT.
     *
     * Sem type hint no serviço de propósito: os testes injetam um duplo por duck typing
     * (PunterMatchPickService é final), e um parâmetro tipado faria o TypeError cair no
     * catch de reload(), devolvendo lista vazia em vez de falhar.
     *
     * @return list<array<string, mixed>>
     */
    private function buildHistoryRows(): array
    {
        return array_slice($this->settledHistory(), 0, self::HISTORY_DISPLAY_LIMIT);
    }

    /**
     * Base inteira apurada, mais recentes primeiro. Não vira propriedade pública: é grande
     * demais para o Livewire serializar.
     *
     * @return list<array<string, mixed>>
     */
    private function settledHistory(): array
    {
        return OraclyCache::remember(OraclyCache::key('lay-seco:history:v3'), function (): array {
            $strategy = $this->strategy();
            $rows = array_values(array_filter(
                app(PunterMatchPickService::class)->history(60000),
                fn (array $row): bool => $strategy->portfolioResult($row, $this->side()) !== null
                    && $strategy->bttsProbability($row) !== null,
            ));

            // Mais recentes primeiro na tela; o backtest é quem ordena ascendente pro split.
            usort($rows, fn (array $a, array $b): int => ($b['matchDate'] ?? '') <=> ($a['matchDate'] ?? ''));

            return $rows;
        }, 900);
    }

    public function strategy(): AgainstFavouriteCleanSheetStrategy
    {
        return app(AgainstFavouriteCleanSheetStrategy::class);
    }

    public function refresh(): void
    {
        OraclyCache::forgetPrefix();
        $this->reload();
    }

    public function setMode(string $value): void
    {
        if (array_key_exists($value, self::MODE_OPTIONS)) {
            $this->mode = $value;
            $this->reload();
        }
    }

    public function setSignalProfile(string $value): void
    {
        if (array_key_exists($value, self::SIGNAL_PROFILES)) {
            $this->signalProfile = $value;
        }
    }

    public function setOfferedLeg(string $value): void
    {
        if (array_key_exists($value, $this->legOptions)) {
            $this->offeredLeg = $value;
        }
    }

    public function setFavouriteOddFilter(string $value): void
    {
        if ($this->usesFavouriteOddFilter() && array_key_exists($value, self::FAVOURITE_ODD_OPTIONS)) {
            $this->favouriteOddFilter = $value;
        }
    }

    public function setLegFilter(string $value): void
    {
        if (array_key_exists($value, $this->legFilterOptions)) {
            $this->legFilter = $value;
        }
    }

    public function setHourFilter(string $hour): void
    {
        $this->hourFilter = $hour === 'all' || in_array($hour, $this->hours, true) ? $hour : 'all';
    }

    public function toggleLeague(string $country, string $competition): void
    {
        try {
            app(FavoritesService::class)->toggleLeague($country, $competition);
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        } catch (\Throwable $e) {
            Notification::make()->title('Erro ao salvar favorito')->body($e->getMessage())->danger()->send();
        }
    }

    public function previousDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, -1);
        if ($this->mode === 'upcoming') {
            $this->reload();
        }
    }

    public function nextDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, 1);
        if ($this->mode === 'upcoming') {
            $this->reload();
        }
    }

    /** Horas de Brasília presentes na lista do dia, para as abas. @return list<string> */
    public function getHoursProperty(): array
    {
        $hours = [];
        foreach ($this->rows as $row) {
            $kickoff = $row['kickoffAt'] ?? null;
            if (is_string($kickoff) && $kickoff !== '') {
                $hours[BrasiliaDate::hourLabelFromKickoff($kickoff)] = true;
            }
        }
        $hours = array_keys($hours);
        sort($hours);

        return $hours;
    }

    /**
     * Uma linha por PERNA, não por partida.
     *
     * Cada partida qualificada gera até quatro entradas, uma por estratégia, porque é assim
     * que se opera: cada linha é uma aposta com seu próprio placar, sua própria odd justa e
     * seu próprio veredito. O ranking por hora é calculado na PARTIDA e depois herdado pelas
     * pernas, senão o selo de melhor da hora se repetiria quatro vezes.
     *
     * @return list<array<string, mixed>>
     */
    public function getFilteredRowsProperty(): array
    {
        $strategy = $this->strategy();

        $matches = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => $strategy->matchesProfile($row, $this->signalProfile)
                && $this->matchesFavouriteOdd($row, $strategy),
        ));

        if ($this->mode === 'upcoming') {
            if ($this->hourFilter !== 'all') {
                $matches = array_values(array_filter(
                    $matches,
                    fn (array $row): bool => is_string($row['kickoffAt'] ?? null)
                        && BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']) === $this->hourFilter,
                ));
            }

            // Maior BTTS primeiro: é o critério que discrimina, medido em 43.824 partidas.
            $matches = $this->rankUpcomingRowsByHour(
                $matches,
                fn (array $a, array $b): int => (float) ($strategy->bttsProbability($b) ?? 0) <=> (float) ($strategy->bttsProbability($a) ?? 0),
            );
        }

        $legs = $this->legFilter === 'all' ? array_keys($this->legOptions) : [$this->legFilter];
        $fair = $this->fairOdds;
        $rows = [];

        foreach ($matches as $match) {
            $base = [...$match, ...$this->decorate($match, $strategy)];
            foreach ($legs as $leg) {
                $score = $strategy->layScoreForLeg($match, $leg);
                if ($score === null) {
                    continue;
                }
                [$scoreHome, $scoreAway] = array_map('intval', explode('-', $score));
                $rows[] = [...$base,
                    'leg' => $leg,
                    'legLabel' => $this->legOptions[$leg],
                    'legSide' => AgainstFavouriteCleanSheetStrategy::LEGS[$leg]['side'],
                    'legScore' => $score,
                    // Quem vence a zero no placar laydo. O placar é sempre casa-fora, então
                    // sem o nome do time a leitura fica ambígua quando o favorito é visitante.
                    'legWinner' => $scoreHome > $scoreAway ? ($match['homeTeam'] ?? '') : ($match['awayTeam'] ?? ''),
                    'legFairOdd' => $fair[$leg] ?? null,
                    'legResult' => $strategy->resultForLeg($match, $leg),
                    'legFiltered' => AgainstFavouriteCleanSheetStrategy::BTTS_DISCRIMINATES[$leg],
                ];
            }
        }

        return $rows;
    }

    /**
     * O corte de odd do favorito, aplicado só onde ele foi medido como real.
     *
     * @param array<string, mixed> $row
     */
    private function matchesFavouriteOdd(array $row, AgainstFavouriteCleanSheetStrategy $strategy): bool
    {
        if (! $this->usesFavouriteOddFilter() || $this->favouriteOddFilter === 'all') {
            return true;
        }
        $odd = $strategy->favouriteOdd($row);
        if ($odd === null) {
            return false;
        }
        $cut = AgainstFavouriteCleanSheetStrategy::ZEBRA_FAVOURITE_ODD_CUT;

        return $this->favouriteOddFilter === 'strong' ? $odd < $cut : $odd >= $cut;
    }

    /**
     * Campos da PARTIDA que a blade mostra. O que é da perna sai em getFilteredRowsProperty.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row, AgainstFavouriteCleanSheetStrategy $strategy): array
    {
        return [
            'bttsProbability' => $strategy->bttsProbability($row),
            'bttsIsRaw' => $strategy->bttsProbabilityIsRaw($row),
            'favouriteSide' => $strategy->favourite($row),
            'favouriteOdd' => $strategy->favouriteOdd($row),
        ];
    }

    /**
     * As mesmas linhas de filteredRows, agrupadas por partida.
     *
     * A tabela mostra uma partida por bloco e uma perna por linha: horário, times, liga,
     * favorito e probabilidade valem para a partida inteira e saem uma vez só, com rowspan.
     * Sem isso a mesma partida repetia esses cinco campos quatro vezes, incluindo o selo de
     * melhor da hora, que parecia quatro oportunidades distintas quando é uma.
     *
     * @return list<array{match: array<string, mixed>, legs: list<array<string, mixed>>}>
     */
    public function getGroupedRowsProperty(): array
    {
        $groups = [];
        foreach ($this->filteredRows as $row) {
            $key = ($row['matchKey'] ?? '').'|'.($row['homeTeam'] ?? '').'|'.($row['awayTeam'] ?? '');
            $groups[$key]['match'] ??= $row;
            $groups[$key]['legs'][] = $row;
        }

        return array_values($groups);
    }

    /**
     * Agregados por perfil e por perna, medidos na base INTEIRA.
     *
     * Cacheia só o resumo, nunca as linhas: são 43.824 partidas e o resumo cabe em alguns
     * bytes. É daqui que saem a odd justa e a tabela de cortes.
     *
     * @return array<string, array<int, array{entries: int, greens: int, reds: int, hitRate: ?float, fairOdd: ?float}>>
     */
    public function getProfileStatsProperty(): array
    {
        return OraclyCache::remember(OraclyCache::key('lay-seco:stats:v2:'.$this->side().':'.$this->favouriteOddFilter), function (): array {
            $strategy = $this->strategy();
            $history = $this->settledHistory();
            $stats = [];

            foreach (array_keys(self::SIGNAL_PROFILES) as $profile) {
                $rows = array_values(array_filter(
                    $history,
                    fn (array $row): bool => $strategy->matchesProfile($row, $profile)
                        && $this->matchesFavouriteOdd($row, $strategy),
                ));
                foreach (array_keys(AgainstFavouriteCleanSheetStrategy::legsForSide($this->side())) as $leg) {
                    $summary = HitRateSummary::of($rows, fn (array $row): bool => $strategy->resultForLeg($row, $leg) === 'green');
                    $frequency = $summary['hitRate'] === null ? null : 100 - $summary['hitRate'];
                    $stats[$profile][$leg] = [...$summary, 'fairOdd' => AgainstFavouriteCleanSheetStrategy::fairLayOdd($frequency)];
                }
            }

            return $stats;
        }, 900);
    }

    /**
     * Odd justa de lay por perna, no perfil selecionado.
     *
     * É o número que decide a entrada: abaixo dela o lay tem retorno esperado positivo, acima
     * dela é prejuízo mesmo com assertividade alta. Vem sempre do histórico, inclusive quando
     * a tela está no modo lista diária.
     *
     * É um número de COORTE, não da partida: vale para o conjunto de jogos que passam no
     * perfil, não para este jogo específico. Placar exato por partida exigiria preço de
     * mercado, que não existe em nenhuma fonte do projeto.
     *
     * @return array<string, float|null>
     */
    public function getFairOddsProperty(): array
    {
        $profile = $this->profileStats[$this->signalProfile] ?? [];

        return array_map(fn (array $leg): ?float => $leg['fairOdd'], $profile);
    }

    /**
     * Tabela de cortes do modo histórico, para a perna selecionada.
     *
     * @return array<string, array{entries: int, greens: int, reds: int, hitRate: ?float, fairOdd: ?float}>
     */
    public function getCutoffStatsProperty(): array
    {
        return array_map(fn (array $legs): array => $legs[$this->offeredLeg], $this->profileStats);
    }

    /** Perfil com melhor assertividade entre os que têm amostra suficiente. @return array{profile: string, hitRate: float, fairOdd: ?float}|null */
    public function getBestCutoffProperty(): ?array
    {
        $eligible = array_filter(
            $this->cutoffStats,
            fn (array $stat): bool => $stat['entries'] >= self::MIN_SAMPLE_FOR_RECOMMENDATION && $stat['hitRate'] !== null,
        );
        if ($eligible === []) {
            return null;
        }
        uasort($eligible, fn (array $a, array $b): int => $b['hitRate'] <=> $a['hitRate']);
        $best = array_key_first($eligible);

        return ['profile' => $best, 'hitRate' => $eligible[$best]['hitRate'], 'fairOdd' => $eligible[$best]['fairOdd']];
    }

    /**
     * Veredito da odd digitada contra a odd justa da perna escolhida.
     *
     * @return array{verdict: 'enter'|'skip', fairOdd: float, offered: float, edge: float}|null
     */
    public function getOfferedVerdictProperty(): ?array
    {
        $fair = $this->fairOdds[$this->offeredLeg] ?? null;
        $offered = $this->offeredOdd;
        if ($fair === null || $offered === null || $offered <= 1.0) {
            return null;
        }

        // Lay: ganha-se 1 quando o placar não sai, perde-se (odd-1) quando sai.
        $probability = 1 / $fair;
        $expected = (1 - $probability) - $probability * ($offered - 1);

        return [
            'verdict' => $offered < $fair ? 'enter' : 'skip',
            'fairOdd' => $fair,
            'offered' => $offered,
            'edge' => $expected * 100,
        ];
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return HistoryCsv::download(
            $this->historyCsvFilename(),
            ['Data', 'Casa', 'Visitante', 'Liga', 'Estratégia', 'Favorito', 'BTTS', 'Placar laydo', 'Odd justa', 'Placar final', 'Resultado'],
            array_map(fn (array $row): array => [
                $row['matchDate'] ?? '',
                $row['homeTeam'] ?? '',
                $row['awayTeam'] ?? '',
                $row['competition'] ?? '',
                $row['legLabel'] ?? '',
                ($row['favouriteSide'] ?? null) === 'home' ? 'Casa' : 'Fora',
                $row['bttsProbability'] !== null ? number_format((float) $row['bttsProbability'], 1) : '',
                $row['legScore'] ?? '',
                $row['legFairOdd'] !== null ? number_format((float) $row['legFairOdd'], 2) : '',
                ($row['homeGoals'] ?? '—').'-'.($row['awayGoals'] ?? '—'),
                $row['legResult'] ?? '',
            ], $this->filteredRows),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->visible(fn (): bool => $this->mode === 'upcoming')->action('previousDay'),
            Action::make('exportCsv')->label('Exportar CSV')->visible(fn (): bool => $this->mode === 'history')->action('exportCsv'),
            Action::make('reload')->label('Recarregar banco')->action('refresh'),
            Action::make('next')->label('Próximo dia')->visible(fn (): bool => $this->mode === 'upcoming')->action('nextDay'),
        ];
    }
}
