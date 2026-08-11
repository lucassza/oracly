<?php

namespace App\Filament\Pages;

use App\Oracly\Services\PredictionService;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class BttsHistory extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Histórico BTTS';

    protected static ?string $title = 'Histórico BTTS';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.btts-history';

    public int $minProbability = 60;

    public string $resultFilter = 'all';

    public string $scoreFilter = 'all';

    public int $favoriteFilter = 0;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    /** @var array<int, string> */
    public const THRESHOLDS = [
        55 => '≥ 55%',
        60 => '≥ 60%',
        65 => '≥ 65%',
        70 => '≥ 70%',
        75 => '≥ 75%',
    ];

    /** @var array<string, string> */
    public const RESULT_OPTIONS = [
        'all' => 'Todos os resultados',
        'hit' => 'Somente acertos',
        'miss' => 'Somente erros',
    ];

    /** @var array<int, string> */
    public const FAVORITE_OPTIONS = [
        0 => 'Todas as ligas',
        1 => 'Somente favoritas',
    ];

    /** A rate based on fewer fixtures is not a reliable recommended entry point. */
    private const MIN_SAMPLE_FOR_RECOMMENDATION = 20;

    public function mount(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $this->rows = app(PredictionService::class)->history('btts', 0);
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler histórico BTTS')->body($e->getMessage())->danger()->send();
        }
    }

    public function setMinProbability(int $value): void
    {
        if (array_key_exists($value, self::THRESHOLDS)) {
            $this->minProbability = $value;
        }
    }

    public function setResultFilter(string $value): void
    {
        if (array_key_exists($value, self::RESULT_OPTIONS)) {
            $this->resultFilter = $value;
        }
    }

    public function setFavoriteFilter(int $value): void
    {
        if (array_key_exists($value, self::FAVORITE_OPTIONS)) {
            $this->favoriteFilter = $value;
        }
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (array $row) => (float) ($row['probability'] ?? 0) >= $this->minProbability
                && ($this->resultFilter === 'all'
                    || ($this->resultFilter === 'hit' && ! empty($row['hit']))
                    || ($this->resultFilter === 'miss' && empty($row['hit'])))
                && ($this->scoreFilter === 'all' || $this->scoreKey($row) === $this->scoreFilter)
                && $this->matchesFavoriteFilter($row),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function getFavoriteRowsProperty(): array
    {
        return array_values(array_filter($this->rows, fn (array $row) => $this->matchesFavoriteFilter($row)));
    }

    /** @return array<string, string> */
    public function getScoreOptionsProperty(): array
    {
        $options = ['all' => 'Todos os placares'];
        $scores = [];
        foreach ($this->rows as $row) {
            $key = $this->scoreKey($row);
            if ($key !== null) {
                $scores[$key] = str_replace('-', ' x ', $key);
            }
        }
        uksort($scores, function (string $a, string $b): int {
            [$homeA, $awayA] = array_map('intval', explode('-', $a));
            [$homeB, $awayB] = array_map('intval', explode('-', $b));

            return ($homeA + $awayA) <=> ($homeB + $awayB) ?: strcmp($a, $b);
        });

        return $options + $scores;
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
    private function matchesFavoriteFilter(array $row): bool
    {
        return $this->favoriteFilter === 0 || in_array(
            ($row['country'] ?? '').'::'.($row['competition'] ?? ''),
            $this->favoriteLeagues,
            true,
        );
    }

    /** @return array<int, array{entries: int, wins: int, hitRate: ?float}> */
    public function getCutoffStatsProperty(): array
    {
        $stats = [];
        foreach (array_keys(self::THRESHOLDS) as $threshold) {
            $entries = array_filter(
                $this->favoriteRows,
                fn (array $row) => (float) ($row['probability'] ?? 0) >= $threshold,
            );
            $count = count($entries);
            $wins = count(array_filter($entries, fn (array $row) => ! empty($row['hit'])));
            $stats[$threshold] = [
                'entries' => $count,
                'wins' => $wins,
                'hitRate' => $count > 0 ? ($wins / $count) * 100 : null,
            ];
        }

        return $stats;
    }

    /** @return array{threshold: int, entries: int, wins: int, hitRate: ?float}|null */
    public function getBestCutoffProperty(): ?array
    {
        $eligible = array_filter(
            $this->cutoffStats,
            fn (array $stat) => $stat['entries'] >= self::MIN_SAMPLE_FOR_RECOMMENDATION && $stat['hitRate'] !== null,
        );
        if ($eligible === []) {
            return null;
        }

        $threshold = array_key_first($eligible);
        foreach ($eligible as $candidateThreshold => $stat) {
            if ($stat['hitRate'] > $eligible[$threshold]['hitRate']) {
                $threshold = $candidateThreshold;
            }
        }

        return ['threshold' => (int) $threshold, ...$eligible[$threshold]];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reload')->label('Recarregar banco')->action('reload'),
        ];
    }
}
