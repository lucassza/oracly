<?php

namespace App\Oracly\Services;

use App\Oracly\Support\LayPricing;
use App\Oracly\Support\MarketPoisson;

/**
 * LAY de placar exato com os placares escolhidos por partida, e não fixos.
 *
 * A ideia: o Poisson independente das odds de 1X2 e over 2,5 (MarketPoisson) é uma aproximação
 * de como o mercado precifica placar exato, e ele erra de forma SISTEMÁTICA por tipo de jogo —
 * superestima a zebra vencendo por pouco contra favorito forte ou médio, subestima 2x1 e 2x2.
 * Em cada grupo de jogo (força do favorito × gols esperados) medimos quanto cada placar sai em
 * relação ao Poisson; na partida, laydamos os placares que o modelo mais superestima.
 *
 * Tudo em orientação FAVORITO x ZEBRA (o favorito é a menor odd 1X2, empate conta como casa) e
 * convertido para casa x fora só na saída.
 *
 * A CHANCE CALIBRADA de cada placar é Poisson × razão do grupo. É dela que sai a odd justa.
 * Mas a seleção não é "escolher o placar mais improvável" — isso já foi testado no LAY Placar
 * Exato e perdeu (83-85%). É escolher onde o modelo de mercado mais erra para cima.
 *
 * VALIDAÇÃO TEMPORAL (punter:backtest-lay-dinamico, 44.611 jogos; razões aprendidas nos 28.594
 * jogos antes de 2025-07-01, aplicadas nos 16.017 a partir dele):
 *
 *   perfil     partidas  pernas   acerto perna  acerto partida  real/modelo
 *   wide       14.224    14.224   94,7%         94,7%           0,837
 *   balanced    6.211     9.155   96,0%         94,1%           0,844
 *   strong      4.075     5.451   96,0%         94,7%           0,862
 *   fixa 0x1+0x2 fav 1,50–2,00    5.874  11.748  95,9%  91,9%   0,821
 *
 * O wide é o padrão da tela: mais volume, maior acerto por partida e mais folga contra o modelo
 * que os perfis de duas pernas. Ele laydou 0x1 (5.093), 0x2 (4.614), 2x0 (1.636), 0x0 (1.154),
 * 1x1 (1.095), 3x0 (429) e 1x2 (203) — é dinâmico de fato, mas a zebra vencendo por pouco domina.
 *
 * BTTS COMO TERCEIRA DIMENSÃO DO GRUPO foi testado e não entrou: com a faixa de BTTS o balanced
 * foi a 94,7% por partida, mas real/modelo ficou igual (0,845 × 0,844) — não achou placar mais
 * superestimado, só cortou volume. E panel_fixtures só traz o lado "sim" do BTTS.
 *
 * O QUE NÃO ESTÁ PROVADO: que a exchange precifica esses placares como o Poisson. Se pagasse, o
 * retorno seria só +0,4% a +0,7% por unidade de responsabilidade com 6,5% de comissão — o lucro
 * real vem de laydar ABAIXO da odd justa (LayPricing::ENTRY_RATIO). Não existe odd de placar
 * exato em nenhuma base do projeto; só as cotações registradas na tela (LayOddQuote) respondem.
 */
final class DynamicScoreLayStrategy
{
    /** Placares candidatos, em gols do favorito x gols da zebra. @var list<array{int, int}> */
    public const SCORES = [
        [0, 0], [1, 0], [0, 1], [1, 1], [2, 0], [0, 2], [2, 1], [1, 2],
        [2, 2], [3, 0], [3, 1], [1, 3], [0, 3], [3, 2], [2, 3],
    ];

    /** @var array<string, string> */
    public const PROFILES = [
        'wide' => '1 placar · modelo 15% acima',
        'balanced' => 'Até 2 placares · modelo 20% acima',
        'strong' => 'Até 2 placares · modelo 25% acima',
    ];

    /** @var array<string, array{legs: int, maxRatio: float, maxFairOdd: float}> */
    public const PROFILE_RULES = [
        'wide' => ['legs' => 1, 'maxRatio' => 0.85, 'maxFairOdd' => 30.0],
        'balanced' => ['legs' => 2, 'maxRatio' => 0.80, 'maxFairOdd' => 40.0],
        'strong' => ['legs' => 2, 'maxRatio' => 0.75, 'maxFairOdd' => 40.0],
    ];

    /** Jogos mínimos no grupo para a razão valer. */
    public const MIN_CELL_ENTRIES = 300;

    /** Pseudo-contagem que puxa a razão para 1 em placar raro. */
    private const RATIO_PRIOR = 5.0;

    /** @param array<string, mixed> $row */
    public function favouriteSide(array $row): ?string
    {
        $home = $this->validOdd($row['oddHome'] ?? null);
        $away = $this->validOdd($row['oddAway'] ?? null);
        if ($home === null || $away === null) {
            return null;
        }

        return $home <= $away ? 'home' : 'away';
    }

    /**
     * Gols esperados do favorito e da zebra, pelas odds.
     *
     * @param array<string, mixed> $row
     * @return array{favourite: float, underdog: float, side: string}|null
     */
    public function expectedGoals(array $row): ?array
    {
        $side = $this->favouriteSide($row);
        $goals = $side === null ? null : MarketPoisson::expectedGoals($row);
        if ($goals === null) {
            return null;
        }

        return $side === 'home'
            ? ['favourite' => $goals['home'], 'underdog' => $goals['away'], 'side' => $side]
            : ['favourite' => $goals['away'], 'underdog' => $goals['home'], 'side' => $side];
    }

