<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstNilNilStrategy;
use App\Oracly\Services\SingleScoreLayStrategy;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * LAY do 0x0, selecionado pela chance de vitória do favorito.
 *
 * Números e cortes em AgainstNilNilStrategy, reproduzidos por punter:backtest-lay-0x0.
 */
class LayZeroAZero extends LayPlacarUnico
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'LAY 0x0';

    protected static ?string $title = 'LAY 0x0';

    protected static string | UnitEnum | null $navigationGroup = 'Operação diária';

    /** Logo abaixo de LayDoisADois (-4) e acima de LayFavorito (-2). */
    protected static ?int $navigationSort = -3;

    public function strategy(): SingleScoreLayStrategy
    {
        return app(AgainstNilNilStrategy::class);
    }

    protected function strategyKey(): string
    {
        return 'lay_0x0';
    }

    /** Só o favorito ≥ 70% sustentou a vantagem sobre o preço na validação. Ver AgainstNilNilStrategy. */
    protected function defaultProfile(): string
    {
        return 'strong';
    }

    public function getStrategyDescriptionProperty(): string
    {
        return 'Laya o 0x0 nos jogos com favorito forte. Acima de 70% de chance de vitória o 0x0 sai '
            .'cerca de metade do que a odd de under 0,5 espera, já descontado o viés da margem, e isso '
            .'segurou na validação temporal. Acima de 60% a vantagem existe, mas enfraqueceu nos '
            .'jogos mais recentes.';
    }

    public function getSelectionNoteProperty(): string
    {
        return 'Forma recente dos times foi testada e não soma nada ao preço; por isso não há filtro '
            .'de forma. O preço do histórico é de casa de aposta, não de exchange: a vantagem só se '
            .'confirma registrando a odd de lay que a exchange oferece nestes jogos.';
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-lay-0x0.csv';
    }
}
