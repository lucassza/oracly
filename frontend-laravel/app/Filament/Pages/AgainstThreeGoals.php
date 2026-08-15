<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeGoalsStrategy;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AgainstThreeGoals extends AgainstOneGoal
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Contra 0x3 ou 3x0';

    protected static ?string $title = 'Contra 0x3 ou 3x0';

    protected static string | UnitEnum | null $navigationGroup = 'Estratégias';

    protected static ?int $navigationSort = 8;

    public function getStrategyScoresLabelProperty(): string
    {
        return '0x3 ou 3x0';
    }

    public function getStrategyDescriptionProperty(): string
    {
        return 'Para cada jogo, escolhemos o menos provável entre 0x3 e 3x0 pelas médias de gols pré-jogo. Os cards medem separadamente o resultado no FT e no HT.';
    }

    /** @return array<string, string> */
    protected function methodLabels(): array
    {
        return ['3-0' => 'Contra 3x0', '0-3' => 'Contra 0x3'];
    }

    /** @return array<string, string> */
    protected function halftimeScoreLabels(): array
    {
        return ['3-0' => 'HT 3x0', '0-3' => 'HT 0x3', '0-0' => 'HT 0x0'];
    }

    protected function strategy(): AgainstOneGoalStrategy
    {
        return app(AgainstThreeGoalsStrategy::class);
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-contra-0x3-ou-3x0.csv';
    }
}
