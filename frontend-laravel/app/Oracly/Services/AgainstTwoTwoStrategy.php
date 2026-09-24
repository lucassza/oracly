<?php

namespace App\Oracly\Services;

use App\Oracly\Support\MarketPoisson;

/**
 * LAY do placar exato 2x2, selecionado pela probabilidade de 2x2 que as odds de mercado implicam.
 *
 * O 2x2 depende de duas coisas ao mesmo tempo — jogo com gols E times equilibrados — e nenhuma
 * odd isolada captura as duas. Medido em 44.611 partidas do Punter (2023-01 a 2026-09), base de
 * 2x2 de 5,29%:
 *
 *   BTTS sim (cru) < 45%  2,65%   ≥ 65%  6,94%     só gols, ignora equilíbrio
 *   favorito ≥ 80%        1,70%   < 40%  5,74%     só equilíbrio, ignora gols
 *
 * Por isso a seleção passa por um Poisson: a odd de over 2,5 fixa o total esperado de gols, a
 * diferença entre as probabilidades de vitória da casa e do visitante (1X2 sem margem) fixa como
 * esse total se divide, e P(2x2) = P(casa = 2) · P(fora = 2). Por decil do modelo:
 *
 *   decil    previsto   observado   lay
 *   D1       2,94%      3,07%       96,9%
 *   D5       4,66%      5,54%       94,5%
 *   D10      6,38%      7,19%       92,8%
 *
 * Ordena bem nas pontas e achata no meio (D3 a D8 ficam entre 94,4% e 95,1%). O modelo subestima
 * o 2x2 (Poisson independente não captura a correlação entre os gols dos dois times), por isso a
 * odd justa NUNCA sai do modelo cru — sai da frequência observada no perfil, via fairLayOdd().
 *
 * VALIDAÇÃO TEMPORAL (punter:backtest-lay-2x2, split 70/30, 44.611 partidas, base 94,7%):
 *
 *   perfil     n        fatia   total   dev     val     odd justa
 *   baseline   17.437   39,1%   95,7%   95,8%   95,4%   23,00
 *   balanced   10.010   22,4%   96,3%   96,4%   96,1%   26,91
 *   strong      4.902   11,0%   96,8%   97,2%   96,0%   31,42
 *
 * Use balanced. O strong perde 1,2pp da desenvolvimento para a validação e termina empatado com
 * o balanced na validação, com metade do volume. Sem concentração por liga: a maior responde por
 * 13,7% das entradas do balanced.
 *
 * O BTTS NÃO SOMA NADA POR CIMA. Dentro do balanced: 96,4% com BTTS < 50%, 96,3% entre 50 e 55%,
 * e as faixas acima têm pouco volume e oscilam para os dois lados. O Poisson já absorve o que o
 * BTTS dizia. Não entra como filtro.
 *
 * ESTÁVEL NO TEMPO. Corte de 3,5% ano a ano (escala só over) contra a base do mesmo ano:
 *   2023  97,55% vs 94,96%   2024  97,18% vs 94,32%   2025  96,73% vs 94,94%   2026  96,81% vs 94,56%
 *
 * CONTRA O RADAR LAY 2x2 DO PUNTER. Nos 1.671 sinais que casam com o match_history por data e
 * times, o radar acerta 95,5%; o balanced, no mesmo período, 96,2% em 6.456 jogos. Dentro do
 * radar o modelo ainda separa: 96,0% nos sinais que passam no balanced, 95,1% nos que não passam.
 *
 * AO VIVO, O INTERVALO DECIDE MAIS QUE O PRÉ-JOGO. No balanced, com 0x0 no intervalo o lay acerta
 * 99,2% (n=3.322); com 1x1, 90,0%; com 1x2, 78,8%. Leitura para segurar ou sair da entrada.
 *
 * NÃO REPORTA RETORNO, pelo mesmo motivo de AgainstFavouriteCleanSheetStrategy: nenhuma fonte tem
 * odd de mercado de placar exato. Laydando a odd O com probabilidade p de o placar sair, o
 * retorno esperado por unidade é 1 − p·O. Só entra quando a odd oferecida está ABAIXO de 1/p.
 *
 * LISTA DO DIA: panel_fixtures traz odd de over 2,5 de ABERTURA em todo jogo com data (medido em
 * 2026-09-14: 365 de 365; as ~1.300 linhas restantes da tabela são linhas vazias da planilha, sem
 * data nem odd). Os cortes foram calibrados em odd de fechamento. Jogo sem odd de over 2,5 fica
 * de fora em vez de receber um chute.
 *
 * Cortes calibrados por `php artisan punter:backtest-lay-2x2`.
 */
