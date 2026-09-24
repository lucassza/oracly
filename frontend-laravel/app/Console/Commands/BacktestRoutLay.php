<?php

namespace App\Console\Commands;

use App\Oracly\Services\AgainstFavouriteRoutStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\LayPricing;
use Illuminate\Console\Command;

/**
 * Mede o LAY da goleada do favorito (AgainstFavouriteRoutStrategy) sobre punter.match_history.
 *
 * Nenhuma fonte tem odd de handicap −3,5, então não existe retorno medido — só frequência e odd
 * justa. A tabela 7 simula a banca com juros compostos supondo que a exchange paga o lay a uma
 * odd X% abaixo da justa de cada jogo; o cenário "mercado justo" é o que acontece sem vantagem.
 */
class BacktestRoutLay extends Command
{
    protected $signature = 'punter:backtest-lay-goleada
        {--limit=60000 : Linhas lidas do match_history}
        {--split=0.7 : Fração mais antiga usada como desenvolvimento}
        {--top=15 : Ligas mostradas no breakdown}
        {--profile=balanced : Perfil usado nas tabelas 5 a 7}
        {--entries=1000 : Entradas por simulação de banca}
        {--sims=2000 : Simulações de banca por cenário}';

    protected $description = 'Mede o LAY da goleada do favorito (vitória por 4+) por faixa de odd e pelo Poisson de 1X2 + over 2,5, com simulação de banca';

    /** @var list<array{float, float}> */
    private const ODD_BANDS = [[1.01, 1.20], [1.20, 1.35], [1.35, 1.50], [1.50, 1.70], [1.70, 2.00], [2.00, 100.0]];

    /** Quanto o mercado exagera a chance de goleada, em fração: lay a 100 / (p · (1 + x)). */
    private const MARKET_EXCESS = [0.0, 0.10, 0.20, 0.35];

    /** Responsabilidade por entrada, em fração da banca do início do dia. */
    private const LIABILITY = [0.01, 0.02, 0.05];

    public function handle(PunterMatchPickService $picks): int
    {
        $strategy = new AgainstFavouriteRoutStrategy();
        $profile = (string) $this->option('profile');
        if (! isset(AgainstFavouriteRoutStrategy::PROFILES[$profile])) {
            $this->error('--profile precisa ser um de: '.implode(', ', array_keys(AgainstFavouriteRoutStrategy::PROFILES)).'.');

            return self::FAILURE;
        }

        // Uma passada só pelo Poisson: as tabelas leem 'model' em vez de refazer o ajuste.
        $rows = [];
        foreach ($picks->history((int) $this->option('limit')) as $row) {
            $margin = $strategy->favouriteMargin($row);
            if ($margin !== null) {
                $rows[] = [...$row, 'favouriteOdd' => $strategy->favouriteOdd($row), 'margin' => $margin, 'model' => $strategy->probability($row)];
            }
        }

        if ($rows === []) {
            $this->warn('Nenhuma partida apurada com favorito definido.');

            return self::SUCCESS;
        }

        // O split temporal do HitRateSummary faz array_slice cego: sem isto dev e val trocam.
        usort($rows, fn (array $a, array $b): int => $a['matchDate'] <=> $b['matchDate']);
        $cutDate = $rows[(int) floor(count($rows) * (float) $this->option('split'))]['matchDate'];
        $modelled = array_values(array_filter($rows, fn (array $row): bool => $row['model'] !== null));

        $this->info(sprintf('Punter · %d partidas com favorito e placar (%d com over 2,5) · %s a %s · validação a partir de %s.',
            count($rows), count($modelled), $rows[0]['matchDate'], $rows[count($rows) - 1]['matchDate'], $cutDate));

        $inProfile = array_values(array_filter($modelled, fn (array $row): bool => AgainstFavouriteRoutStrategy::probabilityMatchesProfile($row['model'], $profile)));

        $this->byOddBand($rows);
        $this->calibration($modelled, $cutDate);
        $this->modelInsideOddBand($modelled, $cutDate);
        $this->byProfile($modelled);
        $this->halfTime($inProfile, $profile);
        $this->breakdown($inProfile, $profile);
        $this->bankroll($inProfile, $profile);

        $this->newLine();
        $this->line('Nenhum retorno é medido: nenhuma fonte tem odd de handicap −3,5. A odd justa sai do Poisson de cada jogo;');
        $this->line(sprintf('entre só quando a exchange pagar até %.0f%% dela (LayPricing::ENTRY_RATIO). Comissão considerada: %.1f%%.', LayPricing::ENTRY_RATIO * 100, LayPricing::COMMISSION * 100));

        return self::SUCCESS;
    }

