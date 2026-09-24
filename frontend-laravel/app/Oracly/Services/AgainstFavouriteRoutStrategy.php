<?php

namespace App\Oracly\Services;

use App\Oracly\Support\MarketPoisson;

/**
 * LAY da goleada do favorito: o favorito NÃO vence por 4 gols ou mais.
 *
 * É o handicap asiático −3,5 do favorito. Não é o "Any Other Home/Away Win" do placar exato da
 * exchange, que conta 4x1, 4x2 e 4x3 e deixa de fora o 3x0 — quem operar esse mercado precisa
 * de outro backtest.
 *
 * A ODD DO FAVORITO SOZINHA JÁ DIZ QUASE TUDO, e ao contrário da intuição: quanto mais forte o
 * favorito, pior o lay. Medido em 44.229 partidas do Punter (2023-01 a 2026-09):
 *
 *   odd do favorito   goleada   odd justa
 *   1,01 – 1,20       23,2%      4,31
 *   1,35 – 1,50        9,3%     10,70
 *   1,50 – 1,70        6,2%     16,11
 *   ≥ 2,00             2,1%     48,25
 *
 * O POISSON DE 1X2 + OVER 2,5 (MarketPoisson) SEPARA ALÉM DA ODD. Dentro da faixa 1,50 – 1,70 o
 * tercil baixo do modelo teve 4,3% de goleada na validação e o alto 9,7%. E vem calibrado sem
 * ajuste: por decil, previsto e observado ficam a menos de 1pp de D3 a D10, no treino e na
 * validação (D9 8,10 / 8,25%; D10 15,19 / 14,57%). Em D1 e D2 superestima (1,36 / 1,09%), o que
 * baixa a odd justa — erra a favor de quem faz o lay. Por isso a chance por jogo sai crua.
 *
 * OS PERFIS NÃO DÃO VANTAGEM, DELIMITAM A RESPONSABILIDADE. Com o modelo calibrado, qualquer
 * faixa acerta o que ele prevê; a faixa só escolhe entre odd de lay 7 e 25 (split 70/30):
 *
 *   perfil     n        previsto   acerto   dev     val     odd justa
 *   baseline   16.717   7,03%      93,0%    93,0%   93,0%   14,25
 *   balanced   10.733   7,40%      92,5%    92,4%   92,7%   13,30
 *   strong      8.397   6,58%      93,2%    93,1%   93,5%   14,81
 *
 * Sem concentração por liga (a maior, MLS, tem 5,7% do balanced) e estável por ano (6,6% a 8,3%
 * de goleada contra 7,3% a 7,5% previstos).
 *
 * O LUCRO DEPENDE SÓ DA ODD OFERECIDA. Laydando exatamente a odd justa, o retorno é −0,50% por
 * unidade de responsabilidade (a comissão). Na simulação com juros compostos do balanced (1.000
 * entradas, sorteio por dia), 2% de responsabilidade leva a banca mediana a x0,88 sem vantagem,
 * x1,21 com o mercado exagerando a goleada em 20% e x1,53 com 35%. Acima de 5% o drawdown mediano
 * passa de 30% em todos os cenários. Os reds se agrupam: houve 16 goleadas em 100 entradas
 * seguidas, contra 7,5 esperadas.
 *
 * BAD RUN, 1 unidade de responsabilidade por entrada, laydando a 90% da odd justa, em ordem de data:
 *
 *   perfil     resultado   maior drawdown   maior tempo sem novo topo   pior janela de 100
 *   baseline   +80,7 u     21,9 u           4.894 entradas (~11 meses)  16 reds (esperado 7,0)
 *   balanced   +37,7 u     30,8 u           5.900 entradas (~21 meses)  16 reds (esperado 7,5)
 *   strong     +19,1 u     23,3 u           5.049 entradas (~24 meses)  14 reds (esperado 6,8)
 *
 * Por isso a tela abre no baseline. Na odd justa, sem vantagem, nenhum perfil volta ao topo desde
 * 2023. Parte da folga do baseline vem de o modelo superestimar a goleada nas faixas baixas.
 *
 * AO VIVO, O INTERVALO DECIDE. No balanced, favorito +2 no intervalo tem 21,7% de goleada (odd
 * justa 4,62) e +3 tem 56,4%; empate, 1,75%. Favorito +2 no intervalo é o ponto de sair.
 *
 * Cortes e números reproduzidos por `php artisan punter:backtest-lay-goleada`.
 */
final class AgainstFavouriteRoutStrategy implements SingleScoreLayStrategy
{
    /** Vitória do favorito por esta diferença ou mais é red. */
    public const MARGIN = 4;

