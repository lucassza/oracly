<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FavoritesService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Favorites extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Favoritos';

    protected static ?string $title = 'Favoritos';

    protected static string | UnitEnum | null $navigationGroup = 'Configuração';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.favorites';

    public string $search = '';

    /** @var array{countries: list<string>, leagues: list<string>} */
    public array $favorites = ['countries' => [], 'leagues' => []];

    /** @var list<array{country: string, competition: string, isTopFlight: bool, division: ?string}> */
    public array $leagues = [];

    public function mount(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        try {
            $service = app(FavoritesService::class);
            $this->favorites = $service->get();
            $this->leagues = $service->knownLeagues();
        } catch (\Throwable $e) {
            $this->favorites = ['countries' => [], 'leagues' => []];
            $this->leagues = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
        }
    }

    public function toggleCountry(string $country): void
    {
        app(FavoritesService::class)->toggleCountry($country);
        $this->reload();
    }

    public function toggleLeague(string $country, string $competition): void
    {
        app(FavoritesService::class)->toggleLeague($country, $competition);
        $this->reload();
    }

    /** @return array<string, list<array{country: string, competition: string, isTopFlight: bool, division: ?string}>> */
    public function getGroupedLeaguesProperty(): array
    {
        $term = mb_strtolower(trim($this->search));
        $grouped = [];
        foreach ($this->leagues as $league) {
            $hay = mb_strtolower($league['country'].' '.$league['competition']);
            if ($term !== '' && ! str_contains($hay, $term)) {
                continue;
            }
            $grouped[$league['country']][] = $league;
        }

        return $grouped;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reload')->label('Recarregar banco')->action('reload'),
        ];
    }
}
