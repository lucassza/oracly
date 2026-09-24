<?php

namespace App\Oracly\Support;

/**
 * Preço de um lay de placar único: odd justa, odd máxima de entrada e retorno esperado.
 *
 * Tudo por unidade de RESPONSABILIDADE, não de stake: é o valor que sai da banca no red e o que
 * as simulações de juros compostos usaram. Laydando a odd O, o green paga 1/(O − 1) menos a
 * comissão e o red custa 1.
 */
final class LayPricing
{
    /** Comissão da exchange sobre o lucro líquido do mercado (Betfair Brasil). */
    public const COMMISSION = 0.065;

    /**
     * Fração da odd justa até onde a entrada compensa.
     *
     * Com 6,5% de comissão o empate fica em ~93,5% da justa; 90% deixa folga para o erro da própria
     * estimativa. Nas simulações do LAY 2x2 e 0x0 foi a faixa em que o crescimento com juros
     * compostos deixou de depender de sorte.
     */
    public const ENTRY_RATIO = 0.90;

    /** @param float|null $probability Chance do placar, em pontos percentuais. */
    public static function fairOdd(?float $probability): ?float
    {
        return $probability === null || $probability <= 0 ? null : 100 / $probability;
    }

    public static function maxEntryOdd(?float $fairOdd): ?float
    {
        return $fairOdd === null ? null : max(1.01, $fairOdd * self::ENTRY_RATIO);
    }

    /**
     * Retorno esperado por unidade de responsabilidade, em pontos percentuais.
     *
     * @param float $probability Chance do placar, em pontos percentuais.
     */
    public static function expectedReturn(float $probability, float $offeredOdd): ?float
    {
        if ($offeredOdd <= 1.0 || $probability <= 0 || $probability >= 100) {
            return null;
        }
        $p = $probability / 100;

        return ((1 - $p) * (1 - self::COMMISSION) / ($offeredOdd - 1) - $p) * 100;
    }

    /** Resultado realizado por unidade de responsabilidade: +ganho no green, −1 no red. */
    public static function realizedReturn(bool $green, float $offeredOdd): float
    {
        return $green ? (1 - self::COMMISSION) / ($offeredOdd - 1) : -1.0;
    }
}
