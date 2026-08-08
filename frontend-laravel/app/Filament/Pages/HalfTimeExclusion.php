<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\HalfTimeExclusionService;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class HalfTimeExclusion extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = '1º Tempo';

    protected static ?string $title = '1º Tempo';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.half-time-exclusion';

    public string $mode = 'upcoming';

    public string $agreementFilter = '';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        $this->reload();
    }

    public function updatedMode(): void
    {
        $this->reload();
    }

    public function updatedAgreementFilter(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $service = app(HalfTimeExclusionService::class);
            $filter = $this->agreementFilter !== '' ? $this->agreementFilter : null;
            $this->rows = $this->mode === 'history'
                ? $service->history($filter)
                : $service->upcoming(now()->toIso8601String(), $filter);
        } catch (\Throwable $e) {
            $this->rows = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
        }
    }

    public function toggleFavoriteLeague(string $country, string $competition): void
    {
        app(FavoritesService::class)->toggleLeague($country, $competition);
        Notification::make()->title('Favoritos atualizados')->success()->send();
    }

    public function getHitRateProperty(): ?string
    {
        if ($this->mode !== 'history' || $this->rows === []) {
            return null;
        }
        $hits = count(array_filter($this->rows, fn ($r) => ! empty($r['hit'])));

        return number_format(($hits / count($this->rows)) * 100, 1).'% ('.$hits.'/'.count($this->rows).')';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reload')->label('Recarregar banco')->action('reload'),
        ];
    }
}
