<?php

namespace App\Filament\Pages;

use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Services\PunterOver05HtStrategy;
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
 * Over 0.5 HT a partir dos dados Punter — aposta A FAVOR (sai gol no 1º tempo), não uma
 * LAY, por isso é uma página separada da PunterLayList (mesmo padrão do SokkerPRO, onde
 * Over05Ht também é página própria, fora da DailyLayList).
 *
 * Critério muda por modo (ver PunterOver05HtStrategy): histórico usa o corte de odd de
 * mercado (`odds_1st_half_over05`, 70-78% conforme o perfil); lista do dia usa a
 * recomendação do próprio Punter (`tendencia_over_ht`, mais fraca — 74% — mas é o único
 * sinal disponível pra jogo que ainda não aconteceu, já que panel_fixtures não tem odd de
 * 1º tempo).
 */
class PunterOver05Ht extends Page
{
    private const HISTORY_PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Over 0.5 HT Punter';

    protected static ?string $title = 'Over 0.5 HT — Punter';

    protected static string|UnitEnum|null $navigationGroup = 'Operação diária';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.punter-over-05-ht';

    public string $date = '';

    public string $mode = 'upcoming';

    public string $profileFilter = 'balanced';

    public string $historyFrom = '';

    public string $historyTo = '';

    public string $historyCompetitionFilter = 'all';

    public int $historyPage = 1;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $historyRows = [];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<string, string> */
    public const PROFILE_OPTIONS = [
        'baseline' => 'Baseline (odd HT < 1,60)',
        'balanced' => 'Balanced (odd HT < 1,45)',
        'strong' => 'Strong (odd HT < 1,30)',
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

    public function setProfileFilter(string $value): void
    {
        if (! array_key_exists($value, self::PROFILE_OPTIONS)) {
            return;
        }
        $this->profileFilter = $value;
        $this->resetHistoryPage();
        $this->reload();
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
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            Notification::make()->title('Erro ao montar Over 0.5 HT Punter')->body($e->getMessage())->danger()->send();
        }
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
        $strategy = app(PunterOver05HtStrategy::class);
        $rows = [];

        foreach (app(PunterMatchPickService::class)->upcoming($this->date) as $row) {
            if (! $strategy->punterRecommends($row)) {
                continue;
            }

            $rows[] = [
                'matchKey' => $row['matchKey'],
                'matchLabel' => $row['matchLabel'],
                'dateBrasilia' => $row['matchDate'],
                'homeTeam' => $row['homeTeam'],
                'awayTeam' => $row['awayTeam'],
                'competition' => $row['competition'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['matchLabel'], $b['matchLabel']));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function buildHistoryRows(): array
    {
        return OraclyCache::remember(OraclyCache::key('punter-over05ht:history:'.$this->profileFilter), function (): array {
            $strategy = app(PunterOver05HtStrategy::class);
            $rows = [];

            foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
                if (! $strategy->matchesOddProfile($row, $this->profileFilter)) {
                    continue;
                }
                $result = $strategy->result($row);
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
                    'oddOver05Ht' => $row['oddOver05Ht'],
                    'punterAgrees' => $strategy->punterRecommends($row),
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
