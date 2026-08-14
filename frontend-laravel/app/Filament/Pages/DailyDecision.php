<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\DailyPickService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DailyDecision extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static ?string $navigationLabel = '3 melhores do dia';
    protected static ?string $title = 'Radar de decisão diária';
    protected static string | UnitEnum | null $navigationGroup = 'Operação diária';
    protected static ?int $navigationSort = 0;
    protected string $view = 'filament.pages.daily-decision';

    public string $date = '';

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
            $this->cards = $this->buildCards(app(DailyPickService::class)->forDate($this->date));
        } catch (\Throwable $e) {
            $this->cards = [];
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
        $cards = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? null) !== 'not_started') {
                continue;
            }
            $actions = [];
            $signalScore = count(array_filter([
                (float) ($row['over25'] ?? 0) >= 70,
                (float) ($row['btts'] ?? 0) >= 65,
                (float) ($row['combinedGoalsAverage'] ?? 0) >= 3.8,
                (float) ($row['over05Percentage'] ?? 0) >= 90,
            ]));
            if ($signalScore >= 2 && is_numeric($row['over15'] ?? null)) {
                $actions[] = ['label' => 'Over 1.5 FT', 'detail' => $signalScore.'/4 sinais · '.round((float) $row['over15']).'%', 'priority' => (float) $row['over15'] + ($signalScore * 5), 'tone' => 'amber'];
            }
            if (is_numeric($row['btts'] ?? null) && (float) $row['btts'] >= 55) {
                $actions[] = ['label' => 'Ambas marcam', 'detail' => round((float) $row['btts']).'%', 'priority' => (float) $row['btts'], 'tone' => 'sky'];
            }
            if (is_numeric($row['over05Ht'] ?? null) && (float) $row['over05Ht'] >= 80) {
                $actions[] = ['label' => 'Over 0.5 HT', 'detail' => round((float) $row['over05Ht']).'%', 'priority' => (float) $row['over05Ht'], 'tone' => 'emerald'];
            }
            foreach ([['strategy' => $one, 'name' => 'Contra 0x1/1x0'], ['strategy' => $two, 'name' => 'Contra 0x2/2x0'], ['strategy' => $three, 'name' => 'Contra 3x1/1x3']] as $exact) {
                $choice = $exact['strategy']->choice($row);
                if ($choice !== null) {
                    $actions[] = ['label' => $exact['name'], 'detail' => 'Contra '.str_replace('-', 'x', $choice['score']).' · '.number_format($choice['probability'] * 100, 1).'%', 'priority' => 0.0, 'tone' => 'violet'];
                }
            }
            if ($actions === []) continue;
            usort($actions, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
            $cards[] = [...$row, 'actions' => $actions, 'priority' => $actions[0]['priority'], 'signalScore' => $signalScore];
        }
        usort($cards, fn (array $a, array $b): int => $b['priority'] <=> $a['priority'] ?: strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));

        return array_slice($cards, 0, 3);
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
