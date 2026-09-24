<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstFavouriteRoutStrategy;
use App\Oracly\Services\SingleScoreLayStrategy;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * LAY da goleada do favorito: o favorito não vence por 4 gols ou mais.
 *
 * Números e cortes em AgainstFavouriteRoutStrategy, reproduzidos por punter:backtest-lay-goleada.
 */
class LayGoleada extends LayPlacarUnico
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?string $navigationLabel = 'LAY Goleada';

    protected static ?string $title = 'LAY Goleada';

    protected static string | UnitEnum | null $navigationGroup = 'Operação diária';

    /** Logo acima de LayDoisADois (-4), sem renumerar o resto do grupo. */
    protected static ?int $navigationSort = -5;

    public function strategy(): SingleScoreLayStrategy
    {
        return app(AgainstFavouriteRoutStrategy::class);
    }

    protected function strategyKey(): string
    {
        return 'lay_goleada';
    }

    /**
     * Mesmo acerto dos outros perfis, mas o melhor bad run a 90% da odd justa: drawdown máximo de
     * 21,9 unidades contra 30,8 do balanced, e 11 meses sem novo topo contra 21. Ver
     * AgainstFavouriteRoutStrategy.
     */
    protected function defaultProfile(): string
    {
        return 'baseline';
    }

    public function getEyebrowProperty(): string
    {
        return 'Punter · lay do handicap −3,5 do favorito';
    }

    public function getStrategyDescriptionProperty(): string
    {
        return 'Laya a vitória do favorito por 4 gols ou mais. A chance de cada jogo sai das odds de 1X2 '
            .'e over 2,5 e bate com o histórico: no perfil de 4 a 15% o modelo previa 7,0% de goleada e '
            .'saiu 7,0%, acerto de 93,0% na validação temporal. Favorito muito forte é o pior jogo para '
            .'este lay: abaixo de 1,20 a goleada sai em 23% das vezes.';
    }

    public function getSelectionNoteProperty(): string
    {
        return 'Os perfis não aumentam o acerto, só escolhem a faixa de odd do lay (7 a 25). O lucro vem '
            .'inteiro da odd: na odd justa o lay perde a comissão. Mercado é o handicap asiático −3,5 do '
            .'favorito, não o "Any Other Win" do placar exato. Ao vivo, com o favorito 2 gols à frente no '
            .'intervalo a goleada sobe para 22% — é o ponto de sair.';
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-lay-goleada.csv';
    }
}
