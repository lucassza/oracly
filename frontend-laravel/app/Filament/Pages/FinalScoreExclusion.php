<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FinalScoreExclusionService;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FinalScoreExclusion extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Excluir placares FT';

    protected static ?string $title = 'Excluir dois placares FT';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.final-score-exclusion';

    public string $mode = 'upcoming';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Próximos jogos',
        'history' => 'Histórico',
    ];

    public function mount(): void
    {
        $this->reload();
    }

    public function setMode(string $value): void
    {
        if (array_key_exists($value, self::MODE_OPTIONS)) {
            $this->mode = $value;
            $this->reload();
        }
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $service = app(FinalScoreExclusionService::class);
            $this->rows = $this->mode === 'history'
                ? $service->history()
                : $service->upcoming(now()->toIso8601String());
        } catch (\Throwable $e) {
            $this->rows = [];
            Notification::make()->title('Erro ao calcular exclusões FT')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return array{entries: int, wins: ?int, hitRate: ?float} */
    public function getStatsProperty(): array
    {
        $entries = count($this->rows);
        $wins = $this->mode === 'history' ? count(array_filter($this->rows, fn (array $row) => ! empty($row['hit']))) : null;

        return [
            'entries' => $entries,
            'wins' => $wins,
            'hitRate' => $wins !== null && $entries > 0 ? ($wins / $entries) * 100 : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('reload')->label('Recarregar banco')->action('reload')];
    }
}
