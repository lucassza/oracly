<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\TodayMatchService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class TodayMatches extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Hoje';

    protected static ?string $title = 'Hoje';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.today-matches';

    /** Live/in-progress statuses, matching the old SokkerPRO "em aberto" counter. */
    public const OPEN_STATUSES = ['1st', '2nd', 'et', 'half_time', 'live'];

    /** @var array<string, string> */
    public const MARKET_TILES = [
        'gols_1t_05_over' => 'O0.5 1T',
        'gols_1t_15_over' => 'O1.5 1T',
        'over_15_ft_over' => 'O1.5 FT',
        'over_25_ft_over' => 'O2.5 FT',
        'btts_sim' => 'BTTS',
    ];

    /** Cell highlight threshold — fixed, independent of the active probability filter. */
    public const HIGHLIGHT_PROBABILITY = 80;

    /** @var array<int, string> */
    public const PROBABILITY_OPTIONS = [
        0 => 'Todos',
        60 => '≥ 60%',
        70 => '≥ 70%',
        75 => '≥ 75%',
        80 => '≥ 80%',
    ];

    public string $date = '';

    public int $minProbability = 0;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $this->rows = app(TodayMatchService::class)->forDate($this->date);
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
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
        $this->reload();
    }

    public function nextDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, 1);
        $this->reload();
    }

    public function setMinProbability(int $value): void
    {
        $this->minProbability = $value;
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        if ($this->minProbability === 0) {
            return $this->rows;
        }

        return array_values(array_filter(
            $this->rows,
            fn (array $row) => $this->rowMaxProbability($row) >= $this->minProbability,
        ));
    }

    private function rowMaxProbability(array $row): float
    {
        $max = 0.0;
        foreach (array_keys(self::MARKET_TILES) as $market) {
            $probability = $row['predictions'][$market]['probability'] ?? null;
            if ($probability !== null) {
                $max = max($max, (float) $probability);
            }
        }

        return $max;
    }

    /** @return array<string, int> */
    public function getMarketCountsProperty(): array
    {
        $filtered = $this->filteredRows;
        $counts = ['all' => count($filtered)];

        foreach (array_keys(self::MARKET_TILES) as $market) {
            $counts[$market] = count(array_filter(
                $filtered,
                fn (array $row) => ($row['predictions'][$market]['probability'] ?? null) !== null
                    && (float) $row['predictions'][$market]['probability'] >= $this->minProbability,
            ));
        }

        return $counts;
    }

    public function getOpenMatchesCountProperty(): int
    {
        return count(array_filter(
            $this->rows,
            fn (array $row) => in_array($row['status'], self::OPEN_STATUSES, true),
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->action('previousDay'),
            Action::make('reload')->label(fn () => "Atualizar ({$this->openMatchesCount} em aberto)")->action('reload'),
            Action::make('next')->label('Próximo dia')->action('nextDay'),
        ];
    }
}