    /** Tabela 1 — o recorte que qualquer operador faz: só a odd do favorito. */
    private function byOddBand(array $rows): void
    {
        $split = (float) $this->option('split');
        $table = [];
        foreach (self::ODD_BANDS as [$low, $high]) {
            $slice = array_values(array_filter($rows, fn (array $row): bool => $row['favouriteOdd'] >= $low && $row['favouriteOdd'] < $high));
            $s = HitRateSummary::temporal($slice, $this->isHit(...), $split);
            $fair = LayPricing::fairOdd(100 - $s['overall']['hitRate']);
            $table[] = [
                $high >= 100 ? sprintf('≥ %.2f', $low) : sprintf('%.2f – %.2f', $low, $high),
                $s['overall']['entries'],
                sprintf('%.2f%%', 100 - $s['overall']['hitRate']),
                HitRateSummary::percent($s['overall']['hitRate']),
                HitRateSummary::percent($s['development']['hitRate']),
                HitRateSummary::percent($s['validation']['hitRate']),
                $fair === null ? '—' : number_format($fair, 2),
                $fair === null ? '—' : number_format(LayPricing::maxEntryOdd($fair), 2),
            ];
        }

        $this->newLine();
        $this->line(sprintf('1. Por odd do favorito, com split temporal %.0f/%.0f:', $split * 100, (1 - $split) * 100));
        $this->table(['Odd do favorito', 'Entradas', 'Goleada', 'Acerto', 'Desenvolvimento', 'Validação', 'Odd justa', 'Entre até'], $table);
    }

    /** Tabela 2 — a chance por jogo acompanha o que sai? Decis do modelo, treino e validação. */
    private function calibration(array $rows, string $cutDate): void
    {
        $sorted = $rows;
        usort($sorted, fn (array $a, array $b): int => $a['model'] <=> $b['model']);
        $size = intdiv(count($sorted), 10);

        $table = [];
        for ($decile = 0; $decile < 10; $decile++) {
            $slice = array_slice($sorted, $decile * $size, $decile === 9 ? null : $size);
            $line = ['D'.($decile + 1), sprintf('%.2f – %.2f%%', $slice[0]['model'], $slice[count($slice) - 1]['model'])];
            foreach ([fn (array $r): bool => $r['matchDate'] < $cutDate, fn (array $r): bool => $r['matchDate'] >= $cutDate] as $inPeriod) {
                $part = array_values(array_filter($slice, $inPeriod));
                $line[] = $part === [] ? '—' : sprintf('%.2f / %.2f%% (%d)', $this->mean($part, 'model'), $this->routRate($part), count($part));
            }
            $table[] = $line;
        }

        $this->newLine();
        $this->line('2. Calibração do Poisson (1X2 + over 2,5) por decil — previsto / saiu (entradas):');
        $this->table(['Decil', 'Faixa do modelo', 'Treino', 'Validação'], $table);
    }

