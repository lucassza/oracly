<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AgainstThreeOne extends AgainstOneGoal
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Contra 3x1 ou 1x3';

    protected static ?string $title = 'Contra 3x1 ou 1x3';

    protected static string | UnitEnum | null $navigationGroup = 'Estratégias';

    protected static ?int $navigationSort = 7;

    public function getStrategyScoresLabelProperty(): string
    {
        return '3x1 ou 1x3';
    }

    public function getStrategyDescriptionProperty(): string
    {
        return 'Para cada jogo, escolhemos o menos provável entre 3x1 e 1x3 pelas médias de gols pré-jogo. Os cards medem separadamente o resultado no FT e no HT.';
    }

    /** @return array<string, string> */
    protected function methodLabels(): array
    {
        return ['3-1' => 'Contra 3x1', '1-3' => 'Contra 1x3'];
    }

    /** @return array<string, string> */
    protected function halftimeScoreLabels(): array
    {
        return ['3-1' => 'HT 3x1', '1-3' => 'HT 1x3', '0-0' => 'HT 0x0'];
    }

    protected function strategy(): AgainstOneGoalStrategy
    {
        return app(AgainstThreeOneStrategy::class);
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-contra-3x1-ou-1x3.csv';
    }
}
