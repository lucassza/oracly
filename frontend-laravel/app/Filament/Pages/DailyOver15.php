<?php

namespace App\Filament\Pages;

use App\Oracly\Services\PredictionService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DailyOver15 extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Over 1.5 FT';

    protected static ?string $title = 'Over 1.5 FT';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.daily-over15';

    public string $mode = 'upcoming';

    public string $date = '';

    public int $minProbability = 60;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $historyRows = [];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<int, string> */
    public const THRESHOLDS = [
        55 => '≥ 55%',
        60 => '≥ 60%',
        65 => '≥ 65%',
        70 => '≥ 70%',
        75 => '≥ 75%',
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
            $this->historyRows = $service->history('over_15_ft', 0);
            $this->rows = $this->mode === 'history'
                ? $this->historyRows
                : array_values(array_filter(
                    $service->upcoming('over_15_ft', now()->toIso8601String(), 0),
                    fn (array $row) => ! empty($row['kickoffAt'])
                        && BrasiliaDate::fromKickoff($row['kickoffAt']) === $this->date,
                ));
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            Notification::make()->title('Erro ao ler previsões Over 1.5')->body($e->getMessage())->danger()->send();
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
        return array_values(array_filter(
            $this->rows,
            fn (array $row) => (float) ($row['probability'] ?? 0) >= $this->minProbability,
        ));
    }

    /** @return array<int, array{entries: int, wins: int, hitRate: ?float}> */
    public function getCutoffStatsProperty(): array
    {
        $stats = [];
        foreach (array_keys(self::THRESHOLDS) as $threshold) {
            $entries = array_filter($this->historyRows, fn (array $row) => (float) ($row['probability'] ?? 0) >= $threshold);
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->action('previousDay'),
            Action::make('reload')->label('Recarregar banco')->action('reload'),
            Action::make('next')->label('Próximo dia')->action('nextDay'),
        ];
    }
}