final class AgainstTwoTwoStrategy implements SingleScoreLayStrategy
{
    public const SCORE = '2-2';

    /** @var array<string, string> */
    public const PROFILES = [
        'baseline' => 'P(2x2) < 4,5%',
        'balanced' => 'P(2x2) < 4,0%',
        'strong' => 'P(2x2) < 3,5%',
    ];

    /** Teto de P(2x2) do modelo CRU, em pontos percentuais, por perfil. Ver PROFILES. */
    public const PROFILE_CUTS = [
        'baseline' => 4.5,
        'balanced' => 4.0,
        'strong' => 3.5,
    ];

    /**
     * Recalibração logística do P(2x2) cru: logit(q) = a + b·logit(p).
     *
     * Ajustada só nos 70% mais antigos (31.227 partidas, 2023-01 a 2025-09) e conferida nos 30%
     * mais recentes, que o ajuste não viu — previsto 5,36% contra 5,46% observado, quintis de
     * 3,77/3,85% a 6,86/7,24%. Os perfis continuam cortando no modelo CRU, que é o que o
     * backtest mediu; a calibração só entra na odd justa do jogo.
     */
    private const CALIBRATION_INTERCEPT = 0.082788;

    private const CALIBRATION_SLOPE = 0.990657;

    public function label(): string
    {
        return str_replace('-', 'x', self::SCORE);
    }

    /** @return array<string, string> */
    public function profiles(): array
    {
        return self::PROFILES;
    }

    /**
     * Gols esperados de cada lado, implícitos nas odds de 1X2 e over 2,5.
     *
     * @param array<string, mixed> $row
     * @return array{home: float, away: float}|null
     */
    public function expectedGoals(array $row): ?array
    {
        return MarketPoisson::expectedGoals($row);
    }

    /**
     * P(2x2) do modelo, em pontos percentuais. Não calibrado — ver docblock da classe.
     *
     * @param array<string, mixed> $row
     */
    public function scoreProbability(array $row): ?float
    {
        $goals = $this->expectedGoals($row);
        if ($goals === null) {
            return null;
        }

        return MarketPoisson::poisson(2, $goals['home']) * MarketPoisson::poisson(2, $goals['away']) * 100;
    }

    /**
     * P(2x2) calibrada contra a frequência observada, em pontos percentuais.
     *
     * @param array<string, mixed> $row
     */
    public function probability(array $row): ?float
    {
        $raw = $this->scoreProbability($row);

        return $raw === null ? null : $this->calibrate($raw);
    }

    /** Aplica a calibração a um P(2x2) cru já calculado, para quem não quer refazer o Poisson. */
    public function calibrate(float $rawProbability): float
    {
        return MarketPoisson::recalibrate($rawProbability / 100, self::CALIBRATION_INTERCEPT, self::CALIBRATION_SLOPE) * 100;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{probability: ?float, profiles: list<string>}
     */
    public function evaluate(array $row): array
    {
        $raw = $this->scoreProbability($row);
        if ($raw === null) {
            return ['probability' => null, 'profiles' => []];
        }

        return [
            'probability' => $this->calibrate($raw),
            'profiles' => array_keys(array_filter(self::PROFILE_CUTS, fn (float $cut): bool => $raw < $cut)),
        ];
    }

    /** @param array<string, mixed> $row */
    public function matchesProfile(array $row, string $profile): bool
    {
        $cut = self::PROFILE_CUTS[$profile] ?? null;
        $probability = $cut === null ? null : $this->scoreProbability($row);

        return $probability !== null && $probability < $cut;
    }

    /**
     * Verde quando o placar final não é 2x2.
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

    /** Mesma fórmula de AgainstFavouriteCleanSheetStrategy::fairLayOdd(): frequência em pp → odd. */
    public static function fairLayOdd(?float $scoreFrequency): ?float
    {
        return AgainstFavouriteCleanSheetStrategy::fairLayOdd($scoreFrequency);
    }
}
