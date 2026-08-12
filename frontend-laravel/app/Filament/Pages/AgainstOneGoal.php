<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FavoritesService;
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

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.against-one-goal';

    public string $mode = 'upcoming';

    public string $date = '';

    public int $minProbability = 75;

    public int $favoriteFilter = 0;

    public string $scoreFilter = 'all';

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

    private const MIN_SAMPLE_FOR_RECOMMENDATION = 20;

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $service = app(PredictionService::class);
            $this->historyRows = $this->withAgainstResult($service->history('over_15_ft', 0));
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
            $this->rows = $this->mode === 'history'
                ? $this->historyRows
                : $this->withAgainstResult(array_values(array_filter(
                    $service->upcoming('over_15_ft', now()->toIso8601String(), 0),
                    fn (array $row) => ! empty($row['kickoffAt']) && BrasiliaDate::fromKickoff($row['kickoffAt']) === $this->date,
                )));
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler estratégia contra 0x1/1x0')->body($e->getMessage())->danger()->send();
        }
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
            && ($this->mode !== 'history' || $this->scoreFilter === 'all' || $this->scoreKey($row) === $this->scoreFilter)
            && ($this->favoriteFilter === 0 || in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $this->favoriteLeagues, true))));
    }

    /** @return array<int, array{entries: int, wins: int, reds: int, hitRate: ?float}> */
    public function getCutoffStatsProperty(): array
    {
        $stats = [];
        foreach (array_keys(self::THRESHOLDS) as $threshold) {
            $entries = array_filter($this->historyRows, fn (array $row) => (float) ($row['probability'] ?? 0) >= $threshold
                && $row['bestAgainstScore'] !== null
                && $this->matchesFavorite($row));
            $count = count($entries);
            $wins = count(array_filter($entries, fn (array $row) => ! empty($row['againstHit'])));
            $reds = $count - $wins;
            $stats[$threshold] = ['entries' => $count, 'wins' => $wins, 'reds' => $reds, 'hitRate' => $count > 0 ? ($wins / $count) * 100 : null];
        }

        return $stats;
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

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return HistoryCsv::download('historico-contra-0x1-ou-1x0.csv', [
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
            $row['againstHit'] = $choice !== null && $actual !== null ? $actual !== $choice['score'] : null;

            return $row;
        }, $rows);
    }

    /** @return array{score: string, probability: float}|null */
    private function bestAgainstChoice(array $row): ?array
    {
        $homeAverage = is_numeric($row['homeGoalsAverage'] ?? null) ? (float) $row['homeGoalsAverage'] : null;
        $awayAverage = is_numeric($row['awayGoalsAverage'] ?? null) ? (float) $row['awayGoalsAverage'] : null;
        if ($homeAverage === null || $awayAverage === null) {
            return null;
        }

        $homeAverage = max(0.08, $homeAverage);
        $awayAverage = max(0.08, $awayAverage);
        $zeroOne = $this->poisson($homeAverage, 0) * $this->poisson($awayAverage, 1);
        $oneZero = $this->poisson($homeAverage, 1) * $this->poisson($awayAverage, 0);

        return $zeroOne <= $oneZero
            ? ['score' => '0-1', 'probability' => $zeroOne]
            : ['score' => '1-0', 'probability' => $oneZero];
    }

    private function poisson(float $average, int $goals): float
    {
        return exp(-$average) * ($average ** $goals);
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
    private function matchesFavorite(array $row): bool
    {
        return $this->favoriteFilter === 0 || in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $this->favoriteLeagues, true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->action('previousDay'),
            Action::make('exportCsv')->label('Exportar CSV')->visible(fn (): bool => $this->mode === 'history')->action('exportCsv'),
            Action::make('reload')->label('Recarregar banco')->action('reload'),
            Action::make('next')->label('Próximo dia')->action('nextDay'),
        ];
    }
}
