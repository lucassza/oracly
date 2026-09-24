<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * LAY do FAVORITO vencer sem sofrer gol, por 1 a 0 ou 2 a 0.
 *
 * É o lado onde o modelo trabalha: o filtro de ambas marcam vale +7,6pp na perna de 1 a 0,
 * a maior contribuição de qualquer corte em qualquer perna. Também é o de menor
 * responsabilidade — odd justa 15,17 contra 29,42 da zebra 2 a 0.
 *
 * A odd do favorito NÃO entra como filtro aqui: medida em faixas, a taxa de red oscila entre
 * 4,63% e 7,51% sem forma, e a margem de 95% de cada faixa engloba a média de 6,59%. É ruído.
 * O corte que decide nesta tela é o BTTS. Ver LayZebra, onde o inverso vale.
 */
class LayFavorito extends LayPlacarSeco
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'LAY Favorito a zero';

    protected static ?string $title = 'LAY favorito vence a zero';

    protected static string | UnitEnum | null $navigationGroup = 'Operação diária';

    /**
     * Sort negativo para ficar no topo do grupo sem renumerar as outras seis páginas de
     * "Operação diária" — DailyDecision já ocupa o 0. Esta é a primeiro da lista.
     */
    protected static ?int $navigationSort = -2;

    protected function side(): string
    {
        return 'favourite';
    }

    public function getSideDescriptionProperty(): string
    {
        return 'Laya o favorito vencer sem sofrer gol. O filtro é a probabilidade de ambas '
            .'marcarem, e é aqui que ele trabalha: a assertividade sobe de 85,8% para 93,4% '
            .'entre a faixa mais baixa e a mais alta, um ganho de 7,6 pontos que segura na '
            .'validação temporal.';
    }

    public function getRecommendationNoteProperty(): string
    {
        return 'A recomendada é a perna de 1 a 0, e não por acertar mais. Ao mesmo desconto '
            .'sobre a odd justa as duas rendem igual. Ela se destaca porque concentra o ganho '
            .'do filtro (+7,6 pontos contra +3,1 da perna de 2 a 0) e é a de menor '
            .'responsabilidade de todas as quatro: arrisca 14,2 para ganhar 1.';
    }

    protected function historyCsvFilename(): string
    {
        return 'historico-lay-favorito-a-zero.csv';
    }
}
