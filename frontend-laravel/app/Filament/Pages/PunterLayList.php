<?php

namespace App\Filament\Pages;

use App\Oracly\Services\PunterLayCasaForaStrategy;
use App\Oracly\Services\PunterLaySignalService;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Picks LAY a partir dos dados Punter (schema Postgres `punter`), em paralelo à Lista LAY
 * do SokkerPRO (`DailyLayList`) — não a substitui, os universos de jogos se sobrepõem pouco.
 *
 * Dois mercados, cada um com dois modos (lista do dia / histórico apurado):
 *  - LAY 2x2 / LAY 0x1: punter.lay_signals já vem apurado pelo próprio Punter (check_result),
 *    sem Strategy nossa — a linha inteira já é o pick.
 *  - LAY Casa / LAY Fora: critério próprio (PunterLayCasaForaStrategy, odd do favorito) sobre
 *    punter.match_history (apurado) / punter.panel_fixtures (futuro, sem resultado ainda).
 */
class PunterLayList extends Page
{
    private const HISTORY_PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Lista LAY Punter';

    protected static ?string $title = 'Lista LAY — Punter';

    protected static string|UnitEnum|null $navigationGroup = 'Operação diária';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.punter-lay-list';

    public string $date = '';

    public string $market = 'lay_2x2_0x1';

    public string $mode = 'upcoming';

    public string $radarFilter = 'all';

    public string $profileFilter = 'balanced';

    public string $hourFilter = 'all';

    public string $historyFrom = '';

    public string $historyTo = '';

    public string $historyCompetitionFilter = 'all';

    public int $historyPage = 1;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $historyRows = [];

    /** @var array<string, string> */
    public const MARKET_OPTIONS = [
        'lay_2x2_0x1' => 'LAY 2x2 / LAY 0x1',
        'lay_casa_fora' => 'LAY Casa / LAY Fora',
    ];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<string, string> */
    public const RADAR_OPTIONS = [
        'all' => 'Todos',
        'lay_2x2' => 'LAY 2x2',
        'lay_0x1' => 'LAY 0x1',
    ];

    /** @var array<string, string> */
    public const PROFILE_OPTIONS = [
        'baseline' => 'Baseline (odd favorito < 1,80)',
        'balanced' => 'Balanced (odd favorito < 1,50)',
        'strong' => 'Strong (odd favorito < 1,30)',
    ];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
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

    public function refresh(): void
    {
        OraclyCache::forgetPrefix();
        $this->reload();
    }

    public function setMarket(string $value): void
    {
        if (! array_key_exists($value, self::MARKET_OPTIONS)) {
            return;
        }
        $this->market = $value;
        $this->resetHistoryPage();
        $this->hourFilter = 'all';
        $this->historyCompetitionFilter = 'all';
        $this->reload();
    }

