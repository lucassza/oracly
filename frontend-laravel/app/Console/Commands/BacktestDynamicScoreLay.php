<?php

namespace App\Console\Commands;

use App\Oracly\Services\DynamicScoreLayStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\LayPricing;
use Illuminate\Console\Command;

/**
 * Mede o LAY de placar exato com placares escolhidos por partida (DynamicScoreLayStrategy).
 *
 * As razões observado/Poisson são aprendidas SÓ nos jogos antes de --cut e aplicadas nos jogos a
 * partir dele. Aprender e avaliar na mesma base escolheria o placar sabendo o resultado.
 *
 * "Retorno se a exchange pagar o Poisson" é hipotético: laydando cada perna à odd do modelo
 * (1 / chance Poisson), por unidade de responsabilidade, com a comissão de LayPricing. Não existe
 * odd de placar exato em nenhuma base — só as cotações registradas na tela dizem se esse preço
 * aparece de verdade.
 */
class BacktestDynamicScoreLay extends Command
{
    protected $signature = 'punter:backtest-lay-dinamico
        {--limit=60000 : Linhas lidas do match_history}
        {--cut=2025-07-01 : Data que separa aprendizado (antes) de validação (a partir)}';

    protected $description = 'Mede o LAY de placar exato com placares escolhidos por partida, contra a regra fixa 0x1+0x2';

    public function handle(PunterMatchPickService $picks, DynamicScoreLayStrategy $strategy): int
    {
        $cut = (string) $this->option('cut');
        $rows = array_values(array_filter(
            $picks->history((int) $this->option('limit')),
            fn (array $row): bool => is_numeric($row['homeGoals'] ?? null) && is_numeric($row['awayGoals'] ?? null)
                && $strategy->expectedGoals($row) !== null,
        ));
        $learning = array_values(array_filter($rows, fn (array $row): bool => $row['matchDate'] < $cut));
        $validation = array_values(array_filter($rows, fn (array $row): bool => $row['matchDate'] >= $cut));

        if ($learning === [] || $validation === []) {
            $this->warn('Sem jogos suficientes dos dois lados do corte.');

            return self::SUCCESS;
        }

        $ratios = $strategy->learnRatios($learning);
        $this->info(sprintf(
            '%d jogos · aprendizado %d (até %s) · validação %d · %d razões grupo×placar · comissão %.1f%%.',
            count($rows), count($learning), $cut, count($validation), count($ratios), LayPricing::COMMISSION * 100,
        ));

        $this->byProfile($strategy, $validation, $ratios);
        $this->topScores($strategy, $validation, $ratios);
        $this->worstCells($ratios);

        return self::SUCCESS;
    }

    /** Tabela 1 — perfis na validação, contra a regra fixa. */
    private function byProfile(DynamicScoreLayStrategy $strategy, array $validation, array $ratios): void
    {
        $table = [];
        foreach (DynamicScoreLayStrategy::PROFILES as $profile => $label) {
            $table[] = [$label, ...$this->summary($validation, fn (array $row): array => $strategy->pick($row, $profile, $ratios), $strategy)];
        }

        // Referência: 0x1 + 0x2 (zebra vence a zero) sempre que o favorito está entre 1,50 e 2,00.
        $fixed = function (array $row) use ($strategy, $ratios): array {
            $favouriteOdd = min((float) $row['oddHome'], (float) $row['oddAway']);
            if ($favouriteOdd < 1.50 || $favouriteOdd >= 2.00) {
                return [];
            }

            return array_values(array_filter(
                $strategy->candidates($row, $ratios),
                fn (array $c): bool => in_array($c['favouriteScore'], ['0-1', '0-2'], true),
            ));
        };
        $table[] = ['Fixa: 0x1 + 0x2, favorito 1,50–2,00', ...$this->summary($validation, $fixed, $strategy)];

        $this->newLine();
        $this->line('1. Validação por perfil (real/modelo < 1 = placar sai menos do que o Poisson prevê):');
        $this->table(['Regra', 'Partidas', 'Pernas', 'Acerto perna', 'Acerto partida', 'Real/modelo', 'Retorno se exchange = Poisson'], $table);
    }

    /** Tabela 2 — o que a escolha dinâmica de fato laydou. */
    private function topScores(DynamicScoreLayStrategy $strategy, array $validation, array $ratios): void
    {
        $counts = [];
        foreach ($validation as $row) {
            foreach ($strategy->pick($row, 'wide', $ratios) as $leg) {
                $key = $leg['favouriteScore'];
                $counts[$key] ??= ['legs' => 0, 'reds' => 0];
                $counts[$key]['legs']++;
                $counts[$key]['reds'] += $strategy->resultForLeg($row, $leg['score']) === 'red' ? 1 : 0;
            }
        }
        uasort($counts, fn (array $a, array $b): int => $b['legs'] <=> $a['legs']);

        $table = [];
        foreach ($counts as $score => $count) {
            $table[] = [str_replace('-', 'x', $score), $count['legs'], number_format((1 - $count['reds'] / $count['legs']) * 100, 1).'%'];
        }

        $this->newLine();
        $this->line('2. Placares escolhidos no perfil wide, o padrão da tela (favorito x zebra):');
        $this->table(['Placar', 'Pernas', 'Acerto'], $table);
    }

    /** Tabela 3 — onde o Poisson mais erra para cima, aprendido antes do corte. */
    private function worstCells(array $ratios): void
    {
        asort($ratios);
        $table = [];
        foreach (array_slice($ratios, 0, 12, true) as $key => $ratio) {
            [$strength, $level, $score] = explode('|', $key);
            $table[] = [$strength, $level, str_replace('-', 'x', $score), number_format($ratio, 2)];
        }

        $this->newLine();
        $this->line('3. Grupos × placares mais superestimados pelo Poisson (F<1,50 · M<2,00 · E≥2,00; gols lo<2,3 · mid<2,9 · hi):');
        $this->table(['Favorito', 'Gols esperados', 'Placar fav x zebra', 'Real/modelo'], $table);
    }

    /**
     * @param callable(array<string, mixed>): list<array{score: string, modelProbability: float}> $legsFor
     * @return list<string>
     */
    private function summary(array $rows, callable $legsFor, DynamicScoreLayStrategy $strategy): array
    {
        $matches = 0;
        $lostMatches = 0;
        $legs = 0;
        $reds = 0;
        $expected = 0.0;
        $return = 0.0;

        foreach ($rows as $row) {
            $picked = $legsFor($row);
            if ($picked === []) {
                continue;
            }
            $matches++;
            $lost = false;
            foreach ($picked as $leg) {
                $legs++;
                $green = $strategy->resultForLeg($row, $leg['score']) === 'green';
                $reds += $green ? 0 : 1;
                $lost = $lost || ! $green;
                $expected += $leg['modelProbability'] / 100;
                $return += LayPricing::realizedReturn($green, 100 / $leg['modelProbability']);
            }
            $lostMatches += $lost ? 1 : 0;
        }

        if ($legs === 0) {
            return ['0', '0', '—', '—', '—', '—'];
        }

        return [
            number_format($matches, 0, ',', '.'),
            number_format($legs, 0, ',', '.'),
            number_format((1 - $reds / $legs) * 100, 1).'%',
            number_format((1 - $lostMatches / $matches) * 100, 1).'%',
            number_format($reds / $expected, 3),
            sprintf('%+.2f%%', $return / $legs * 100),
        ];
    }
}
