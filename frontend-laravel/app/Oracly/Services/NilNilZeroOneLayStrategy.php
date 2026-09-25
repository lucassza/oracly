<?php

namespace App\Oracly\Services;

use App\Oracly\Support\LayPricing;

/**
 * LAY 0x0 + LAY 0x1 no mesmo jogo, sobre os sinais LAY 0x1 do Punter (casa favorita 1,41–1,69).
 *
 * As duas pernas têm a mesma responsabilidade R e ficam no mesmo mercado (Placar Correto), então
 * a exchange limita o risco a R: só um dos dois placares pode sair. Laydando o 0x0 à odd A e o
 * 0x1 à odd B, as stakes são R/(A−1) e R/(B−1):
 *
 *   outro placar → +(stake 0x0 + stake 0x1) × (1 − comissão)
 *   0x0          → −R + stake 0x1
 *   0x1          → −R + stake 0x0
 *
 * Medido sobre os sinais apurados (melhor da hora, 0x0 a 11 e 0x1 a 14,5): 2025 com 1.867
 * entradas, 90,1% de green e +5,65% por entrada; 2026 até 06/09 com 1.002, 90,3% e +5,84%.
 * O 0x0 saiu em ~5,6% e o 0x1 em ~4,6% desses jogos; o mercado paga como se fossem ~9,1% e
 * ~6,9%. A vantagem depende dessa diferença, então os tetos de odd abaixo são o que separa
 * entrar de pular: com 0x0 a 15 e 0x1 a 17 o retorno cai para ~+2%.
 *
 * Tudo por unidade de responsabilidade (R = 1), como LayPricing.
 */
final class NilNilZeroOneLayStrategy
{
    /** Odds usadas quando o operador ainda não registrou a da exchange. Vistas na Betfair em 23/09/2026. */
    public const DEFAULT_ODDS = ['nil' => 11.0, 'one' => 14.5];

    /** Acima disso a vantagem medida some rápido (ver docblock da classe). */
    public const MAX_ODDS = ['nil' => 13.0, 'one' => 15.0];

    /** @var array<string, string> */
    public const LEG_LABELS = ['nil' => '0x0', 'one' => '0x1'];

    /** @return array{nil: float, one: float} */
    public static function stakes(float $liability, float $oddNil, float $oddOne): array
    {
        return [
            'nil' => $liability / ($oddNil - 1),
            'one' => $liability / ($oddOne - 1),
        ];
    }

    /** @return 'green'|'red_nil'|'red_one'|null */
    public static function result(mixed $homeGoals, mixed $awayGoals): ?string
    {
        if (! is_numeric($homeGoals) || ! is_numeric($awayGoals)) {
            return null;
        }

        return match ([(int) $homeGoals, (int) $awayGoals]) {
            [0, 0] => 'red_nil',
            [0, 1] => 'red_one',
            default => 'green',
        };
    }

    /** Retorno realizado por unidade de responsabilidade. */
    public static function realizedReturn(string $result, float $oddNil, float $oddOne): float
    {
        $stakes = self::stakes(1.0, $oddNil, $oddOne);

        return match ($result) {
            'red_nil' => -1.0 + $stakes['one'],
            'red_one' => -1.0 + $stakes['nil'],
            default => ($stakes['nil'] + $stakes['one']) * (1 - LayPricing::COMMISSION),
        };
    }

    /**
     * Retorno esperado por unidade de responsabilidade, dadas as chances (0–1) de cada placar.
     */
    public static function expectedReturn(float $probNil, float $probOne, float $oddNil, float $oddOne): float
    {
        return (1 - $probNil - $probOne) * self::realizedReturn('green', $oddNil, $oddOne)
            + $probNil * self::realizedReturn('red_nil', $oddNil, $oddOne)
            + $probOne * self::realizedReturn('red_one', $oddNil, $oddOne);
    }

    /**
     * Veredito das odds digitadas: entra se o retorno esperado é positivo e as duas odds estão
     * dentro do teto; margem curta se é positivo mas alguma passou do teto.
     *
     * @return array{verdict: 'enter'|'thin'|'skip', expectedReturn: float}
     */
    public static function verdict(float $probNil, float $probOne, float $oddNil, float $oddOne): array
    {
        $expected = self::expectedReturn($probNil, $probOne, $oddNil, $oddOne);
        $withinMax = $oddNil <= self::MAX_ODDS['nil'] && $oddOne <= self::MAX_ODDS['one'];

        return [
            'verdict' => $expected <= 0 ? 'skip' : ($withinMax ? 'enter' : 'thin'),
            'expectedReturn' => $expected,
        ];
    }
}
