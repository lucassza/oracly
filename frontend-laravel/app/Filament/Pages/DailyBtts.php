<?php

namespace App\Filament\Pages;

use App\Oracly\Services\DailyPickService;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DailyBtts extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Lista diária BTTS';

    protected static ?string $title = 'Lista diária BTTS';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.daily-btts';

    public string $date = '';

    public int $minProbability = 55;

    public int $favoriteFilter = 0;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    /** @var array<int, string> */
    public const PROBABILITY_OPTIONS = [
        55 => '≥ 55%',
        60 => '≥ 60%',
        65 => '≥ 65%',
        70 => '≥ 70%',
        75 => '≥ 75%',
    ];

    /** @var array<int, string> */
    public const FAVORITE_OPTIONS = [
        0 => 'Todas as ligas',
        1 => 'Somente favoritas',
    ];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $this->rows = app(DailyPickService::class)->forDate($this->date);
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
        }
    }

    public function setMinProbability(int $value): void
    {
        if (array_key_exists($value, self::PROBABILITY_OPTIONS)) {
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
        $this->reload();
    }

    public function nextDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, 1);
        $this->reload();
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (array $row) => ($row['btts'] ?? null) !== null
                && (float) $row['btts'] >= $this->minProbability
                && ($this->favoriteFilter === 0 || in_array(
                    ($row['country'] ?? '').'::'.($row['competition'] ?? ''),
                    $this->favoriteLeagues,
                    true,
                )),
        ));
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
