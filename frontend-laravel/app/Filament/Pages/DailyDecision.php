<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeGoalsStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\DailyPickService;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\HalfTimeExclusionService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DailyDecision extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = '3 melhores do dia';

    protected static ?string $title = 'Radar de decisão diária';

    protected static string|UnitEnum|null $navigationGroup = 'Operação diária';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.daily-decision';

    public string $date = '';

    /** @var list<string> */
    public array $favoriteLeagues = [];

    /** @var list<string> */
    public const OPEN_STATUSES = ['1st', '2nd', 'et', 'half_time', 'live'];

    /** @var list<array<string, mixed>> */
    public array $cards = [];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function reload(): void
    {
        try {
            $this->favoriteLeagues = app(FavoritesService::class)->get()['leagues'];
            $this->cards = $this->buildCards(app(DailyPickService::class)->forDate($this->date));
        } catch (\Throwable $e) {
            $this->cards = [];
            $this->favoriteLeagues = [];
            Notification::make()->title('Erro ao montar radar diário')->body($e->getMessage())->danger()->send();
        }
    }

    public function refresh(): void
    {
        OraclyCache::forgetPrefix();
        $this->reload();
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

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function buildCards(array $rows): array
    {
        $one = app(AgainstOneGoalStrategy::class);
        $two = app(AgainstTwoGoalsStrategy::class);
        $three = app(AgainstThreeOneStrategy::class);
        $threeGoals = app(AgainstThreeGoalsStrategy::class);
        $fixtures = [];
        $byStrategy = [];
        foreach ($rows as $row) {
            if (! in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $this->favoriteLeagues, true)) {
                continue;
            }
            $id = (string) ($row['providerMatchId'] ?? '');
            if ($id === '') {
                continue;
            }
            $fixtures[$id] = $row;
            $signalScore = count(array_filter([
                (float) ($row['over25'] ?? 0) >= 70,
                (float) ($row['btts'] ?? 0) >= 65,
                (float) ($row['combinedGoalsAverage'] ?? 0) >= 3.8,
                (float) ($row['over05Percentage'] ?? 0) >= 90,
            ]));
            if ($signalScore >= 2 && is_numeric($row['over15'] ?? null)) {
                $byStrategy['over15'][] = ['fixtureId' => $id, 'label' => 'Radar de gols', 'bet' => 'Over 1.5 FT', 'detail' => $signalScore.'/4 sinais · '.round((float) $row['over15']).'%', 'signalScore' => $signalScore, 'value' => (float) $row['over15'], 'btts' => (float) ($row['btts'] ?? 0), 'kickoffAt' => $row['kickoffAt']];
            }
            if (is_numeric($row['btts'] ?? null) && (float) $row['btts'] >= 55) {
                $byStrategy['btts'][] = ['fixtureId' => $id, 'label' => 'Radar BTTS', 'bet' => 'Ambas marcam', 'detail' => round((float) $row['btts']).'%', 'value' => (float) $row['btts'], 'kickoffAt' => $row['kickoffAt']];
            }
            if (is_numeric($row['over05Ht'] ?? null) && (float) $row['over05Ht'] >= 80) {
                $byStrategy['over05ht'][] = ['fixtureId' => $id, 'label' => 'Radar 1º tempo', 'bet' => 'Over 0.5 HT', 'detail' => round((float) $row['over05Ht']).'%', 'value' => (float) $row['over05Ht'], 'kickoffAt' => $row['kickoffAt']];
            }
            foreach ([['strategy' => $one, 'name' => 'Contra 0x1/1x0'], ['strategy' => $two, 'name' => 'Contra 0x2/2x0'], ['strategy' => $three, 'name' => 'Contra 3x1/1x3'], ['strategy' => $threeGoals, 'name' => 'Contra 0x3/3x0']] as $exact) {
                $choice = $exact['strategy']->choice($row);
                if ($choice !== null && (float) ($row['over15'] ?? 0) >= 75) {
                    $key = match ($exact['name']) {
                        'Contra 0x1/1x0' => 'against1',
                        'Contra 0x2/2x0' => 'against2',
                        'Contra 3x1/1x3' => 'against31',
                        default => 'against3',
                    };
                    $byStrategy[$key][] = ['fixtureId' => $id, 'label' => $exact['name'], 'bet' => 'Contra '.str_replace('-', 'x', $choice['score']), 'detail' => number_format($choice['probability'] * 100, 1).'%', 'exactProbability' => $choice['probability'], 'kickoffAt' => $row['kickoffAt']];
                }
            }
        }
        foreach (app(HalfTimeExclusionService::class)->upcoming(now()->toIso8601String()) as $row) {
            $id = (string) ($row['providerMatchId'] ?? '');
            if (isset($fixtures[$id]) && BrasiliaDate::fromKickoff($row['kickoffAt']) === $this->date) {
                $byStrategy['half'][] = ['fixtureId' => $id, 'label' => 'Excluir resultado HT', 'bet' => 'Contra '.strtoupper((string) $row['excluded']), 'detail' => 'Acordo '.$row['agreementKey'], 'agreement' => (int) $row['agreement'], 'available' => (int) $row['sourcesAvailable'], 'exactProbability' => (float) ($row['probExcluded'] ?? INF), 'kickoffAt' => $row['kickoffAt']];
            }
        }

        $selected = [];
        foreach ($byStrategy as $key => $actions) {
            $compare = match ($key) {
                'over15' => fn (array $a, array $b): int => $b['signalScore'] <=> $a['signalScore'] ?: $b['value'] <=> $a['value'] ?: $b['btts'] <=> $a['btts'],
                'btts', 'over05ht' => fn (array $a, array $b): int => $b['value'] <=> $a['value'],
                'half' => fn (array $a, array $b): int => $b['agreement'] <=> $a['agreement'] ?: $b['available'] <=> $a['available'] ?: $a['exactProbability'] <=> $b['exactProbability'],
                default => fn (array $a, array $b): int => $a['exactProbability'] <=> $b['exactProbability'],
            };
            foreach ($this->topThreeByHour($actions, $compare) as $action) {
                $selected[$action['fixtureId']][] = $action;
            }
        }
        $cards = [];
        foreach ($selected as $id => $actions) {
            usort($actions, fn (array $a, array $b): int => $a['rank'] <=> $b['rank'] ?: strcmp($a['label'], $b['label']));
            $cards[] = [...$fixtures[$id], 'actions' => $actions];
        }
        usort($cards, fn (array $a, array $b): int => strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));

        return $cards;
    }

    /** @return list<array<string, mixed>> */
    public function getCardGroupsProperty(): array
    {
        $upcoming = [];
        $live = [];
        $past = [];

        foreach ($this->cards as $card) {
            $status = $card['status'] ?? null;

            if (in_array($status, self::OPEN_STATUSES, true)) {
                $live[] = $card;
            } elseif ($status === 'not_started') {
                $upcoming[] = $card;
            } else {
                $past[] = $card;
            }
        }

        return [
            ['key' => 'upcoming', 'title' => 'Próximas', 'description' => 'Partidas que ainda não começaram.', 'cards' => $upcoming],
            ['key' => 'live', 'title' => 'Em andamento', 'description' => 'Partidas com jogo em curso.', 'cards' => $live],
            ['key' => 'past', 'title' => 'Finalizadas', 'description' => 'Partidas encerradas.', 'cards' => $past],
        ];
    }

    /** @param list<array<string, mixed>> $actions
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
            Action::make('prev')->label('Dia anterior')->action('previousDay'),
            Action::make('reload')->label('Recarregar banco')->action('refresh'),
            Action::make('next')->label('Próximo dia')->action('nextDay'),
        ];
    }
}
