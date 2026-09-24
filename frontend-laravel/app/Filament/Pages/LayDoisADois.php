<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstTwoTwoStrategy;
use App\Oracly\Services\SingleScoreLayStrategy;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * LAY do placar exato 2x2, selecionado pela P(2x2) que as odds de 1X2 e over 2,5 implicam.
 *
 * Números e cortes em AgainstTwoTwoStrategy, reproduzidos por punter:backtest-lay-2x2.
 */
class LayDoisADois extends LayPlacarUnico
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'LAY 2x2';

    protected static ?string $title = 'LAY 2x2';

    protected static string | UnitEnum | null $navigationGroup = 'Operação diária';

    /** Acima de LayFavorito (-2) e LayZebra (-1), sem renumerar o resto do grupo. */
    protected static ?int $navigationSort = -4;

    public function strategy(): SingleScoreLayStrategy
    {
        return app(AgainstTwoTwoStrategy::class);
    }

    protected function strategyKey(): string
    {
        return 'lay_2x2';
    }

    public function getStrategyDescriptionProperty(): string
    {
        return 'Laya o 2x2 nos jogos em que as odds de 1X2 e over 2,5 apontam pouca chance dele: '
            .'jogo desequilibrado ou de poucos gols. No perfil de 4% o lay acerta 96,3% contra 94,7% '
            .'laydando em todo jogo, e segura na validação temporal (96,1%).';
    }

    public function getSelectionNoteProperty(): string
    {
        return 'O perfil corta na chance do modelo cru; a coluna mostra a chance já calibrada, '
            .'que sai um pouco acima porque o Poisson subestima o 2x2. Acertar muito não basta: '
            .'na odd justa, com 6,5% de comissão, o lay perde dinheiro. Só entre com odd até a '
            .'máxima indicada — e registre a odd da exchange para saber se ela costuma pagar isso.';
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-lay-2x2.csv';
    }
}
