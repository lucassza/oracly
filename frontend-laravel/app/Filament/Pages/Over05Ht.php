<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\DailyPickService;
use App\Oracly\Services\PredictionService;
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

class Over05Ht extends Page
{
    use RanksHourlyOpportunities;
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'O0.5 HT';

    protected static ?string $title = 'Over 0.5 HT';

    protected static string | UnitEnum | null $navigationGroup = 'Estratégias';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.over-05-ht';

    public string $mode = 'upcoming';

    public string $date = '';

    public int $minProbability = 80;

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
        try {
            $service = app(PredictionService::class);
            $this->historyRows = $this->mode === 'history' ? $service->history('over_05_ht', 0) : [];
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
            $this->rows = $this->mode === 'history'
                ? $this->historyRows
                : $this->dailyRows();
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler previsões O0.5 HT')->body($e->getMessage())->danger()->send();
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
        $rows = array_values(array_filter($this->rows, fn (array $row): bool => (float) ($row['probability'] ?? 0) >= $this->minProbability
            && ($this->mode !== 'history' || $this->scoreFilter === 'all' || $this->scoreKey($row) === $this->scoreFilter)
            && $this->matchesFavorite($row)));
        return $this->mode === 'upcoming'
            ? $this->rankUpcomingRowsByHour($rows, fn (array $a, array $b): int => (float) ($b['probability'] ?? 0) <=> (float) ($a['probability'] ?? 0))
            : $rows;
    }

    /** @return array<int, array{entries: int, wins: int, reds: int, hitRate: ?float}> */
    public function getCutoffStatsProperty(): array
    {
        $stats = [];
        foreach (array_keys(self::THRESHOLDS) as $threshold) {
            $entries = array_filter($this->historyRows, fn (array $row): bool => (float) ($row['probability'] ?? 0) >= $threshold && $this->matchesFavorite($row));
            $count = count($entries);
            $wins = count(array_filter($entries, fn (array $row): bool => ! empty($row['hit'])));
            $stats[$threshold] = [
                'entries' => $count,
                'wins' => $wins,
                'reds' => $count - $wins,
                'hitRate' => $count > 0 ? ($wins / $count) * 100 : null,
            ];
        }

        return $stats;
    }

    /** @return array{threshold: int, entries: int, wins: int, reds: int, hitRate: float}|null */
    public function getBestCutoffProperty(): ?array
    {
        $eligible = array_filter($this->cutoffStats, fn (array $stat): bool => $stat['entries'] >= self::MIN_SAMPLE_FOR_RECOMMENDATION && $stat['hitRate'] !== null);
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
            $score = $this->scoreKey($row);
            if ($score !== null) {
                $options[$score] = str_replace('-', ' x ', $score);
            }
        }

        return $options;
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return HistoryCsv::download('historico-over-05-ht.csv', [
            'Data', 'Casa', 'Visitante', 'Liga', 'Probabilidade', 'HT', 'FT', 'Resultado',
        ], array_map(fn (array $row): array => [
            $row['kickoffAt'] ?? '', $row['homeTeam'] ?? '', $row['awayTeam'] ?? '',
            ($row['country'] ?? '').' · '.($row['competition'] ?? ''), $row['probability'] ?? '',
            ($row['halftimeHomeScore'] ?? '—').'-'.($row['halftimeAwayScore'] ?? '—'),
            ($row['homeScore'] ?? '—').'-'.($row['awayScore'] ?? '—'), ! empty($row['hit']) ? 'Green' : 'Red',
        ], $this->filteredRows));
    }

    /** @return list<array<string, mixed>> */
    private function dailyRows(): array
    {
        return array_values(array_map(function (array $row): array {
            $row['probability'] = $row['over05Ht'];

            return $row;
        }, app(DailyPickService::class)->forDate($this->date)));
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
        return $this->favoriteFilter === 0 || in_array(
            ($row['country'] ?? '').'::'.($row['competition'] ?? ''),
            $this->favoriteLeagues,
            true,
        );
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