    public function setMode(string $value): void
    {
        if (! array_key_exists($value, self::MODE_OPTIONS)) {
            return;
        }
        $this->mode = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function setRadarFilter(string $value): void
    {
        if (! array_key_exists($value, self::RADAR_OPTIONS)) {
            return;
        }
        $this->radarFilter = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function setProfileFilter(string $value): void
    {
        if (! array_key_exists($value, self::PROFILE_OPTIONS)) {
            return;
        }
        $this->profileFilter = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function setHourFilter(string $hour): void
    {
        $this->hourFilter = $hour === 'all' || in_array($hour, $this->hours, true) ? $hour : 'all';
    }

    public function updatedHistoryFrom(): void
    {
        $this->resetHistoryPage();
    }

    public function updatedHistoryTo(): void
    {
        $this->resetHistoryPage();
    }

    public function updatedHistoryCompetitionFilter(): void
    {
        $this->resetHistoryPage();
    }

    public function previousHistoryPage(): void
    {
        $this->historyPage = max(1, $this->historyPage - 1);
    }

    public function nextHistoryPage(): void
    {
        $this->historyPage = min($this->historyPagination['lastPage'], $this->historyPage + 1);
    }

    public function reload(): void
    {
        try {
            $this->rows = $this->mode === 'upcoming' ? $this->buildUpcomingRows() : [];
            $this->historyRows = $this->mode === 'history' ? $this->buildHistoryRows() : [];

            if ($this->mode === 'upcoming' && $this->hourFilter !== 'all' && ! in_array($this->hourFilter, $this->hours, true)) {
                $this->hourFilter = 'all';
            }
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            Notification::make()->title('Erro ao montar a lista LAY Punter')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return list<string> */
    public function getHoursProperty(): array
    {
        if ($this->market !== 'lay_2x2_0x1') {
            return [];
        }

        $hours = array_values(array_unique(array_map(
            fn (array $row): string => BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']),
            $this->rows,
        )));
        sort($hours);

        return $hours;
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        if ($this->market !== 'lay_2x2_0x1' || $this->hourFilter === 'all') {
            return $this->rows;
        }

        return array_values(array_filter(
            $this->rows,
            fn (array $row): bool => BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']) === $this->hourFilter,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredHistoryRowsProperty(): array
    {
        return array_values(array_filter($this->historyRows, function (array $row): bool {
            $date = $row['dateBrasilia'];

            return ($this->historyFrom === '' || $date >= $this->historyFrom)
                && ($this->historyTo === '' || $date <= $this->historyTo)
                && ($this->historyCompetitionFilter === 'all' || $row['competitionLabel'] === $this->historyCompetitionFilter);
        }));
    }

    /** @return array{page: int, lastPage: int, total: int, from: int, to: int} */
    public function getHistoryPaginationProperty(): array
    {
        $total = count($this->filteredHistoryRows);
        $lastPage = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page = min(max(1, $this->historyPage), $lastPage);

        return [
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
            'from' => $total === 0 ? 0 : (($page - 1) * self::HISTORY_PER_PAGE) + 1,
            'to' => min($page * self::HISTORY_PER_PAGE, $total),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getPagedHistoryRowsProperty(): array
    {
        $rows = $this->filteredHistoryRows;
        usort($rows, fn (array $a, array $b): int => strcmp($b['dateBrasilia'], $a['dateBrasilia']));

        return array_slice($rows, ($this->historyPagination['page'] - 1) * self::HISTORY_PER_PAGE, self::HISTORY_PER_PAGE);
    }

    /** @return array<string, string> */
    public function getHistoryCompetitionsProperty(): array
    {
        $competitions = [];
        foreach ($this->historyRows as $row) {
            $competitions[$row['competitionLabel']] = $row['competitionLabel'];
        }
        asort($competitions);

        return $competitions;
    }

    /** @return array{entries: int, wins: int, reds: int, hitRate: ?float} */
    public function getHistoryStatsProperty(): array
    {
        $entries = count($this->filteredHistoryRows);
        $wins = count(array_filter($this->filteredHistoryRows, fn (array $row): bool => $row['hit'] === true));

        return [
            'entries' => $entries,
            'wins' => $wins,
            'reds' => $entries - $wins,
            'hitRate' => $entries > 0 ? ($wins / $entries) * 100 : null,
        ];
    }

    /** @return list<array{strategy: string, entries: int, wins: int, reds: int, hitRate: ?float}> */
    public function getHistoryStrategyStatsProperty(): array
    {
        $strategies = [];

        foreach ($this->filteredHistoryRows as $row) {
            $strategy = (string) $row['bet'];
            $strategies[$strategy] ??= ['strategy' => $strategy, 'entries' => 0, 'wins' => 0, 'reds' => 0, 'hitRate' => null];
            $strategies[$strategy]['entries']++;
            if ($row['hit'] === true) {
                $strategies[$strategy]['wins']++;
            } else {
                $strategies[$strategy]['reds']++;
            }
        }

        foreach ($strategies as &$strategy) {
            $strategy['hitRate'] = $strategy['entries'] > 0 ? ($strategy['wins'] / $strategy['entries']) * 100 : null;
        }

        usort($strategies, fn (array $a, array $b): int => ($b['hitRate'] ?? 0) <=> ($a['hitRate'] ?? 0) ?: strcmp($a['strategy'], $b['strategy']));

        return $strategies;
    }

    /** @return list<array{date: string, label: string, entries: int, wins: int, reds: int, hitRate: ?float}> */
    public function getHistoryChartDaysProperty(): array
    {
        $days = [];

        foreach ($this->filteredHistoryRows as $row) {
            $date = $row['dateBrasilia'];
            $days[$date] ??= ['date' => $date, 'label' => Carbon::parse($date)->format('d/m'), 'entries' => 0, 'wins' => 0, 'reds' => 0, 'hitRate' => null];
            $days[$date]['entries']++;
            if ($row['hit'] === true) {
                $days[$date]['wins']++;
            } else {
                $days[$date]['reds']++;
            }
        }

        ksort($days);
        $days = array_values($days);

        foreach ($days as &$day) {
            $day['hitRate'] = $day['entries'] > 0 ? ($day['wins'] / $day['entries']) * 100 : null;
        }

        return array_slice($days, -30);
    }

    /** @return list<array<string, mixed>> */
    private function buildUpcomingRows(): array
    {
        if ($this->market === 'lay_2x2_0x1') {
            $rows = app(PunterLaySignalService::class)->forDate($this->date);
            if ($this->radarFilter !== 'all') {
                $rows = array_values(array_filter($rows, fn (array $r): bool => $r['radar'] === $this->radarFilter));
            }

            usort($rows, fn (array $a, array $b): int => strcmp($a['kickoffAt'], $b['kickoffAt'])
                ?: (($a['oddHome'] ?? INF) <=> ($b['oddHome'] ?? INF)));

            return $rows;
        }

        $strategy = app(PunterLayCasaForaStrategy::class);
        $rows = [];

        foreach (app(PunterMatchPickService::class)->upcoming($this->date) as $row) {
            $choice = $strategy->choice($row);
            if ($choice === null || ! $strategy->matchesProfile($row, $this->profileFilter)) {
                continue;
            }

            $rows[] = [
                'matchKey' => $row['matchKey'],
                'matchLabel' => $row['matchLabel'],
                'dateBrasilia' => $row['matchDate'],
                'homeTeam' => $row['homeTeam'],
                'awayTeam' => $row['awayTeam'],
                'competition' => $row['competition'],
                'bet' => $choice['side'] === 'fora' ? 'LAY FORA' : 'LAY CASA',
                'favoriteOdd' => $choice['favoriteOdd'],
                'underdogOdd' => $choice['underdogOdd'],
                'punterAgrees' => $choice['punterAgrees'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['favoriteOdd'] <=> $b['favoriteOdd']);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function buildHistoryRows(): array
    {
        if ($this->market === 'lay_2x2_0x1') {
            return OraclyCache::remember(OraclyCache::key('punter-lay-list:history:lay_signals'), function (): array {
                $rows = app(PunterLaySignalService::class)->history(5000);
                foreach ($rows as &$row) {
                    $row['dateBrasilia'] = BrasiliaDate::fromKickoff($row['kickoffAt']);
                    $row['competitionLabel'] = trim(($row['country'] ?? '').' · '.($row['competition'] ?? ''), ' ·');
                }

                return $rows;
            }, 300);
        }

        return OraclyCache::remember(OraclyCache::key('punter-lay-list:history:lay_casa_fora:'.$this->profileFilter), function (): array {
            $strategy = app(PunterLayCasaForaStrategy::class);
            $rows = [];

            foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
                $choice = $strategy->choice($row);
                if ($choice === null || ! $strategy->matchesProfile($row, $this->profileFilter)) {
                    continue;
                }
                $result = $strategy->result($row, $choice['side']);
                if ($result === null) {
                    continue;
                }

                $rows[] = [
                    'matchKey' => $row['matchKey'],
                    'dateBrasilia' => $row['matchDate'],
                    'homeTeam' => $row['homeTeam'],
                    'awayTeam' => $row['awayTeam'],
                    'competition' => $row['competition'],
                    'competitionLabel' => str_replace('_', ' ', (string) $row['competition']),
                    'bet' => $choice['side'] === 'fora' ? 'LAY FORA' : 'LAY CASA',
                    'favoriteOdd' => $choice['favoriteOdd'],
                    'punterAgrees' => $choice['punterAgrees'],
                    'hit' => $result === 'green',
                ];
            }

            return $rows;
        }, 300);
    }

    private function resetHistoryPage(): void
    {
        $this->historyPage = 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->visible(fn (): bool => $this->mode === 'upcoming')->action(fn () => $this->previousDay()),
            Action::make('reload')->label('Recarregar banco')->action(fn () => $this->refresh()),
            Action::make('next')->label('Próximo dia')->visible(fn (): bool => $this->mode === 'upcoming')->action(fn () => $this->nextDay()),
        ];
    }
}
