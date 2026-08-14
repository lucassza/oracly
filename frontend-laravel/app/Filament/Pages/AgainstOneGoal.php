<?php

namespace App\Filament\Pages;

use App\Oracly\Services\DailyPickService;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\PredictionService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\HistoryCsv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AgainstOneGoal extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Contra 0x1 ou 1x0';

    protected static ?string $title = 'Contra 0x1 ou 1x0';

    protected static string | UnitEnum | null $navigationGroup = 'Estratégias';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.against-one-goal';

    public string $mode = 'upcoming';

    public string $date = '';

    public int $minProbability = 75;

    public int $favoriteFilter = 0;

    public string $scoreFilter = 'all';

    public string $signalProfile = 'baseline';

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

    /** @var array<int, string> */
    public const THRESHOLDS = [
        60 => '≥ 60%',
        65 => '≥ 65%',
        70 => '≥ 70%',
        75 => '≥ 75%',
        80 => '≥ 80%',
        85 => '≥ 85%',
    ];

    /** @var array<int, string> */
    public const FAVORITE_OPTIONS = [
        0 => 'Todas as ligas',
        1 => 'Somente favoritas',
    ];

    /** @var array<string, string> */
    public const SIGNAL_PROFILES = AgainstOneGoalStrategy::PROFILES;

    private const MIN_SAMPLE_FOR_RECOMMENDATION = 20;

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function reload(): void
    {
        try {
            $service = app(PredictionService::class);
            $this->historyRows = $this->mode === 'history'
                ? $this->withAgainstResult($service->history('over_15_ft', 0))
                : [];
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
            $this->rows = $this->mode === 'history'
                ? $this->historyRows
                : $this->withAgainstResult($this->dailyRows());
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler estratégia contra '.$this->strategyScoresLabel)->body($e->getMessage())->danger()->send();
        }
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

    public function setMinProbability(int $value): void
    {
        if (array_key_exists($value, self::THRESHOLDS)) {
            $this->minProbability = $value;
        }
    }

    public function setFavoriteFilter(int $value): void
    {
        if (array_key_exists($value, self::FAVORITE_OPTIONS)) {
            $this->favoriteFilter = $value;
        }
    }

    public function setSignalProfile(string $value): void
    {
        if (array_key_exists($value, self::SIGNAL_PROFILES)) {
            $this->signalProfile = $value;
        }
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

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        return array_values(array_filter($this->rows, fn (array $row) => (float) ($row['probability'] ?? 0) >= $this->minProbability
            && $this->strategy()->matchesProfile($row, $this->signalProfile)
            && ($this->mode !== 'history' || $this->scoreFilter === 'all' || $this->scoreKey($row) === $this->scoreFilter)
            && ($this->favoriteFilter === 0 || in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $this->favoriteLeagues, true))));
    }

    /** @return array<int, array{entries: int, wins: int, reds: int, hitRate: ?float, htEntries: int, htWins: int, htReds: int, htHitRate: ?float}> */
    public function getCutoffStatsProperty(): array
    {
        return $this->buildCutoffStats($this->historyRows);
    }

    /** @return array<string, array<int, array{entries: int, wins: int, reds: int, hitRate: ?float, htEntries: int, htWins: int, htReds: int, htHitRate: ?float}>> */
    public function getCutoffStatsByMethodProperty(): array
    {
        $stats = [];
        foreach (array_keys($this->methodLabels()) as $method) {
            $stats[$method] = $this->buildCutoffStats(array_values(array_filter(
                $this->historyRows,
                fn (array $row): bool => ($row['bestAgainstScore'] ?? null) === $method,
            )));
        }

        return $stats;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<int, array{entries: int, wins: int, reds: int, hitRate: ?float, htEntries: int, htWins: int, htReds: int, htHitRate: ?float}>
     */
    private function buildCutoffStats(array $rows): array
    {
        $stats = [];
        foreach (array_keys(self::THRESHOLDS) as $threshold) {
            $entries = array_filter($rows, fn (array $row) => (float) ($row['probability'] ?? 0) >= $threshold
                && $row['bestAgainstScore'] !== null
                && $this->strategy()->matchesProfile($row, $this->signalProfile)
                && $this->matchesFavorite($row));
            $count = count($entries);
            $wins = count(array_filter($entries, fn (array $row) => ! empty($row['againstHit'])));
            $reds = $count - $wins;
            $htEntries = array_filter($entries, fn (array $row) => ($row['againstHtHit'] ?? null) !== null);
            $htCount = count($htEntries);
            $htWins = count(array_filter($htEntries, fn (array $row) => ! empty($row['againstHtHit'])));
            $stats[$threshold] = [
                'entries' => $count,
                'wins' => $wins,
                'reds' => $reds,
                'hitRate' => $count > 0 ? ($wins / $count) * 100 : null,
                'htEntries' => $htCount,
                'htWins' => $htWins,
                'htReds' => $htCount - $htWins,
                'htHitRate' => $htCount > 0 ? ($htWins / $htCount) * 100 : null,
            ];
        }

        return $stats;
    }

    /** @return array<string, array<string, int>> */
    public function getHtResultsByMethodProperty(): array
    {
        $counts = [];
        foreach (array_keys($this->methodLabels()) as $method) {
            $counts[$method] = array_fill_keys(array_keys($this->halftimeScoreLabels()), 0);
        }

        foreach ($this->historyRows as $row) {
            if ((float) ($row['probability'] ?? 0) < $this->minProbability
                || ! isset($counts[$row['bestAgainstScore'] ?? ''])
                || ! $this->strategy()->matchesProfile($row, $this->signalProfile)
                || ! $this->matchesFavorite($row)) {
                continue;
            }

            $score = $this->halftimeScoreKey($row);
            if ($score !== null && isset($counts[$row['bestAgainstScore']][$score])) {
                $counts[$row['bestAgainstScore']][$score]++;
            }
        }

        return $counts;
    }

    /** @return array{threshold: int, entries: int, wins: int, hitRate: float}|null */
    public function getBestCutoffProperty(): ?array
    {
        $eligible = array_filter($this->cutoffStats, fn (array $stat) => $stat['entries'] >= self::MIN_SAMPLE_FOR_RECOMMENDATION && $stat['hitRate'] !== null);
        if ($eligible === []) {
            return null;
        }
        $threshold = array_key_first($eligible);
        foreach ($eligible as $candidate => $stat) {
            if ($stat['hitRate'] > $eligible[$threshold]['hitRate']) {
                $threshold = $candidate;
            }
        }

        return ['threshold' => (int) $threshold, ...$eligible[$threshold]];
    }

    /**
     * Keeps the latest 30% of completed matches out of development metrics.
     * @return array{development: array{entries: int, wins: int, hitRate: ?float}, validation: array{entries: int, wins: int, hitRate: ?float}}|null
     */
    public function getTemporalValidationProperty(): ?array
    {
        $rows = array_values(array_filter($this->historyRows, fn (array $row): bool => $row['bestAgainstScore'] !== null
            && $row['againstHit'] !== null
            && $this->matchesFavorite($row)));
        usort($rows, fn (array $a, array $b): int => strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));
        if (count($rows) < 2) {
            return null;
        }
        $split = max(1, (int) floor(count($rows) * 0.7));
        $eligible = fn (array $row): bool => (float) ($row['probability'] ?? 0) >= $this->minProbability
            && $this->strategy()->matchesProfile($row, $this->signalProfile);

        return [
            'development' => $this->summarize(array_filter(array_slice($rows, 0, $split), $eligible)),
            'validation' => $this->summarize(array_filter(array_slice($rows, $split), $eligible)),
        ];
    }

    /** @return array<string, string> */
    public function getScoreOptionsProperty(): array
    {
        $options = ['all' => 'Todos os placares'];
        foreach ($this->historyRows as $row) {
            $key = $this->scoreKey($row);
            if ($key !== null) {
                $options[$key] = str_replace('-', ' x ', $key);
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public function getMethodLabelsProperty(): array
    {
        return $this->methodLabels();
    }

    /** @return array<string, string> */
    public function getHalftimeScoreLabelsProperty(): array
    {
        return $this->halftimeScoreLabels();
    }

    public function getStrategyScoresLabelProperty(): string
    {
        return '0x1 ou 1x0';
    }

    public function getStrategyDescriptionProperty(): string
    {
        return 'Para cada jogo, escolhemos o menos provável entre 0x1 e 1x0 pelas médias de gols pré-jogo. Os cards medem separadamente o resultado no FT e no HT.';
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return HistoryCsv::download($this->historyCsvFilename(), [
            'Data', 'Casa', 'Visitante', 'Liga', 'Odd casa', 'Odd empate', 'Odd visitante', 'O1.5', 'O2.5', 'BTTS', 'Média gols', 'Melhor escolha', 'HT', 'FT', 'Resultado',
        ], array_map(fn (array $row) => [
            $row['kickoffAt'] ?? '', $row['homeTeam'] ?? '', $row['awayTeam'] ?? '',
            ($row['country'] ?? '').' · '.($row['competition'] ?? ''), $row['homeOdd'] ?? '', $row['drawOdd'] ?? '', $row['awayOdd'] ?? '', $row['probability'] ?? '',
            $row['over25Probability'] ?? '', $row['bttsProbability'] ?? '', $row['combinedGoalsAverage'] ?? '',
            $row['bestAgainstScore'] ?? '—',
            ($row['halftimeHomeScore'] ?? '—').'-'.($row['halftimeAwayScore'] ?? '—'),
            ($row['homeScore'] ?? '—').'-'.($row['awayScore'] ?? '—'), $row['againstHit'] === null ? '—' : (! empty($row['againstHit']) ? 'Green' : 'Red'),
        ], $this->filteredRows));
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withAgainstResult(array $rows): array
    {
        return array_map(function (array $row): array {
            $choice = $this->bestAgainstChoice($row);
            $row['bestAgainstScore'] = $choice['score'] ?? null;
            $row['bestAgainstProbability'] = $choice['probability'] ?? null;
            $actual = $this->scoreKey($row);
            $actualHt = $this->halftimeScoreKey($row);
            $row['againstHit'] = $choice !== null && $actual !== null ? $actual !== $choice['score'] : null;
            $row['againstHtHit'] = $choice !== null && $actualHt !== null ? $actualHt !== $choice['score'] : null;

            return $row;
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function dailyRows(): array
    {
        return array_values(array_map(function (array $row): array {
            $row['probability'] = $row['over15'];
            $row['over25Probability'] = $row['over25'];
            $row['bttsProbability'] = $row['btts'];

            return $row;
        }, app(DailyPickService::class)->forDate($this->date)));
    }

    /** @return array{score: string, probability: float}|null */
    private function bestAgainstChoice(array $row): ?array
    {
        $choice = $this->strategy()->choice($row);

        return $choice === null ? null : ['score' => $choice['score'], 'probability' => $choice['probability']];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{entries: int, wins: int, hitRate: ?float}
     */
    private function summarize(array $rows): array
    {
        $entries = count($rows);
        $wins = count(array_filter($rows, fn (array $row): bool => ! empty($row['againstHit'])));

        return ['entries' => $entries, 'wins' => $wins, 'hitRate' => $entries > 0 ? ($wins / $entries) * 100 : null];
    }

    protected function strategy(): AgainstOneGoalStrategy
    {
        return app(AgainstOneGoalStrategy::class);
    }

    /** @return array<string, string> */
    protected function methodLabels(): array
    {
        return ['1-0' => 'Contra 1x0', '0-1' => 'Contra 0x1'];
    }

    /** @return array<string, string> */
    protected function halftimeScoreLabels(): array
    {
        return ['1-0' => 'HT 1x0', '0-1' => 'HT 0x1', '0-0' => 'HT 0x0'];
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-contra-0x1-ou-1x0.csv';
    }

    /** @param array<string, mixed> $row */
    private function scoreKey(array $row): ?string
    {
        if (! is_numeric($row['homeScore'] ?? null) || ! is_numeric($row['awayScore'] ?? null)) {
            return null;
        }

        return sprintf('%d-%d', (int) $row['homeScore'], (int) $row['awayScore']);
    }

    /** @param array<string, mixed> $row */
    private function halftimeScoreKey(array $row): ?string
    {
        if (! is_numeric($row['halftimeHomeScore'] ?? null) || ! is_numeric($row['halftimeAwayScore'] ?? null)) {
            return null;
        }

        return sprintf('%d-%d', (int) $row['halftimeHomeScore'], (int) $row['halftimeAwayScore']);
    }

    /** @param array<string, mixed> $row */
    private function matchesFavorite(array $row): bool
    {
        return $this->favoriteFilter === 0 || in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $this->favoriteLeagues, true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->action('previousDay'),
            Action::make('exportCsv')->label('Exportar CSV')->visible(fn (): bool => $this->mode === 'history')->action('exportCsv'),
            Action::make('reload')->label('Recarregar banco')->action('refresh'),
            Action::make('next')->label('Próximo dia')->action('nextDay'),
        ];
    }
}