    /** @var array<string, string> */
    public const PROFILES = [
        'baseline' => 'Chance 4 – 15%',
        'balanced' => 'Chance 5 – 12%',
        'strong' => 'Chance 5 – 9%',
    ];

    /** Faixa [piso, teto) da chance de goleada do modelo, em pontos percentuais, por perfil. */
    public const PROFILE_BANDS = [
        'baseline' => [4.0, 15.0],
        'balanced' => [5.0, 12.0],
        'strong' => [5.0, 9.0],
    ];

    /** Gols por time considerados na grade do Poisson. */
    private const MAX_GOALS = 10;

    public function label(): string
    {
        return 'goleada';
    }

    /** @return array<string, string> */
    public function profiles(): array
    {
        return self::PROFILES;
    }

    /**
     * Chance crua do Poisson — já sai calibrada, ver docblock da classe — e perfis numa passada só.
     *
     * @param array<string, mixed> $row
     * @return array{probability: ?float, profiles: list<string>}
     */
    public function evaluate(array $row): array
    {
        $probability = $this->probability($row);

        return [
            'probability' => $probability,
            'profiles' => array_values(array_filter(array_keys(self::PROFILE_BANDS), fn (string $profile): bool => self::probabilityMatchesProfile($probability, $profile))),
        ];
    }

    /**
     * Lado favorito pela menor odd de 1X2. Odds iguais não têm favorito.
     *
     * @param array<string, mixed> $row
     * @return 'home'|'away'|null
     */
    public function favouriteSide(array $row): ?string
    {
        $home = $row['oddHome'] ?? null;
        $away = $row['oddAway'] ?? null;
        if (! is_numeric($home) || ! is_numeric($away) || $home <= 1 || $away <= 1 || (float) $home === (float) $away) {
            return null;
        }

        return $home < $away ? 'home' : 'away';
    }

    /** @param array<string, mixed> $row */
    public function favouriteOdd(array $row): ?float
    {
        $side = $this->favouriteSide($row);

        return $side === null ? null : (float) $row[$side === 'home' ? 'oddHome' : 'oddAway'];
    }

    /**
     * Gols do favorito menos gols do azarão. Negativo quando o azarão vence.
     *
     * @param array<string, mixed> $row
     */
    public function favouriteMargin(array $row): ?int
    {
        $side = $this->favouriteSide($row);
        $home = $row['homeGoals'] ?? null;
        $away = $row['awayGoals'] ?? null;
        if ($side === null || ! is_numeric($home) || ! is_numeric($away)) {
            return null;
        }

        return $side === 'home' ? (int) $home - (int) $away : (int) $away - (int) $home;
    }

    /**
     * Red quando o favorito vence por MARGIN ou mais.
     *
     * @param array<string, mixed> $row
     * @return 'green'|'red'|null
     */
    public function result(array $row): ?string
    {
        $margin = $this->favouriteMargin($row);

        return $margin === null ? null : ($margin >= self::MARGIN ? 'red' : 'green');
    }

    /**
     * P(favorito vence por MARGIN+) do Poisson de 1X2 + over 2,5, em pontos percentuais. Não calibrada.
     *
     * @param array<string, mixed> $row
     */
    public function probability(array $row): ?float
    {
        $side = $this->favouriteSide($row);
        $goals = $side === null ? null : MarketPoisson::expectedGoals($row);
        if ($goals === null) {
            return null;
        }

        [$favourite, $underdog] = $side === 'home' ? [$goals['home'], $goals['away']] : [$goals['away'], $goals['home']];

        $total = 0.0;
        for ($against = 0; $against <= self::MAX_GOALS - self::MARGIN; $against++) {
            $pAgainst = MarketPoisson::poisson($against, $underdog);
            for ($for = $against + self::MARGIN; $for <= self::MAX_GOALS; $for++) {
                $total += $pAgainst * MarketPoisson::poisson($for, $favourite);
            }
        }

        return $total * 100;
    }

    /** @param array<string, mixed> $row */
    public function matchesProfile(array $row, string $profile): bool
    {
        return isset(self::PROFILE_BANDS[$profile]) && self::probabilityMatchesProfile($this->probability($row), $profile);
    }

    /** Mesmo corte de matchesProfile() para quem já tem a chance calculada. */
    public static function probabilityMatchesProfile(?float $probability, string $profile): bool
    {
        $band = self::PROFILE_BANDS[$profile] ?? null;

        return $band !== null && $probability !== null && $probability >= $band[0] && $probability < $band[1];
    }
}
