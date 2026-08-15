<?php

namespace App\Filament\Pages;

use App\Oracly\Services\DailyCardsService;
use App\Oracly\Support\BrasiliaDate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DailyLayList extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Lista LAY';

    protected static ?string $title = 'Lista LAY do dia';

    protected static string|UnitEnum|null $navigationGroup = 'Operação diária';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.daily-lay-list';

    public string $date = '';

    /** @var list<array{kickoffAt: string, homeTeam: string, awayTeam: string, bet: string, rank: int}> */
    public array $rows = [];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
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

    public function reload(): void
    {
        try {
            $groups = app(DailyCardsService::class)->forDate($this->date)['groups'];
            $rows = [];

            foreach ($groups as $group) {
                foreach ($group['cards'] as $card) {
                    foreach ($card['actions'] as $action) {
                        $rows[] = [
                            'kickoffAt' => (string) $card['kickoffAt'],
                            'homeTeam' => (string) $card['homeTeam'],
                            'awayTeam' => (string) $card['awayTeam'],
                            'bet' => (string) $action['bet'],
                            'rank' => (int) $action['rank'],
                        ];
                    }
                }
            }

            usort($rows, fn (array $a, array $b): int => strcmp($a['kickoffAt'], $b['kickoffAt']) ?: strcmp($a['bet'], $b['bet']));
            $this->rows = $rows;
        } catch (\Throwable $e) {
            $this->rows = [];
            Notification::make()->title('Erro ao montar a lista LAY')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->action('previousDay'),
            Action::make('reload')->label('Recarregar')->action('reload'),
            Action::make('next')->label('Próximo dia')->action('nextDay'),
        ];
    }
}
