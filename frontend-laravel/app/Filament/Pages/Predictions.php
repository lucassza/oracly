<?php

namespace App\Filament\Pages;

use App\Oracly\Services\PredictionService;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\HistoryCsv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Predictions extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Previsões';

    protected static ?string $title = 'Previsões';

    protected static string | UnitEnum | null $navigationGroup = 'Mercados';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.predictions';

    /** @var array<int, string> */
    public const CONFIDENCE_THRESHOLDS = [70, 75, 80, 85, 90];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Próximos jogos',
        'history' => 'Histórico',
    ];

    /** @var array<int, string> */
    public const FAVORITE_OPTIONS = [
        0 => 'Todas as ligas',
        1 => 'Somente favoritas',
    ];

    public string $market = 'over_05_ht';

    public string $mode = 'upcoming';

    public int $minProbability = 80;

    public string $scoreFilter = 'all';

    public int $favoriteFilter = 0;

    /** Unfiltered rows for the current market/mode, fetched once and filtered client-side per threshold. */
    public array $allRows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    public function mount(): void
    {
        $this->reload();
    }

    public function setMarket(string $value): void
    {
        $this->market = $value;
        $this->reload();
    }

    public function setMode(string $value): void
    {
        $this->mode = $value;
        $this->reload();
    }

    public function setMinProbability(int $value): void
    {
        $this->minProbability = $value;
    }

    public function setFavoriteFilter(int $value): void
    {
        if (array_key_exists($value, self::FAVORITE_OPTIONS)) {
            $this->favoriteFilter = $value;
        }
    }

    /** @return array<string, string> */
    public function getScoreOptionsProperty(): array
    {
        $options = ['all' => 'Todos os placares'];
        foreach ($this->allRows as $row) {
            $key = $this->scoreKey($row);
            if ($key !== null) {
                $options[$key] = str_replace('-', ' x ', $key);
            }
        }

        return $options;
    }

    public function reload(): void
    {
        try {
            $service = app(PredictionService::class);
            $this->allRows = $this->mode === 'history'
                ? $service->history($this->market, 0)
                : $service->upcoming($this->market, now()->toIso8601String(), 0);
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        } catch (\Throwable $e) {
            $this->allRows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
        }
    }

    public function refresh(): void
    {
        OraclyCache::forgetPrefix();
        $this->reload();
    }

    /** @return list<array<string, mixed>> */
    public function getRowsProperty(): array
    {
        $rows = $this->filterByProbability($this->allRows, $this->minProbability);
        if ($this->favoriteFilter === 1) {
            $rows = array_values(array_filter($rows, fn (array $row) => in_array(
                ($row['country'] ?? '').'::'.($row['competition'] ?? ''),
                $this->favoriteLeagues,
                true,
            )));
        }

        return $this->mode === 'history' && $this->scoreFilter !== 'all'
            ? array_values(array_filter($rows, fn (array $row) => $this->scoreKey($row) === $this->scoreFilter))
            : $rows;
    }

    /** @param array<string, mixed> $row */
    private function scoreKey(array $row): ?string
    {
        if (! is_numeric($row['homeScore'] ?? null) || ! is_numeric($row['awayScore'] ?? null)) {
            return null;
        }

        return sprintf('%d-%d', (int) $row['homeScore'], (int) $row['awayScore']);
    }

    /** @return list<array<string, mixed>> */
    private function filterByProbability(array $rows, float $minProbability): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row) => ($row['probability'] ?? 0) >= $minProbability,
        ));
    }

    /** @return array{sampleSize: int, entries: int, wins: ?int, coverage: float, hitRate: ?float} */
    public function getStatsProperty(): array
    {
        $sampleSize = count($this->allRows);
        $entries = count($this->rows);
        $wins = $this->mode === 'history' ? count(array_filter($this->rows, fn ($r) => ! empty($r['hit']))) : null;

        return [
            'sampleSize' => $sampleSize,
            'entries' => $entries,
            'wins' => $wins,
            'coverage' => $sampleSize > 0 ? ($entries / $sampleSize) * 100 : 0.0,
            'hitRate' => ($wins !== null && $entries > 0) ? ($wins / $entries) * 100 : null,
        ];
    }

    /** @return array<int, array{entries: int, coverage: float, hitRate: ?float}> */
    public function getConfidenceLineProperty(): array
    {
        $sampleSize = count($this->allRows);
        $line = [];

        foreach (self::CONFIDENCE_THRESHOLDS as $threshold) {
            $filtered = $this->filterByProbability($this->allRows, $threshold);
            $entries = count($filtered);
            $wins = $this->mode === 'history' ? count(array_filter($filtered, fn ($r) => ! empty($r['hit']))) : null;

            $line[$threshold] = [
                'entries' => $entries,
                'coverage' => $sampleSize > 0 ? ($entries / $sampleSize) * 100 : 0.0,
                'hitRate' => ($wins !== null && $entries > 0) ? ($wins / $entries) * 100 : null,
            ];
        }

        return $line;
    }

    /** @return array<string, array{label: string, hitRate: ?float, active: bool}> */
    public function getMarketSummaryProperty(): array
    {
        if ($this->mode !== 'history') {
            return [];
        }

        $service = app(PredictionService::class);
        $summary = [];

        foreach (PredictionService::MARKETS as $key => $meta) {
            if ($key === $this->market) {
                $summary[$key] = [
                    'label' => $meta['label'],
                    'hitRate' => $this->stats['hitRate'],
                    'active' => true,
                ];

                continue;
            }

            try {
                $rows = $service->history($key, (float) $this->minProbability);
                $hits = count(array_filter($rows, fn ($r) => ! empty($r['hit'])));
                $hitRate = count($rows) > 0 ? ($hits / count($rows)) * 100 : null;
            } catch (\Throwable) {
                $hitRate = null;
            }

            $summary[$key] = [
                'label' => $meta['label'],
                'hitRate' => $hitRate,
                'active' => false,
            ];
        }

        return $summary;
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return HistoryCsv::download('historico-'.$this->market.'.csv', [
            'Data', 'Casa', 'Visitante', 'Liga', 'Probabilidade', 'HT', 'FT', 'Acerto',
        ], array_map(fn (array $row) => [
            $row['kickoffAt'] ?? '', $row['homeTeam'] ?? '', $row['awayTeam'] ?? '',
            ($row['country'] ?? '').' · '.($row['competition'] ?? ''), $row['probability'] ?? '',
            ($row['halftimeHomeScore'] ?? '—').'-'.($row['halftimeAwayScore'] ?? '—'),
            ($row['homeScore'] ?? '—').'-'.($row['awayScore'] ?? '—'), ! empty($row['hit']) ? 'Green' : 'Red',
        ], $this->rows));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label('Exportar CSV')->visible(fn (): bool => $this->mode === 'history')->action('exportCsv'),
            Action::make('reload')->label('Recarregar banco')->action('refresh'),
        ];
    }
}
