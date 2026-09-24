<?php

namespace App\Oracly\Services;

use App\Oracly\Support\MarketPoisson;

/**
 * LAY do 0x0, selecionado pela chance de vitória do favorito.
 *
 * O 0x0 é o único placar do projeto com PREÇO de mercado no histórico: under 0,5 gols é o mesmo
 * evento, e punter.match_history traz as duas odds. Isso permite medir o que nas outras telas é
 * só hipótese — se a seleção acerta mais do que o preço já espera.
 *
 * O QUE SEPARA É O FAVORITO FORTE. A margem da casa tirada proporcionalmente infla a chance do
 * lado caro, então até "todos os jogos" parece sair menos 0x0 do que o preço espera. A régua é a
 * razão saiu/esperado da faixa DIVIDIDA pela do controle (punter:backtest-lay-0x0, tabela 2,
 * split temporal 70/30):
 *
 *   recorte          treino   validação
 *   favorito ≥ 50%   0,86     0,90
 *   favorito ≥ 60%   0,72     0,87       enfraqueceu: a faixa 60–70% sozinha foi 1,03 na validação
 *   favorito ≥ 70%   0,62     0,48       segura e cresce; 758 jogos na validação, 1,98% de 0x0
 *
 * Por isso a tela abre no perfil strong. O balanced continua acima do controle, mas com metade
 * da vantagem que tinha no treino.
 *
 * FORMA RECENTE NÃO SOMA NADA. Um logístico com mercado + favorito + forma dos últimos 8 jogos
 * de cada time (gols, jogos sem marcar, 0x0) + 0x0 recente da liga melhorou o log-loss da
 * validação em 0,00001. Filtros de forma que brilhavam no treino pioraram na validação
 * ("favorito ≥ 60% + nenhum 0x0 recente": +2,15% → +0,29% de retorno). Por isso não entram.
 *
 * O PREÇO DO HISTÓRICO É DE CASA DE APOSTA (margem ~6%), não de exchange. A vantagem medida pode
 * ser um viés da casa que a exchange não tem. Só registrando a odd de lay da exchange dá para
 * saber — é para isso que a tela guarda a cotação digitada (LayOddQuote).
 *
 * CHANCE POR JOGO. A lista do dia (panel_fixtures) não tem odd de under 0,5, então a chance vem
 * do mesmo Poisson de 1X2 + over 2,5 do LAY 2x2 — P(0x0) = e^-(gols esperados) — recalibrada
 * com o favorito como segunda variável, porque o Poisson superestima o 0x0 justamente com
 * favorito forte (observado/previsto de 0,55 a 0,86 acima de 70%). Ajuste nos 70% mais antigos;
 * na validação, favorito ≥ 60% previu 4,16% e saiu 4,30%. No ≥ 70% previu 2,98% e saiu 2,12%:
 * superestima, o que joga a odd justa para BAIXO — erra a favor de quem lay.
 *
 * Cortes e números reproduzidos por `php artisan punter:backtest-lay-0x0`.
 */
final class AgainstNilNilStrategy implements SingleScoreLayStrategy
{
    public const SCORE = '0-0';

    /** @var array<string, string> */
    public const PROFILES = [
        'baseline' => 'Favorito ≥ 50%',
        'balanced' => 'Favorito ≥ 60%',
        'strong' => 'Favorito ≥ 70%',
    ];

    /** Piso da chance de vitória do favorito (1X2 sem margem, 0 a 1), por perfil. */
    public const PROFILE_CUTS = [
        'baseline' => 0.50,
        'balanced' => 0.60,
        'strong' => 0.70,
    ];

    /** logit(q) = a + b·logit(P Poisson de 0x0) + c·favorito. Ver docblock da classe. */
    private const CALIBRATION_INTERCEPT = 0.618659;

    private const CALIBRATION_SLOPE = 0.971381;

    private const CALIBRATION_FAVOURITE = -1.276852;

    public function label(): string
    {
        return str_replace('-', 'x', self::SCORE);
    }

    /** @return array<string, string> */
    public function profiles(): array
    {
        return self::PROFILES;
    }

    /** @param array<string, mixed> $row */
    public function favouriteProbability(array $row): ?float
    {
        return MarketPoisson::favouriteProbability($row);
    }

    /**
     * O perfil só olha o favorito, que a lista do dia tem em quase todo jogo. Jogo sem odd de
     * over 2,5 entra mesmo sem chance própria — a tela cai na odd justa do perfil.
     *
     * @param array<string, mixed> $row
     */
    public function matchesProfile(array $row, string $profile): bool
    {
        $cut = self::PROFILE_CUTS[$profile] ?? null;
        $favourite = $cut === null ? null : $this->favouriteProbability($row);

        return $favourite !== null && $favourite >= $cut;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{probability: ?float, profiles: list<string>}
     */
    public function evaluate(array $row): array
    {
        $favourite = $this->favouriteProbability($row);

        return [
            'probability' => $favourite === null ? null : $this->probability($row),
            'profiles' => $favourite === null ? [] : array_keys(array_filter(self::PROFILE_CUTS, fn (float $cut): bool => $favourite >= $cut)),
        ];
    }

    /**
     * P(0x0) do Poisson cru, em pontos percentuais.
     *
     * @param array<string, mixed> $row
     */
    public function scoreProbability(array $row): ?float
    {
        $goals = MarketPoisson::expectedGoals($row);

        return $goals === null ? null : exp(-($goals['home'] + $goals['away'])) * 100;
    }

    /**
     * P(0x0) calibrada, em pontos percentuais.
     *
     * @param array<string, mixed> $row
     */
    public function probability(array $row): ?float
    {
        $raw = $this->scoreProbability($row);
        $favourite = $this->favouriteProbability($row);
        if ($raw === null || $favourite === null) {
            return null;
        }

        return MarketPoisson::recalibrate(
            $raw / 100,
            self::CALIBRATION_INTERCEPT,
            self::CALIBRATION_SLOPE,
            self::CALIBRATION_FAVOURITE * $favourite,
        ) * 100;
    }

    /**
     * Chance de 0x0 que o preço de under 0,5 implica, margem tirada proporcionalmente, em pp.
     *
     * Só o histórico tem as duas odds. É a régua do backtest, não da tela.
     *
     * @param array<string, mixed> $row
     */
    public function marketProbability(array $row): ?float
    {
        $under = $row['oddUnder05'] ?? null;
        $over = $row['oddOver05'] ?? null;
        if (! is_numeric($under) || ! is_numeric($over) || (float) $under <= 1.0 || (float) $over <= 1.0) {
            return null;
        }

        return (1 / $under) / (1 / $under + 1 / $over) * 100;
    }

    /**
     * Verde quando o jogo não termina 0x0.
     *
     * @param array<string, mixed> $row
     * @return 'green'|'red'|null
     */
    public function result(array $row): ?string
    {
        $home = $row['homeGoals'] ?? null;
        $away = $row['awayGoals'] ?? null;
        if (! is_numeric($home) || ! is_numeric($away)) {
            return null;
        }

        return ((int) $home).'-'.((int) $away) === self::SCORE ? 'red' : 'green';
    }
}
