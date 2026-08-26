<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeGoalsStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\DailyCardsService;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\PredictionService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DailyLayList extends Page
{
    private const HISTORY_FIXTURE_LIMIT = 5000;

    private const HISTORY_PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Lista LAY';

    protected static ?string $title = 'Lista LAY do dia';

    protected static string|UnitEnum|null $navigationGroup = 'Operação diária';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.daily-lay-list';

    public string $date = '';

    public string $mode = 'upcoming';

    public string $hourFilter = 'all';

    public string $historyFrom = '';

    public string $historyTo = '';

    public string $historyStrategyFilter = 'all';

    public string $historyCompetitionFilter = 'all';

    public int $historyPage = 1;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $historyRows = [];

    /** @var list<string> */
    public array $favoriteLeagues = [];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
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

    public function setMode(string $value): void
    {
        if (! array_key_exists($value, self::MODE_OPTIONS)) {
            return;
        }

        $this->mode = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function reload(): void
    {
        try {
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
            $this->rows = $this->mode === 'upcoming' ? $this->buildUpcomingRows() : [];
            $this->historyRows = $this->mode === 'history' ? $this->buildHistoryRows() : [];

            if ($this->mode === 'upcoming' && $this->hourFilter !== 'all' && ! in_array($this->hourFilter, $this->hours, true)) {
                $this->hourFilter = 'all';
            }
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao montar a lista LAY')->body($e->getMessage())->danger()->send();
        }
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

    public function updatedHistoryStrategyFilter(): void
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

    /** @return list<string> */
    public function getHoursProperty(): array
    {
        return array_values(array_unique(array_map(
            fn (array $row): string => Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i'),
            $this->rows,
        )));
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        if ($this->mode === 'history') {
            return array_slice($this->groupedHistoryRows, ($this->historyPagination['page'] - 1) * self::HISTORY_PER_PAGE, self::HISTORY_PER_PAGE);
        }

        if ($this->hourFilter === 'all') {
            return $this->rows;
        }

        return array_values(array_filter(
            $this->rows,
            fn (array $row): bool => Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') === $this->hourFilter,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function getGroupedHistoryRowsProperty(): array
    {
        return $this->groupByFixture($this->filteredHistoryRows);
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredHistoryRowsProperty(): array
    {
        return array_values(array_filter($this->historyRows, function (array $row): bool {
            $date = BrasiliaDate::fromKickoff((string) $row['kickoffAt']);
            $competition = ($row['country'] ?? '').'::'.($row['competition'] ?? '');

            return ($this->historyFrom === '' || $date >= $this->historyFrom)
                && ($this->historyTo === '' || $date <= $this->historyTo)
                && ($this->historyStrategyFilter === 'all' || ($row['bet'] ?? '') === $this->historyStrategyFilter)
                && ($this->historyCompetitionFilter === 'all' || $competition === $this->historyCompetitionFilter);
        }));
    }

    /** @return array{page: int, lastPage: int, total: int, from: int, to: int} */
    public function getHistoryPaginationProperty(): array
    {
        $total = count($this->groupedHistoryRows);
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

    /** @return list<string> */
    public function getHistoryStrategiesProperty(): array
    {
        $strategies = array_values(array_unique(array_map(fn (array $row): string => (string) $row['bet'], $this->historyRows)));
        sort($strategies);

        return $strategies;
    }

    /** @return array<string, string> */
    public function getHistoryCompetitionsProperty(): array
    {
        $competitions = [];

        foreach ($this->historyRows as $row) {
            $key = ($row['country'] ?? '').'::'.($row['competition'] ?? '');
            $competitions[$key] = trim(($row['country'] ?? '').' · '.($row['competition'] ?? ''), ' ·');
        }

        asort($competitions);

        return $competitions;
    }

    /** @return array{entries: int, wins: int, reds: int, hitRate: ?float} */
    public function getHistoryStatsProperty(): array
    {
        $entries = count($this->filteredHistoryRows);
        $wins = count(array_filter($this->filteredHistoryRows, fn (array $row): bool => ! empty($row['hit'])));

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

            if (! empty($row['hit'])) {
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
            $date = BrasiliaDate::fromKickoff((string) $row['kickoffAt']);
            $days[$date] ??= ['date' => $date, 'label' => Carbon::parse($date)->format('d/m'), 'entries' => 0, 'wins' => 0, 'reds' => 0, 'hitRate' => null];
            $days[$date]['entries']++;

            if (! empty($row['hit'])) {
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
        $groups = app(DailyCardsService::class)->forDate($this->date)['groups'];
        $rows = [];

        foreach ($groups as $group) {
            foreach ($group['cards'] as $card) {
                $bets = array_map(fn (array $action): array => [
                    'bet' => (string) $action['bet'],
                    'rank' => (int) $action['rank'],
                    'exactProbability' => isset($action['exactProbability']) ? (float) $action['exactProbability'] : null,
                ], $card['actions']);

                usort($bets, fn (array $a, array $b): int => $a['rank'] <=> $b['rank'] ?: strcmp($a['bet'], $b['bet']));

                $rows[] = [
                    'kickoffAt' => (string) $card['kickoffAt'],
                    'homeTeam' => (string) $card['homeTeam'],
                    'awayTeam' => (string) $card['awayTeam'],
                    'competition' => (string) ($card['competition'] ?? ''),
                    'country' => (string) ($card['country'] ?? ''),
                    'status' => (string) ($card['status'] ?? ''),
                    'homeScore' => $card['homeScore'] ?? null,
                    'awayScore' => $card['awayScore'] ?? null,
                    'bets' => $bets,
                ];
            }
        }

        usort($rows, function (array $a, array $b): int {
            $hourA = Carbon::parse($a['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');
            $hourB = Carbon::parse($b['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');

            return strcmp($hourA, $hourB)
                ?: $this->bestExactProbability($a['bets']) <=> $this->bestExactProbability($b['bets'])
                ?: strcmp($a['kickoffAt'], $b['kickoffAt']);
        });

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function buildHistoryRows(): array
    {
        $favorites = $this->favoriteLeagues;
        sort($favorites);

        return OraclyCache::remember(OraclyCache::key('daily-lay-list:history:v2:'.md5(implode('|', $favorites))), function () use ($favorites): array {
            $matchesFavorite = fn (array $row): bool => in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $favorites, true);
            $fixtures = [];
            $byStrategy = [];
            $over15History = array_values(array_filter(app(PredictionService::class)->history('over_15_ft', 0, self::HISTORY_FIXTURE_LIMIT), $matchesFavorite));
            $strategies = [
                'against1' => app(AgainstOneGoalStrategy::class),
                'against2' => app(AgainstTwoGoalsStrategy::class),
                'against31' => app(AgainstThreeOneStrategy::class),
                'against3' => app(AgainstThreeGoalsStrategy::class),
            ];

            foreach ($over15History as $row) {
                $fixtureId = (string) ($row['providerMatchId'] ?? '');
                if ($fixtureId === '') {
                    continue;
                }

                $fixtures[$fixtureId] = [
                    'providerMatchId' => $fixtureId,
                    'kickoffAt' => (string) ($row['kickoffAt'] ?? ''),
                    'homeTeam' => (string) ($row['homeTeam'] ?? ''),
                    'awayTeam' => (string) ($row['awayTeam'] ?? ''),
                    'competition' => (string) ($row['competition'] ?? ''),
                    'country' => (string) ($row['country'] ?? ''),
                    'homeScore' => $row['homeScore'] ?? null,
                    'awayScore' => $row['awayScore'] ?? null,
                    'halftimeHomeScore' => $row['halftimeHomeScore'] ?? null,
                    'halftimeAwayScore' => $row['halftimeAwayScore'] ?? null,
                ];

                if ((float) ($row['probability'] ?? 0) < 75) {
                    continue;
                }

                foreach ($strategies as $key => $strategy) {
                    $choice = $strategy->choice($row);

                    if ($choice === null) {
                        continue;
                    }

                    // O sub-caso "contra 1x0" tem assertividade histórica bem menor que o "contra 0x1" (90,7% vs 96,9%).
                    if ($key === 'against1' && $choice['score'] === '1-0') {
                        continue;
                    }

                    $byStrategy[$key][] = [
                        'fixtureId' => $fixtureId,
                        'kickoffAt' => (string) ($row['kickoffAt'] ?? ''),
                        'bet' => 'LAY '.str_replace('-', 'x', (string) $choice['score']),
                        'targetScore' => (string) $choice['score'],
                        'exactProbability' => (float) $choice['probability'],
                    ];
                }
            }

            $selected = [];

            foreach ($byStrategy as $actions) {
                foreach ($this->topThreeByHour($actions, fn (array $a, array $b): int => $a['exactProbability'] <=> $b['exactProbability']) as $action) {
                    $selected[] = [...$fixtures[$action['fixtureId']], ...$action];
                }
            }

            foreach ($selected as &$row) {
                if (! array_key_exists('hit', $row)) {
                    $row['hit'] = is_numeric($row['homeScore'] ?? null) && is_numeric($row['awayScore'] ?? null)
                        ? sprintf('%d-%d', (int) $row['homeScore'], (int) $row['awayScore']) !== ($row['targetScore'] ?? '')
                        : null;
                }
            }

            usort($selected, fn (array $a, array $b): int => strcmp($b['kickoffAt'] ?? '', $a['kickoffAt'] ?? '') ?: (($a['rank'] ?? 99) <=> ($b['rank'] ?? 99)));

            return $selected;
        }, 300);
    }

    private function resetHistoryPage(): void
    {
        $this->historyPage = 1;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function groupByFixture(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $id = (string) ($row['providerMatchId'] ?? '');
            $groups[$id] ??= [
                'kickoffAt' => $row['kickoffAt'] ?? '',
                'homeTeam' => $row['homeTeam'] ?? '',
                'awayTeam' => $row['awayTeam'] ?? '',
                'competition' => $row['competition'] ?? '',
                'country' => $row['country'] ?? '',
                'halftimeHomeScore' => $row['halftimeHomeScore'] ?? null,
                'halftimeAwayScore' => $row['halftimeAwayScore'] ?? null,
                'homeScore' => $row['homeScore'] ?? null,
                'awayScore' => $row['awayScore'] ?? null,
                'bets' => [],
            ];
            $groups[$id]['bets'][] = [
                'bet' => $row['bet'] ?? '',
                'rank' => $row['rank'] ?? null,
                'hit' => $row['hit'] ?? null,
                'exactProbability' => isset($row['exactProbability']) ? (float) $row['exactProbability'] : null,
            ];
        }

        foreach ($groups as &$group) {
            usort($group['bets'], fn (array $a, array $b): int => ($a['rank'] ?? 99) <=> ($b['rank'] ?? 99));
        }
        unset($group);

        $groups = array_values($groups);

        usort($groups, function (array $a, array $b): int {
            $hourA = Carbon::parse($a['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');
            $hourB = Carbon::parse($b['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');

            return strcmp($hourB, $hourA)
                ?: $this->bestExactProbability($a['bets']) <=> $this->bestExactProbability($b['bets'])
                ?: strcmp($b['kickoffAt'], $a['kickoffAt']);
        });

        return $groups;
    }

    /** @param list<array<string, mixed>> $bets */
    private function bestExactProbability(array $bets): float
    {
        $probabilities = array_values(array_filter(
            array_map(fn (array $bet): ?float => $bet['exactProbability'] ?? null, $bets),
            fn (?float $probability): bool => $probability !== null,
        ));

        return $probabilities === [] ? INF : min($probabilities);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  callable(array<string, mixed>, array<string, mixed>): int  $compare
     * @return list<array<string, mixed>>
     */
    private function topThreeByHour(array $actions, callable $compare): array
    {
        $groups = [];

        foreach ($actions as $action) {
            $groups[Carbon::parse($action['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H')][] = $action;
        }

        $selected = [];

        foreach ($groups as $group) {
            usort($group, $compare);

            foreach (array_slice($group, 0, 3) as $rank => $action) {
                $selected[] = [...$action, 'rank' => $rank + 1];
            }
        }

        return $selected;
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
