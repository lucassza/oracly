<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\Over15ValueStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\HistoryCsv;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\RanksHourlyOpportunities;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Over 1,5 FT com valor, para operar na exchange.
 *
 * Antes lia o SokkerPRO (fora do ar desde 2026-09-02) e ranqueava por assertividade, sem
 * preço. Over 1,5 acerta muito e paga pouco: acertar 85% só empata a odd 1,18. A tela agora
 * gira em torno da ODD MÍNIMA DE ENTRADA de cada jogo — a menor odd com retorno esperado ≥ 0
 * já descontados os 6,5% de comissão — e do veredito contra a odd que a exchange oferece.
 *
 * Fonte única: Punter. Lista do dia de panel_fixtures (odd de abertura), histórico de
 * match_history (odd de fechamento, com os dois lados do mercado). Regras e números medidos em
 * Over15ValueStrategy e punter:backtest-over15.
 */
class DailyOver15 extends Page
{
    use RanksHourlyOpportunities;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Melhores entradas O1.5';

    protected static ?string $title = 'Over 1.5 FT com valor';

    protected static string | UnitEnum | null $navigationGroup = 'Estratégias';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.daily-over15';

    /** Mesmo corte do LayPlacarSeco: o Livewire serializa toda propriedade pública. */
    private const HISTORY_DISPLAY_LIMIT = 500;

    /** Início do período de validação do backtest; a tela mostra o ROI dele separado. */
    public const VALIDATION_FROM = '2025-07-01';

    public string $mode = 'upcoming';

    public string $date = '';

    public string $profile = 'balanced';

    public string $hourFilter = 'all';

    /**
     * Odd que a exchange está pagando, digitada por jogo.
     *
     * @var array<string, float|string|null> chave = rowId()
     */
    public array $offeredOdds = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<string, string> */
    public const PROFILES = Over15ValueStrategy::PROFILES;

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function reload(): void
    {
        try {
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
            $this->rows = $this->mode === 'history' ? [] : $this->upcomingRows();

            if ($this->mode === 'upcoming' && $this->hourFilter !== 'all' && ! in_array($this->hourFilter, $this->hours, true)) {
                $this->hourFilter = 'all';
            }
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler a base Punter')->body($e->getMessage())->danger()->send();
        }
    }

    public function strategy(): Over15ValueStrategy
    {
        return app(Over15ValueStrategy::class);
    }

    /**
     * Jogos do dia com odd de over 1,5, já decorados com o edge atual da liga.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingRows(): array
    {
        $strategy = $this->strategy();
        $edges = $this->currentLeagueEdges();

        $rows = [];
        foreach (app(PunterMatchPickService::class)->upcoming($this->date) as $row) {
            if ($strategy->fairProbability($row) === null) {
                continue;
            }
            $rows[] = [...$row, ...$this->decorate($row, $edges[$row['competition'] ?? ''] ?? null)];
        }

        return $rows;
    }

    /**
     * Histórico apurado, ascendente, com o edge da liga de cada jogo calculado só com os jogos
     * anteriores — o mesmo que o backtest mede. Guarda só os campos que a tela usa.
     *
     * @return list<array<string, mixed>>
     */
    private function settledHistory(): array
    {
        return OraclyCache::remember(OraclyCache::key('over15-value:history:v1'), function (): array {
            $strategy = $this->strategy();
            $rows = array_values(array_filter(
                app(PunterMatchPickService::class)->history(60000),
                fn (array $row): bool => $strategy->result($row) !== null && $strategy->fairProbability($row) !== null,
            ));
            usort($rows, fn (array $a, array $b): int => ($a['matchDate'] ?? '') <=> ($b['matchDate'] ?? ''));

            $edges = $strategy->rollingLeagueEdges($rows);
            $slim = [];
            foreach ($rows as $index => $row) {
                $base = array_intersect_key($row, array_flip([
                    'matchKey', 'matchDate', 'homeTeam', 'awayTeam', 'competition',
                    'oddOver15', 'oddUnder15', 'homeGoals', 'awayGoals',
                ]));
                $slim[] = [...$base, ...$this->decorate($base, $edges[$index])];
            }

            return $slim;
        }, 900);
    }