    /** Tabela 3 — o modelo separa além da odd? Tercis do modelo dentro de cada faixa de odd. */
    private function modelInsideOddBand(array $rows, string $cutDate): void
    {
        $table = [];
        foreach (self::ODD_BANDS as [$low, $high]) {
            $slice = array_values(array_filter($rows, fn (array $row): bool => $row['favouriteOdd'] >= $low && $row['favouriteOdd'] < $high));
            usort($slice, fn (array $a, array $b): int => $a['model'] <=> $b['model']);
            $size = intdiv(count($slice), 3);
            $line = [$high >= 100 ? sprintf('≥ %.2f', $low) : sprintf('%.2f – %.2f', $low, $high)];
            for ($third = 0; $third < 3; $third++) {
                $part = array_slice($slice, $third * $size, $third === 2 ? null : $size);
                $train = array_values(array_filter($part, fn (array $r): bool => $r['matchDate'] < $cutDate));
                $validation = array_values(array_filter($part, fn (array $r): bool => $r['matchDate'] >= $cutDate));
                $line[] = sprintf('%.1f / %.1f / %.1f%%', $this->mean($part, 'model'), $this->routRate($train), $this->routRate($validation));
            }
            $table[] = $line;
        }

        $this->newLine();
        $this->line('3. Tercis do modelo dentro da faixa de odd — previsto / saiu no treino / saiu na validação:');
        $this->line('   Se os três tercis saíssem iguais, a odd do favorito bastaria e o over 2,5 não somaria nada.');
        $this->table(['Odd do favorito', 'Tercil baixo', 'Tercil do meio', 'Tercil alto'], $table);
    }

    /** Tabela 4 — perfis pela chance do modelo, com validação temporal e odd justa de coorte. */
    private function byProfile(array $rows): void
    {
        $split = (float) $this->option('split');
        $table = [];
        foreach (AgainstFavouriteRoutStrategy::PROFILES as $profile => $label) {
            $slice = array_values(array_filter($rows, fn (array $row): bool => AgainstFavouriteRoutStrategy::probabilityMatchesProfile($row['model'], $profile)));
            $s = HitRateSummary::temporal($slice, $this->isHit(...), $split);
            $fair = $s['overall']['hitRate'] === null ? null : LayPricing::fairOdd(100 - $s['overall']['hitRate']);
            $table[] = [
                $profile, $label, $s['overall']['entries'],
                sprintf('%.1f%%', $s['overall']['entries'] / count($rows) * 100),
                $slice === [] ? '—' : sprintf('%.2f%%', $this->mean($slice, 'model')),
                HitRateSummary::percent($s['overall']['hitRate']),
                HitRateSummary::percent($s['development']['hitRate']),
                HitRateSummary::percent($s['validation']['hitRate']),
                $fair === null ? '—' : number_format($fair, 2),
                $fair === null ? '—' : number_format(LayPricing::maxEntryOdd($fair), 2),
            ];
        }

        $this->newLine();
        $this->line(sprintf('4. Por perfil, com split temporal %.0f/%.0f:', $split * 100, (1 - $split) * 100));
        $this->table(['Perfil', 'Chance de goleada', 'Entradas', 'Fatia', 'Previsto', 'Acerto', 'Desenvolvimento', 'Validação', 'Odd justa', 'Entre até'], $table);
    }

    /** Tabela 5 — o placar do intervalo pelo lado do favorito. Leitura para segurar ou sair ao vivo. */
    private function halfTime(array $rows, string $profile): void
    {
        $groups = [];
        foreach ($rows as $row) {
            if ($row['htScore'] === null) {
                continue;
            }
            [$home, $away] = array_map('intval', explode('-', $row['htScore']));
            $lead = $row['oddHome'] < $row['oddAway'] ? $home - $away : $away - $home;
            $groups[match (true) {
                $lead < 0 => 'Azarão na frente',
                $lead === 0 => 'Empate',
                $lead >= 3 => 'Favorito +3 ou mais',
                default => "Favorito +{$lead}",
            }][] = $row;
        }

        $table = [];
        foreach (['Azarão na frente', 'Empate', 'Favorito +1', 'Favorito +2', 'Favorito +3 ou mais'] as $label) {
            $slice = $groups[$label] ?? [];
            $fair = $slice === [] ? null : LayPricing::fairOdd($this->routRate($slice));
            $table[] = [$label, count($slice), $slice === [] ? '—' : sprintf('%.2f%%', $this->routRate($slice)), $fair === null ? '—' : number_format($fair, 2)];
        }

        $this->newLine();
        $this->line("5. Perfil {$profile} pelo placar do intervalo, visto do favorito:");
        $this->table(['Intervalo', 'Entradas', 'Goleada', 'Odd justa'], $table);
    }

