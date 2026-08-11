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

class DailyList extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Lista diária';

    protected static ?string $title = 'Lista diária';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.daily-list';

    public string $date = '';

    public int $pageNumber = 1;

    public int $pageSize = 20;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    public int $favoriteFilter = 0;

    public int $minProbability = 0;

    /** @var array<int, string> */
    public const FAVORITE_OPTIONS = [
        0 => 'Todas as ligas',
        1 => 'Somente favoritos',
    ];

    /** @var array<int, string> */
    public const PROBABILITY_OPTIONS = [
        0 => 'Todas',
        60 => '≥ 60%',
        70 => '≥ 70%',
        80 => '≥ 80%',
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
            $this->pageNumber = 1;
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

    public function setFavoriteFilter(int $value): void
    {
        $this->favoriteFilter = $value;
        $this->pageNumber = 1;
    }

    public function setMinProbability(int $value): void
    {
        $this->minProbability = $value;
        $this->pageNumber = 1;
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
    public function getPagedRowsProperty(): array
    {
        $offset = ($this->pageNumber - 1) * $this->pageSize;

        return array_slice($this->filteredRows, $offset, $this->pageSize);
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        return array_values(array_filter($this->rows, function (array $row): bool {
            if ($this->favoriteFilter === 1) {
                $key = ($row['country'] ?? '').'::'.($row['competition'] ?? '');
                if (! in_array($key, $this->favoriteLeagues, true)) {
                    return false;
                }
            }

            if ($this->minProbability > 0 && $this->maxProbability($row) < $this->minProbability) {
                return false;
            }

            return true;
        }));
    }

    public function getTotalPagesProperty(): int
    {
        return max(1, (int) ceil(count($this->filteredRows) / $this->pageSize));
    }

    /** @param array<string, mixed> $row */
    private function maxProbability(array $row): float
    {
        return max(array_map(
            fn (string $market): float => (float) ($row[$market] ?? 0),
            ['over05', 'over15', 'over25', 'under35'],
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