    /**
     * Grupo do jogo: força do favorito × gols esperados.
     *
     * A faixa de BTTS foi testada como terceira dimensão e não entrou — ver o backtest.
     *
     * @param array<string, mixed> $row
     * @param array{favourite: float, underdog: float, side: string} $goals
     */
    public function cell(array $row, array $goals): string
    {
        $favouriteOdd = min((float) $row['oddHome'], (float) $row['oddAway']);
        $strength = $favouriteOdd < 1.50 ? 'F' : ($favouriteOdd < 2.00 ? 'M' : 'E');
        $total = $goals['favourite'] + $goals['underdog'];
        $level = $total < 2.3 ? 'lo' : ($total < 2.9 ? 'mid' : 'hi');

        return $strength.'|'.$level;
    }

    /**
     * Razão observado/Poisson por grupo e placar.
     *
     * @param iterable<array<string, mixed>> $rows jogos apurados
     * @return array<string, float> "grupo|f-z" => razão
     */
    public function learnRatios(iterable $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $final = $this->finalScore($row);
            $goals = $final === null ? null : $this->expectedGoals($row);
            if ($goals === null) {
                continue;
            }
            [$favouriteGoals, $underdogGoals] = $goals['side'] === 'home' ? $final : [$final[1], $final[0]];
            $cell = $this->cell($row, $goals);

            foreach (self::SCORES as [$f, $z]) {
                $key = "{$cell}|{$f}-{$z}";
                $totals[$key] ??= ['entries' => 0, 'hits' => 0, 'expected' => 0.0];
                $totals[$key]['entries']++;
                $totals[$key]['hits'] += $favouriteGoals === $f && $underdogGoals === $z ? 1 : 0;
                $totals[$key]['expected'] += MarketPoisson::poisson($f, $goals['favourite']) * MarketPoisson::poisson($z, $goals['underdog']);
            }
        }

        $ratios = [];
        foreach ($totals as $key => $total) {
            if ($total['entries'] >= self::MIN_CELL_ENTRIES) {
                $ratios[$key] = ($total['hits'] + self::RATIO_PRIOR) / ($total['expected'] + self::RATIO_PRIOR);
            }
        }

        return $ratios;
    }

    /**
     * Todos os placares com razão conhecida para o jogo.
     *
     * @param array<string, mixed> $row
     * @param array<string, float> $ratios
     * @return list<array{score: string, favouriteScore: string, modelProbability: float, ratio: float, probability: float, fairOdd: float}>
     *         score em casa-fora; probabilidades em pontos percentuais
     */
    public function candidates(array $row, array $ratios): array
    {
        $goals = $this->expectedGoals($row);
        if ($goals === null) {
            return [];
        }
        $cell = $this->cell($row, $goals);

        $candidates = [];
        foreach (self::SCORES as [$f, $z]) {
            $ratio = $ratios["{$cell}|{$f}-{$z}"] ?? null;
            $model = MarketPoisson::poisson($f, $goals['favourite']) * MarketPoisson::poisson($z, $goals['underdog']) * 100;
            if ($ratio === null || $model <= 0) {
                continue;
            }
            $probability = $model * $ratio;
            $candidates[] = [
                'score' => $goals['side'] === 'home' ? "{$f}-{$z}" : "{$z}-{$f}",
                'favouriteScore' => "{$f}-{$z}",
                'modelProbability' => $model,
                'ratio' => $ratio,
                'probability' => $probability,
                'fairOdd' => LayPricing::fairOdd($probability),
            ];
        }

        return $candidates;
    }

    /**
     * Pernas do perfil: os placares que o modelo mais superestima, dentro do limite de odd justa.
     *
     * @param array<string, mixed> $row
     * @param array<string, float> $ratios
     * @return list<array{score: string, favouriteScore: string, modelProbability: float, ratio: float, probability: float, fairOdd: float}>
     */
    public function pick(array $row, string $profile, array $ratios): array
    {
        $rule = self::PROFILE_RULES[$profile] ?? null;
        if ($rule === null) {
            return [];
        }

        $eligible = array_values(array_filter(
            $this->candidates($row, $ratios),
            fn (array $c): bool => $c['ratio'] <= $rule['maxRatio'] && $c['fairOdd'] <= $rule['maxFairOdd'],
        ));
        usort($eligible, fn (array $a, array $b): int => $a['ratio'] <=> $b['ratio']);

        return array_slice($eligible, 0, $rule['legs']);
    }

    /**
     * Green quando o placar final (casa-fora) é diferente do laydado; null se não apurado.
     *
     * @param array<string, mixed> $row
     * @return 'green'|'red'|null
     */
    public function resultForLeg(array $row, string $score): ?string
    {
        $final = $this->finalScore($row);
        if ($final === null) {
            return null;
        }

        return "{$final[0]}-{$final[1]}" === $score ? 'red' : 'green';
    }

    /** @param array<string, mixed> $row @return array{int, int}|null casa, fora */
    private function finalScore(array $row): ?array
    {
        $home = $row['homeGoals'] ?? null;
        $away = $row['awayGoals'] ?? null;

        return is_numeric($home) && is_numeric($away) ? [(int) $home, (int) $away] : null;
    }

    private function validOdd(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 1.0 ? (float) $value : null;
    }
}
