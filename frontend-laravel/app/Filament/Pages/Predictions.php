<?php

namespace App\Filament\Pages;

use App\Oracly\Services\PredictionService;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Predictions extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Over 0.5 / 1.5';

    protected static ?string $title = 'Over 0.5 / 1.5';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.predictions';

    public string $market = 'over_05_ht';

    public string $mode = 'upcoming';

    public int $minProbability = 80;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        $this->reload();
    }

    public function updatedMarket(): void
    {
        $this->reload();
    }

    public function updatedMode(): void
    {
        $this->reload();
    }

    public function updatedMinProbability(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $service = app(PredictionService::class);
            $this->rows = $this->mode === 'history'
                ? $service->history($this->market, (float) $this->minProbability)
                : $service->upcoming($this->market, now()->toIso8601String(), (float) $this->minProbability);
        } catch (\Throwable $e) {
            $this->rows = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
        }
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