    /** Tabela 6 — concentração e previsto/saiu por liga e por ano. */
    private function breakdown(array $rows, string $profile): void
    {
        foreach ([['competition', 'Liga', (int) $this->option('top')], ['year', 'Ano', 10]] as [$field, $label, $max]) {
            $groups = [];
            foreach ($rows as $row) {
                $groups[$field === 'year' ? substr((string) $row['matchDate'], 0, 4) : ($row['competition'] ?: '—')][] = $row;
            }
            $table = [];
            foreach ($groups as $key => $slice) {
                $table[] = [$key, count($slice), sprintf('%.1f%%', count($slice) / max(1, count($rows)) * 100),
                    sprintf('%.2f%%', $this->mean($slice, 'model')), sprintf('%.2f%%', $this->routRate($slice))];
            }
            usort($table, fn (array $a, array $b): int => $field === 'year' ? ($a[0] <=> $b[0]) : ($b[1] <=> $a[1]));

            $this->newLine();
            $this->line("6. Perfil {$profile} por {$label}:");
            $this->table([$label, 'Entradas', 'Fatia', 'Previsto', 'Goleada'], array_slice($table, 0, $max));
        }
    }

    /**
     * Tabela 7 — banca com juros compostos.
     *
     * Sorteia DIAS inteiros do histórico, não jogos: os reds se concentram em rodadas, e sortear
     * jogo a jogo esconde isso.
     * A responsabilidade de todas as entradas do dia sai da banca do início do dia, porque os
     * jogos correm ao mesmo tempo.
     */
    private function bankroll(array $rows, string $profile): void
    {
        if ($rows === []) {
            return;
        }

        $days = [];
        foreach ($rows as $row) {
            $days[substr((string) $row['matchDate'], 0, 10)][] = [$this->isHit($row), $row['model']];
        }
        $days = array_values($days);
        $entries = (int) $this->option('entries');
        $sims = (int) $this->option('sims');

        $table = [];
        foreach (self::MARKET_EXCESS as $excess) {
            $flat = 0.0;
            foreach ($rows as $row) {
                $flat += LayPricing::realizedReturn($this->isHit($row), $this->layOdd($row['model'], $excess));
            }

            foreach (self::LIABILITY as $liability) {
                // Semente fixa por cenário: a tabela sai igual a cada execução.
                mt_srand(1);
                $finals = [];
                $drawdowns = [];
                for ($sim = 0; $sim < $sims; $sim++) {
                    $sample = [];
                    for ($count = 0; $count < $entries; $count += count($day)) {
                        $sample[] = $day = $days[mt_rand(0, count($days) - 1)];
                    }
                    [$finals[], $drawdowns[]] = $this->simulate($sample, $excess, $liability);
                }
                sort($finals);
                sort($drawdowns);
                $blocks = array_map(fn (array $block): array => $this->simulate($block, $excess, $liability), $this->realBlocks($days, $entries));

                $table[] = [
                    $excess === 0.0 ? 'Mercado justo' : sprintf('+%.0f%%', $excess * 100),
                    sprintf('%+.2f%%', $flat / count($rows) * 100),
                    sprintf('%.0f%%', $liability * 100),
                    sprintf('x%.2f', $finals[intdiv($sims, 2)]),
                    sprintf('x%.2f', $finals[intdiv($sims, 10)]),
                    sprintf('%.1f%%', count(array_filter($finals, fn (float $bank): bool => $bank < 0.5)) / $sims * 100),
                    sprintf('%.0f%%', $drawdowns[intdiv($sims, 2)] * 100),
                    sprintf('%.0f%%', $drawdowns[intdiv($sims * 9, 10)] * 100),
                    $blocks === [] ? '—' : sprintf('x%.2f / %.0f%%', min(array_column($blocks, 0)), max(array_column($blocks, 1)) * 100),
                ];
            }
        }

        // Pior janela real de 100 entradas seguidas: mede o agrupamento que o sorteio por dia tenta preservar.
        $outcomes = array_merge(...array_map(fn (array $day): array => array_column($day, 0), $days));
        $worstWindow = 0;
        for ($start = 0; $start + 100 <= count($outcomes); $start++) {
            $worstWindow = max($worstWindow, count(array_filter(array_slice($outcomes, $start, 100), fn (bool $green): bool => ! $green)));
        }

        $this->newLine();
        $this->line(sprintf('7. Perfil %s — banca com juros compostos, %d entradas, %d simulações sorteando dias do histórico.', $profile, $entries, $sims));
        $this->line('   Cenário = quanto o mercado exagera a chance de goleada do modelo em cada jogo. "Mercado justo" é operar sem vantagem.');
        $this->line(sprintf('   A regra de entrada (até %.0f%% da odd justa) equivale a exigir +%.0f%%.', LayPricing::ENTRY_RATIO * 100, (1 / LayPricing::ENTRY_RATIO - 1) * 100));
        $this->line(sprintf('   Última coluna: o histórico em ordem de data cortado em %d blocos de %d entradas — pior banca final / pior drawdown entre eles.', count($this->realBlocks($days, $entries)), $entries));
        $this->line(sprintf('   Pior sequência real: %d goleadas em 100 entradas seguidas, contra %.1f esperadas.', $worstWindow, $this->routRate($rows)));
        $this->table(['Cenário', 'Retorno por responsab.', 'Responsab.', 'Banca mediana', 'Pior 10%', 'Perde metade', 'Drawdown mediano', 'Drawdown pior 10%', 'Pior bloco real'], $table);
    }

