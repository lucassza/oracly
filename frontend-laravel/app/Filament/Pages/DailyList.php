<?php

namespace App\Filament\Pages;

use App\Oracly\Services\DailyPickService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DailyList extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Lista diária';

    protected static ?string $title = 'Lista diária';

    protected static string | UnitEnum | null $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.daily-list';

    public string $date = '';

    public int $pageNumber = 1;

    public int $pageSize = 20;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function reload(): void
    {
        OraclyCache::forgetPrefix();
        try {
            $this->rows = app(DailyPickService::class)->forDate($this->date);
            $this->pageNumber = 1;
        } catch (\Throwable $e) {
            $this->rows = [];
            Notification::make()->title('Erro ao ler Postgres Oracly')->body($e->getMessage())->danger()->send();
        }
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

    /** @return list<array<string, mixed>> */
    public function getPagedRowsProperty(): array
    {
        $offset = ($this->pageNumber - 1) * $this->pageSize;

        return array_slice($this->rows, $offset, $this->pageSize);
    }

    public function getTotalPagesProperty(): int
    {
        return max(1, (int) ceil(count($this->rows) / $this->pageSize));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->action('previousDay'),
            Action::make('reload')->label('Recarregar banco')->action('reload'),
            Action::make('next')->label('Próximo dia')->action('nextDay'),
        ];
    }
}
