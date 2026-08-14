<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FinalScoreExclusionService;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\HistoryCsv;
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

    protected static string | UnitEnum | null $navigationGroup = 'Estratégias';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.final-score-exclusion';

    public string $mode = 'upcoming';

    public string $scoreFilter = 'all';

    public int $favoriteFilter = 0;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

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
        try {
            $service = app(FinalScoreExclusionService::class);
            $this->rows = $this->mode === 'history'
                ? $service->history()
                : $service->upcoming(now()->toIso8601String());
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao calcular exclusões FT')->body($e->getMessage())->danger()->send();
        }
    }

    public function refresh(): void
    {
        OraclyCache::forgetPrefix();
        $this->reload();
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

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        return array_values(array_filter($this->rows, fn (array $row) => ($this->mode !== 'history' || $this->scoreFilter === 'all' || ($row['actual'] ?? null) === $this->scoreFilter)
            && ($this->favoriteFilter === 0 || in_array(
                ($row['country'] ?? '').'::'.($row['competition'] ?? ''),
                $this->favoriteLeagues,
                true,
            ))));
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
        foreach ($this->rows as $row) {
            if (! empty($row['actual'])) {
                $options[$row['actual']] = str_replace('x', ' x ', $row['actual']);
            }
        }

        return $options;
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return HistoryCsv::download('historico-exclusao-placares-ft.csv', [
            'Data', 'Casa', 'Visitante', 'Liga', 'Placares excluídos', 'Chance conjunta', 'FT', 'Acerto',
        ], array_map(fn (array $row) => [
            $row['kickoffAt'] ?? '', $row['homeTeam'] ?? '', $row['awayTeam'] ?? '',
            ($row['country'] ?? '').' · '.($row['competition'] ?? ''), implode(' e ', $row['excluded'] ?? []),
            $row['combinedProbability'] ?? '', $row['actual'] ?? '', ! empty($row['hit']) ? 'Green' : 'Red',
        ], $this->filteredRows));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label('Exportar CSV')->visible(fn (): bool => $this->mode === 'history')->action('exportCsv'),
            Action::make('reload')->label('Recarregar banco')->action('refresh'),
        ];
    }
}
