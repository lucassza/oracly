<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * LAY da ZEBRA vencer sem sofrer gol, por 1 a 0 ou 2 a 0.
 *
 * Acerta mais que o lado do favorito (94,63% e 96,60% contra 93,41% e 93,74%) e paga
 * proporcionalmente pior — ao mesmo desconto sobre a odd justa os dois lados rendem igual.
 * O que muda de verdade é a responsabilidade: a perna de 2 a 0 arrisca 28,4 para ganhar 1,
 * o dobro da de 1 a 0 do favorito.
 *
 * AQUI O MODELO QUASE NÃO TRABALHA, e a tela diz isso. O filtro de ambas marcam vale +2,0pp
 * na perna de 1 a 0 e −0,5pp na de 2 a 0: os 96,60% já existem sem nós, é só um placar raro.
 * Quem discrimina deste lado é a odd do favorito (ZEBRA_FAVOURITE_ODD_CUT), por isso esta é
 * a única das duas telas que expõe esse corte.
 */
class LayZebra extends LayPlacarSeco
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'LAY Zebra a zero';

    protected static ?string $title = 'LAY zebra vence a zero';

    protected static string | UnitEnum | null $navigationGroup = 'Operação diária';

    /**
     * Sort negativo para ficar no topo do grupo sem renumerar as outras seis páginas de
     * "Operação diária" — DailyDecision já ocupa o 0. Esta é a segundo da lista.
     */
    protected static ?int $navigationSort = -1;

    protected function side(): string
    {
        return 'underdog';
    }

    public function getSideDescriptionProperty(): string
    {
        return 'Laya a zebra vencer sem sofrer gol. Assertividade alta porque o placar já é '
            .'raro por natureza, não porque a seleção funcione: ambas marcam vale só 2 pontos '
            .'na perna de 1 a 0 e nada na de 2 a 0. Deste lado quem separa é a odd do '
            .'favorito, e o filtro dela está logo abaixo.';
    }

    public function getRecommendationNoteProperty(): string
    {
        return 'A recomendada é a perna de 2 a 0, por ser a mais rara: 96,6% no geral e 97,97% '
            .'com favorito abaixo de 1,90, estável na validação. Em troca, é a de maior '
            .'responsabilidade da tela — arrisca 28,4 para ganhar 1, contra 17,6 da perna de '
            .'1 a 0. Odd justa de 29,42 também é mais difícil de achar com desconto real.';
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-lay-zebra-a-zero.csv';
    }
}
