<?php

namespace App\Filament\Pages;

use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\HalfTimeExclusionService;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\HistoryCsv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class HalfTimeExclusion extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Excluir resultado HT';

    protected static ?string $title = '1º Tempo';

    protected static string | UnitEnum | null $navigationGroup = 'Estratégias';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.half-time-exclusion';

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Próximos jogos',
        'history' => 'Histórico',
    ];

    /** @var array<int, string> */
    public const FAVORITE_OPTIONS = [
        0 => 'Todas as ligas',
        1 => 'Somente favoritos',
    ];

    /** Agreement between sources (sourcesAgreeing/sourcesAvailable) — the "confidence" dimension for this pick. */
    public const AGREEMENT_TIERS = ['3/3', '2/2', '2/3', '1/2', '1/3', '1/1'];

    public string $mode = 'upcoming';

    /** @var list<string> */
    public array $agreementFilters = [];

    public int $favoriteFilter = 0;

    public string $scoreFilter = 'all';

    /** Unfiltered rows for the current mode, fetched once and filtered client-side per agreement tier. */
    public array $allRows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    public function mount(): void
    {
        $this->reload();
    }

    public function setMode(string $value): void
    {
        $this->mode = $value;
        $this->reload();
    }

    public function setAgreementFilter(string $value): void
    {
        if ($value === '') {
            $this->agreementFilters = [];

            return;
        }

        if (in_array($value, $this->agreementFilters, true)) {
            $this->agreementFilters = array_values(array_filter(
                $this->agreementFilters,
                fn (string $filter) => $filter !== $value,
            ));
        } else {
            $this->agreementFilters[] = $value;
        }
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $service = app(HalfTimeExclusionService::class);
            $this->allRows = $this->mode === 'history'
                ? $service->history(null)
                : $service->upcoming(now()->toIso8601String(), null);
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
        } catch (\Throwable $e) {
            $this->allRows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
        }
    }

    public function toggleFavoriteLeague(string $country, string $competition): void
    {
        try {
            $service = app(FavoritesService::class);
            $service->toggleLeague($country, $competition);
            $this->favoriteLeagues = $service->get()['leagues'];
            Notification::make()->title('Favoritos atualizados')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Erro ao salvar favorito')->body($e->getMessage())->danger()->send();
        }
    }

    public function setFavoriteFilter(int $value): void
    {
        $this->favoriteFilter = $value;
    }

    /** @return list<array<string, mixed>> */
    public function getRowsProperty(): array
    {
        $rows = $this->filterByScore(
            $this->filterByAgreement($this->filterByFavorite($this->allRows), $this->agreementFilters),
        );
        usort($rows, fn (array $a, array $b) => strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));

        return $rows;
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

    /** @return list<array<string, mixed>> */
    private function filterByFavorite(array $rows): array
    {
        if ($this->favoriteFilter === 0) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row): bool {
            $key = ($row['country'] ?? '').'::'.($row['competition'] ?? '');

            return in_array($key, $this->favoriteLeagues, true);
        }));
    }

    /** @return list<array<string, mixed>> */
    private function filterByAgreement(array $rows, array $agreementFilters): array
    {
        if ($agreementFilters === []) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row) => in_array($row['agreementKey'] ?? null, $agreementFilters, true),
        ));
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function filterByScore(array $rows): array
    {
        if ($this->mode !== 'history' || $this->scoreFilter === 'all') {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row) => $this->scoreKey($row) === $this->scoreFilter));
    }

    /** @param array<string, mixed> $row */
    private function scoreKey(array $row): ?string
    {
        if (! is_numeric($row['homeScore'] ?? null) || ! is_numeric($row['awayScore'] ?? null)) {
            return null;
        }

        return sprintf('%d-%d', (int) $row['homeScore'], (int) $row['awayScore']);
    }

    /** @return array{sampleSize: int, entries: int, wins: ?int, coverage: float, hitRate: ?float} */
    public function getStatsProperty(): array
    {
        $sampleSize = count($this->filterByFavorite($this->allRows));
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

    /** @return array<string, array{entries: int, coverage: float, hitRate: ?float}> */
    public function getConfidenceLineProperty(): array
    {
        $favoriteRows = $this->filterByFavorite($this->allRows);
        $sampleSize = count($favoriteRows);
        $line = [];

        foreach (self::AGREEMENT_TIERS as $tier) {
            $filtered = $this->filterByAgreement($favoriteRows, [$tier]);
            $entries = count($filtered);
            $wins = $this->mode === 'history' ? count(array_filter($filtered, fn ($r) => ! empty($r['hit']))) : null;

            $line[$tier] = [
                'entries' => $entries,
                'coverage' => $sampleSize > 0 ? ($entries / $sampleSize) * 100 : 0.0,
                'hitRate' => ($wins !== null && $entries > 0) ? ($wins / $entries) * 100 : null,
            ];
        }

        return $line;
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return HistoryCsv::download('historico-exclusao-primeiro-tempo.csv', [
            'Data', 'Casa', 'Visitante', 'Liga', 'HT', 'FT', 'Excluir', 'Acordo', 'Resultado HT', 'Acerto',
        ], array_map(fn (array $row) => [
            $row['kickoffAt'] ?? '', $row['homeTeam'] ?? '', $row['awayTeam'] ?? '',
            ($row['country'] ?? '').' · '.($row['competition'] ?? ''),
            ($row['halftimeHomeScore'] ?? '—').'-'.($row['halftimeAwayScore'] ?? '—'),
            ($row['homeScore'] ?? '—').'-'.($row['awayScore'] ?? '—'), $row['excluded'] ?? '',
            $row['agreementKey'] ?? '', $row['actual'] ?? '', ! empty($row['hit']) ? 'Green' : 'Red',
        ], $this->rows));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label('Exportar CSV')->visible(fn (): bool => $this->mode === 'history')->action('exportCsv'),
            Action::make('reload')->label('Recarregar banco')->action('reload'),
        ];
    }
}