    /** @return array<string, array{entries: int, edge: ?float, shrunkEdge: ?float}> */
    private function currentLeagueEdges(): array
    {
        return OraclyCache::remember(OraclyCache::key('over15-value:league-edges:v1'), function (): array {
            return $this->strategy()->leagueEdges(app(PunterMatchPickService::class)->history(60000));
        }, 900);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{entries: int, edge: ?float, shrunkEdge: ?float}|null $edge
     * @return array<string, mixed>
     */
    private function decorate(array $row, ?array $edge): array
    {
        $strategy = $this->strategy();
        $probability = $strategy->probability($row, $edge);
        $minEntry = Over15ValueStrategy::minEntryOdd($probability);

        return [
            'rowId' => substr(md5(($row['matchKey'] ?? '').'|'.($row['matchDate'] ?? '')), 0, 12),
            'leagueEdge' => $edge,
            'fairOdd' => $strategy->fairOdd($row),
            'probability' => $probability,
            'minEntryOdd' => $minEntry,
            // Folga da odd de abertura (com margem) sobre a mínima. Negativa é o normal: a casa
            // cobra ~7%. Serve para ordenar, não para entrar — quem decide é a odd da exchange.
            'openingSlack' => $minEntry === null || ! is_numeric($row['oddOver15'] ?? null) ? null : (float) $row['oddOver15'] / $minEntry - 1,
        ];
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

    public function setProfile(string $value): void
    {
        if (array_key_exists($value, self::PROFILES)) {
            $this->profile = $value;
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
        $this->reload();
    }

    public function nextDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, 1);
        $this->reload();
    }

    /** @return array<string, string> */
    public function getHourOptionsProperty(): array
    {
        return ['all' => 'Todos os horários'] + array_combine($this->hours, $this->hours);
    }

    /** @return list<string> */
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

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        $strategy = $this->strategy();

        if ($this->mode === 'history') {
            $rows = array_values(array_filter(
                $this->settledHistory(),
                fn (array $row): bool => $strategy->matchesProfile($row, $this->profile, $row['leagueEdge']),
            ));

            return array_reverse(array_slice($rows, -self::HISTORY_DISPLAY_LIMIT));
        }

        $rows = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => $strategy->matchesProfile($row, $this->profile, $row['leagueEdge'])
                && ($this->hourFilter === 'all' || (is_string($row['kickoffAt'] ?? null)
                    && BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']) === $this->hourFilter)),
        ));

        return $this->rankUpcomingRowsByHour(
            $rows,
            fn (array $a, array $b): int => ($b['openingSlack'] ?? -INF) <=> ($a['openingSlack'] ?? -INF),
        );
    }

    /**
     * Veredito da odd digitada para um jogo.
     *
     * @param array<string, mixed> $row
     * @return array{verdict: 'enter'|'skip', offered: float, minEntryOdd: float, edge: float}|null
     */
    public function verdictFor(array $row): ?array
    {
        $offered = $this->offeredOdds[$row['rowId']] ?? null;
        $offered = is_string($offered) ? str_replace(',', '.', $offered) : $offered;
        $probability = $row['probability'] ?? null;
        $minEntry = $row['minEntryOdd'] ?? null;
        if (! is_numeric($offered) || (float) $offered <= 1.0 || $probability === null || $minEntry === null) {
            return null;
        }
        $offered = (float) $offered;

        return [
            'verdict' => $offered >= $minEntry ? 'enter' : 'skip',
            'offered' => $offered,
            'minEntryOdd' => $minEntry,
            'edge' => Over15ValueStrategy::expectedValue($probability, $offered) * 100,
        ];
    }

    /**
     * Resultado simulado de cada perfil na base inteira e no período de validação, pagando a
     * odd justa e 1% abaixo dela.
     *
     * @return array<string, array<string, array{entries: int, hitRate: ?float, roi: ?float, roiDiscount: ?float}>>
     */
    public function getProfileStatsProperty(): array
    {
        return OraclyCache::remember(OraclyCache::key('over15-value:stats:v1'), function (): array {
            $strategy = $this->strategy();
            $history = $this->settledHistory();
            $stats = [];

            foreach (array_keys(self::PROFILES) as $profile) {
                $rows = array_values(array_filter($history, fn (array $row): bool => $strategy->matchesProfile($row, $profile, $row['leagueEdge'])));
                $stats[$profile] = [
                    'all' => $this->summarize($rows),
                    'validation' => $this->summarize(array_values(array_filter($rows, fn (array $row): bool => $row['matchDate'] >= self::VALIDATION_FROM))),
                ];
            }

            return $stats;
        }, 900);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{entries: int, hitRate: ?float, roi: ?float, roiDiscount: ?float}
     */
    private function summarize(array $rows): array
    {
        $strategy = $this->strategy();
        $n = count($rows);
        if ($n === 0) {
            return ['entries' => 0, 'hitRate' => null, 'roi' => null, 'roiDiscount' => null];
        }
        $greens = 0;
        $fair = 0.0;
        $discount = 0.0;
        foreach ($rows as $row) {
            $greens += $strategy->result($row) === 'green' ? 1 : 0;
            $fair += $strategy->settledReturn($row, $row['fairOdd']);
            $discount += $strategy->settledReturn($row, $row['fairOdd'] * 0.99);
        }

        return [
            'entries' => $n,
            'hitRate' => $greens / $n * 100,
            'roi' => $fair / $n * 100,
            'roiDiscount' => $discount / $n * 100,
        ];
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $strategy = $this->strategy();

        return HistoryCsv::download(
            'historico-over-15-ft-valor.csv',
            ['Data', 'Casa', 'Visitante', 'Liga', 'Odd fechamento', 'Odd justa', 'Probabilidade', 'Edge liga (pp)', 'Odd mínima', 'Placar', 'Resultado', 'Retorno à justa'],
            array_map(fn (array $row): array => [
                $row['matchDate'] ?? '',
                $row['homeTeam'] ?? '',
                $row['awayTeam'] ?? '',
                $row['competition'] ?? '',
                number_format((float) $row['oddOver15'], 2),
                number_format((float) $row['fairOdd'], 2),
                number_format((float) $row['probability'] * 100, 1),
                ($row['leagueEdge']['edge'] ?? null) === null ? '' : number_format($row['leagueEdge']['edge'] * 100, 1),
                number_format((float) $row['minEntryOdd'], 2),
                ($row['homeGoals'] ?? '—').'-'.($row['awayGoals'] ?? '—'),
                $strategy->result($row) ?? '',
                number_format((float) $strategy->settledReturn($row, $row['fairOdd']), 3),
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