    /**
     * Dias em ordem de data agrupados em blocos consecutivos de pelo menos $entries entradas.
     * A sobra do fim, menor que um bloco, fica de fora para não comparar banca de tamanhos diferentes.
     *
     * @param list<list<array{bool, float}>> $days
     * @return list<list<list<array{bool, float}>>>
     */
    private function realBlocks(array $days, int $entries): array
    {
        $blocks = [];
        $block = [];
        $count = 0;
        foreach ($days as $day) {
            $block[] = $day;
            $count += count($day);
            if ($count >= $entries) {
                $blocks[] = $block;
                $block = [];
                $count = 0;
            }
        }

        return $blocks;
    }

    /**
     * @param list<list<array{bool, float}>> $days
     * @return array{float, float} banca final (começa em 1) e drawdown máximo
     */
    private function simulate(array $days, float $excess, float $liability): array
    {
        $bank = 1.0;
        $peak = 1.0;
        $drawdown = 0.0;
        foreach ($days as $day) {
            $risk = $bank * $liability;
            foreach ($day as [$green, $probability]) {
                $bank += $risk * LayPricing::realizedReturn($green, $this->layOdd($probability, $excess));
            }
            if ($bank <= 0) {
                return [0.0, 1.0];
            }
            $peak = max($peak, $bank);
            $drawdown = max($drawdown, 1 - $bank / $peak);
        }

        return [$bank, $drawdown];
    }

    private function layOdd(float $probability, float $excess): float
    {
        return max(1.01, 100 / ($probability * (1 + $excess)));
    }

    private function isHit(array $row): bool
    {
        return $row['margin'] < AgainstFavouriteRoutStrategy::MARGIN;
    }

    /** @param list<array<string, mixed>> $rows */
    private function routRate(array $rows): float
    {
        return $rows === [] ? 0.0 : count(array_filter($rows, fn (array $row): bool => ! $this->isHit($row))) / count($rows) * 100;
    }

    /** @param list<array<string, mixed>> $rows */
    private function mean(array $rows, string $field): float
    {
        return $rows === [] ? 0.0 : array_sum(array_column($rows, $field)) / count($rows);
    }
}
