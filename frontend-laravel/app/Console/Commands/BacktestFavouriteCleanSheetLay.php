<?php

namespace App\Console\Commands;

use App\Oracly\Repositories\MatchSnapshotRepository;
use App\Oracly\Services\AgainstFavouriteCleanSheetStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\X7;
use Illuminate\Console\Command;

/**
 * Mede o LAY do placar em que o favorito vence a zero (1x0 e 2x0), filtrado por BTTS.
 *
 * Duas fontes independentes, porque o backtest e a lista diária não podem se alimentar da
 * mesma: punter.match_history tem 44 mil jogos com odd 1X2 e odd BTTS reais, mas
 * panel_fixtures (futuro) traz odd BTTS em só 22% das linhas e guarda 7 dias. O SokkerPRO tem
 * previsão BTTS e odd 1X2 para todo fixture, passado e futuro, no mesmo formato. `--source=sokker`
 * existe para confirmar que o ganho replica antes de a tela depender dessa base.
 *
 * RESULTADO DA RÉPLICA, medido: o SokkerPRO confirma o SENTIDO mas com resolução bem menor.
 * Punter (43.824 jogos, BTTS de mercado):  85,8 / 89,0 / 90,6 / 93,1 / 94,8% — discrimina em
 * toda a faixa. Sokker (3.414 jogos, previsão X7): 84,4 / 90,7 / 90,7 / 91,6 / 91,7% — o ganho
 * vem quase todo do balde de baixo (n=378) e a curva achata a partir de 50%, que é justamente
 * a faixa operável. Preço de mercado tem mais resolução que previsão de modelo.
 *
 * Consequência para a lista diária: o corte de BTTS do lado Sokker separa muito menos, então
 * uma tela alimentada por ele entrega perto de 91,6% e não os 93,4% do backtest. Medir de novo
 * quando a base do SokkerPRO passar de seis meses antes de prometer o número maior.
 *
 * Nenhum retorno é reportado: não existe odd de mercado de placar exato em nenhuma fonte. O
 * substituto é a coluna de odd justa de lay (ver tabela 4).
 */
class BacktestFavouriteCleanSheetLay extends Command
{
    protected $signature = 'punter:backtest-lay-favorito
        {--source=punter : punter (44k jogos, odd de mercado) ou sokker (previsão X7)}
        {--limit=60000 : Linhas lidas da fonte}
        {--split=0.7 : Fração mais antiga usada como desenvolvimento}
        {--top=15 : Ligas mostradas no breakdown}';

    protected $description = 'Mede o LAY de 1x0 e 2x0 do favorito filtrado por BTTS, contra a regra do azarão que roda hoje';

    /** @var list<float> */
    private const BTTS_GATES = [0.0, 50.0, 55.0, 60.0, 65.0];

    public function handle(PunterMatchPickService $picks, MatchSnapshotRepository $snapshots): int
    {
        $strategy = new AgainstFavouriteCleanSheetStrategy();
        $source = (string) $this->option('source');
        $limit = (int) $this->option('limit');

        $rows = match ($source) {
            'punter' => $picks->history($limit),
            'sokker' => $this->sokkerRows($snapshots, $limit),
            default => null,
        };

        if ($rows === null) {
            $this->error('--source precisa ser "punter" ou "sokker".');

            return self::FAILURE;
        }

        // Só partidas em que as duas pernas são avaliáveis: favorito definido e placar apurado.
        $rows = array_values(array_filter($rows, fn (array $row): bool => $strategy->portfolioResult($row) !== null
            && $strategy->bttsProbability($row) !== null));

        if ($rows === []) {
            $this->warn('Nenhuma partida apurada com favorito definido e BTTS disponível.');

            return self::SUCCESS;
        }

        // O split temporal do HitRateSummary faz array_slice cego: sem isto dev e val trocam.
        usort($rows, fn (array $a, array $b): int => ($a['matchDate'] ?? '') <=> ($b['matchDate'] ?? ''));

        $this->info(sprintf(
            'Fonte %s · %d partidas apuradas · %s a %s.',
            $source, count($rows), $rows[0]['matchDate'] ?? '—', $rows[count($rows) - 1]['matchDate'] ?? '—',
        ));

        $this->baseline($rows, $strategy);
        $this->byBttsGate($rows, $strategy);
        $this->byProfile($rows, $strategy);
        $this->fairOdds($rows, $strategy);
        $this->versusUnderdog($rows, $strategy);
        $this->breakdown($rows, $strategy);

        $this->newLine();
        $this->line('Nenhum retorno é reportado: não existe odd de mercado de placar exato em nenhuma das fontes.');
        $this->line('A odd justa de lay vem da frequência observada do placar. Entre só quando a odd oferecida for MENOR que ela.');

        return self::SUCCESS;
    }

    /** Tabela 1 — hipótese nula: as duas pernas sem filtro nenhum. */
    private function baseline(array $rows, AgainstFavouriteCleanSheetStrategy $strategy): void
    {
        $table = [];
        foreach (AgainstFavouriteCleanSheetStrategy::LEGS as $leg => $parts) {
            $summary = HitRateSummary::of($rows, fn (array $row): bool => $strategy->resultForLeg($row, $leg) === 'green');
            $table[] = [$parts['label'], $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate'])];
        }
        foreach (AgainstFavouriteCleanSheetStrategy::SIDES as $side => $label) {
            $c = HitRateSummary::of($rows, fn (array $row): bool => $strategy->portfolioResult($row, $side) === 'green');
            $table[] = ["Carteira do {$label} (2 pernas)", $c['entries'], $c['greens'], $c['reds'], HitRateSummary::percent($c['hitRate'])];
        }

        $this->newLine();
        $this->line('1. Linha de base, sem filtro de BTTS:');
        $this->table(['Perna', 'Entradas', 'Green', 'Red', 'Assertividade'], $table);
    }

    /** Tabela 2 — o corte que decide. Se não reproduzir o plano, a leitura está errada. */
    private function byBttsGate(array $rows, AgainstFavouriteCleanSheetStrategy $strategy): void
    {
        $table = [];
        foreach (self::BTTS_GATES as $index => $gate) {
            $upper = self::BTTS_GATES[$index + 1] ?? null;
            $slice = array_values(array_filter($rows, function (array $row) use ($strategy, $gate, $upper): bool {
                $btts = $strategy->bttsProbability($row);

                return $btts !== null && $btts >= $gate && ($upper === null || $btts < $upper);
            }));
            $table[] = [
                $upper === null ? sprintf('BTTS ≥ %.0f%%', $gate) : sprintf('BTTS %.0f-%.0f%%', $gate, $upper),
                count($slice),
                $this->rate($slice, fn (array $row): bool => $strategy->resultForLeg($row, 'fav1') === 'green'),
                $this->rate($slice, fn (array $row): bool => $strategy->resultForLeg($row, 'fav2') === 'green'),
                $this->rate($slice, fn (array $row): bool => $strategy->resultForLeg($row, 'dog1') === 'green'),
                $this->rate($slice, fn (array $row): bool => $strategy->resultForLeg($row, 'dog2') === 'green'),
            ];
        }

        $this->newLine();
        $this->line('2. Por faixa de BTTS — separa no favorito, quase nada no azarão:');
        $this->table(['Faixa', 'Entradas', '1x0 fav.', '2x0 fav.', '1x0 azarão', '2x0 azarão'], $table);
    }

    /** Tabela 3 — perfis com validação temporal. */
    private function byProfile(array $rows, AgainstFavouriteCleanSheetStrategy $strategy): void
    {
        $split = (float) $this->option('split');
        $table = [];
        foreach (array_keys(AgainstFavouriteCleanSheetStrategy::PROFILES) as $profile) {
            $slice = array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, $profile)));
            foreach (AgainstFavouriteCleanSheetStrategy::LEGS as $leg => $parts) {
                $s = HitRateSummary::temporal($slice, fn (array $row): bool => $strategy->resultForLeg($row, $leg) === 'green', $split);
                $table[] = [
                    $profile, $parts['label'], $s['overall']['entries'],
                    HitRateSummary::percent($s['overall']['hitRate']),
                    HitRateSummary::percent($s['development']['hitRate']),
                    HitRateSummary::percent($s['validation']['hitRate']),
                ];
            }
            $c = HitRateSummary::temporal($slice, fn (array $row): bool => $strategy->portfolioResult($row, 'favourite') === 'green', $split);
            $table[] = [
                $profile, 'Carteira do favorito', $c['overall']['entries'],
                HitRateSummary::percent($c['overall']['hitRate']),
                HitRateSummary::percent($c['development']['hitRate']),
                HitRateSummary::percent($c['validation']['hitRate']),
            ];
        }

        $this->newLine();
        $this->line(sprintf('3. Por perfil, com split temporal %.0f/%.0f:', $split * 100, (1 - $split) * 100));
        $this->table(['Perfil', 'Perna', 'Entradas', 'Total', 'Desenvolvimento', 'Validação'], $table);
    }

    /** Tabela 4 — a que o operador usa: abaixo de que odd o lay compensa. */
    private function fairOdds(array $rows, AgainstFavouriteCleanSheetStrategy $strategy): void
    {
        $table = [];
        foreach (array_keys(AgainstFavouriteCleanSheetStrategy::PROFILES) as $profile) {
            $slice = array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, $profile)));
            foreach (AgainstFavouriteCleanSheetStrategy::LEGS as $leg => $parts) {
                $s = HitRateSummary::of($slice, fn (array $row): bool => $strategy->resultForLeg($row, $leg) === 'green');
                $frequency = $s['hitRate'] === null ? null : 100 - $s['hitRate'];
                $fair = AgainstFavouriteCleanSheetStrategy::fairLayOdd($frequency);
                $table[] = [
                    $profile, $parts['label'], $s['entries'],
                    HitRateSummary::percent($s['hitRate']),
                    $frequency === null ? '—' : number_format($frequency, 2).'%',
                    $fair === null ? '—' : number_format($fair, 2),
                ];
            }
        }

        $this->newLine();
        $this->line('4. Frequência do placar e odd justa de lay (entre só ABAIXO dela):');
        $this->table(['Perfil', 'Perna', 'Entradas', 'Assertividade', 'Placar ocorre', 'Odd justa de lay'], $table);
    }

    /**
     * Tabela 5 — contra a regra que roda hoje.
     *
     * Duas comparações: a perna espelhada (azarão a zero, que é para onde a regra atual
     * converge) e o que AgainstOneGoalStrategy::choice() de fato escolhe nesta base. As duas
     * divergem porque o Poisson do lado Punter recebe média de gols com semântica trocada.
     */
    private function versusUnderdog(array $rows, AgainstFavouriteCleanSheetStrategy $strategy): void
    {
        $legacy = new \App\Oracly\Services\AgainstOneGoalStrategy();
        $table = [];
        foreach (array_keys(AgainstFavouriteCleanSheetStrategy::PROFILES) as $profile) {
            $slice = array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, $profile)));

            $favourite = HitRateSummary::of($slice, fn (array $row): bool => $strategy->resultForLeg($row, 'fav1') === 'green');
            $underdog = HitRateSummary::of($slice, fn (array $row): bool => $strategy->resultForLeg($row, 'dog1') === 'green');
            $poisson = HitRateSummary::of(
                array_values(array_filter($slice, fn (array $row): bool => $legacy->choice($row) !== null)),
                fn (array $row): bool => $this->legacyResult($legacy, $row) === 'green',
            );

            foreach ([
                ['Favorito a zero (perna barata)', $favourite],
                ['Azarão a zero (perna cara)', $underdog],
                ['AgainstOneGoalStrategy::choice()', $poisson],
            ] as [$label, $s]) {
                $fair = AgainstFavouriteCleanSheetStrategy::fairLayOdd($s['hitRate'] === null ? null : 100 - $s['hitRate']);
                $table[] = [$profile, $label, $s['entries'], HitRateSummary::percent($s['hitRate']), $fair === null ? '—' : number_format($fair, 2)];
            }
        }

        $this->newLine();
        $this->line('5. Perna barata contra a cara, nas mesmas partidas:');
        $this->table(['Perfil', 'Regra', 'Entradas', 'Assertividade', 'Odd justa de lay'], $table);
    }

    /** Tabela 6 — concentração por liga e por mês. */
    private function breakdown(array $rows, AgainstFavouriteCleanSheetStrategy $strategy): void
    {
        $slice = array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, 'balanced')));
        $isHit = fn (array $row): bool => $strategy->resultForLeg($row, 'fav1') === 'green';

        foreach ([['competition', 'Liga', (int) $this->option('top')], ['month', 'Mês', 60]] as [$field, $label, $max]) {
            $groups = [];
            foreach ($slice as $row) {
                $key = $field === 'month' ? substr((string) ($row['matchDate'] ?? '—'), 0, 7) : ($row['competition'] ?? '—');
                $groups[$key][] = $row;
            }
            $table = [];
            foreach ($groups as $key => $groupRows) {
                $s = HitRateSummary::of($groupRows, $isHit);
                $table[] = [$key, $s['entries'], sprintf('%.1f%%', $s['entries'] / max(1, count($slice)) * 100), HitRateSummary::percent($s['hitRate'])];
            }
            usort($table, fn (array $a, array $b): int => $field === 'month' ? ($a[0] <=> $b[0]) : ($b[1] <=> $a[1]));

            $this->newLine();
            $this->line("6. LAY 1x0 no perfil balanced, por {$label}:");
            $this->table([$label, 'Entradas', 'Fatia', 'Assertividade'], array_slice($table, 0, $max));
        }
    }

    /** @param array<string, mixed> $row */
    private function legacyResult(\App\Oracly\Services\AgainstOneGoalStrategy $legacy, array $row): ?string
    {
        $choice = $legacy->choice($row);
        if ($choice === null || ! is_numeric($row['homeGoals'] ?? null) || ! is_numeric($row['awayGoals'] ?? null)) {
            return null;
        }

        return ((int) $row['homeGoals']).'-'.((int) $row['awayGoals']) === $choice['score'] ? 'red' : 'green';
    }

    /** @param list<array<string, mixed>> $rows */
    private function rate(array $rows, callable $isHit): string
    {
        return HitRateSummary::percent(HitRateSummary::of($rows, $isHit)['hitRate']);
    }

    /**
     * Réplica no SokkerPRO, com a barreira anti-vazamento de sempre: features do último
     * snapshot coletado ANTES do pontapé inicial. O filtro é por collectedAt e nunca por
     * status, porque saveLiveUpdates mescla estatísticas para a frente e um snapshot pós-jogo
     * carrega a previsão pré-jogo parecendo limpo.
     *
     * @return list<array<string, mixed>>
     */
    private function sokkerRows(MatchSnapshotRepository $snapshots, int $limit): array
    {
        $rows = [];
        foreach (array_chunk($snapshots->finishedProviderIds($limit), 250) as $chunk) {
            $byFixture = [];
            foreach ($snapshots->allForProviderIds($chunk) as $match) {
                $id = $match['providerMatchId'] ?? null;
                if ($id) {
                    $byFixture[$id][] = $match;
                }
            }
            foreach ($byFixture as $matchSnapshots) {
                $row = $this->sokkerRowFor($matchSnapshots);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return array<string, mixed>|null
     */
    private function sokkerRowFor(array $snapshots): ?array
    {
        usort($snapshots, fn (array $a, array $b): int => ($a['collectedAt'] ?? '') <=> ($b['collectedAt'] ?? ''));

        $settled = null;
        foreach ($snapshots as $snapshot) {
            if (($snapshot['status'] ?? null) === 'finished') {
                $settled = $snapshot;
            }
        }
        $kickoffAt = $settled['kickoffAt'] ?? null;
        if ($settled === null || $kickoffAt === null) {
            return null;
        }

        $home = data_get($settled, 'score.home');
        $away = data_get($settled, 'score.away');
        if ($home === null || $away === null) {
            return null;
        }

        $feature = null;
        foreach ($snapshots as $snapshot) {
            if (($snapshot['collectedAt'] ?? '') < $kickoffAt && X7::pred($snapshot, 'btts_sim') !== null) {
                $feature = $snapshot;
            }
        }
        if ($feature === null) {
            return null;
        }

        return [
            'matchDate' => $kickoffAt,
            'competition' => $feature['competition'] ?? null,
            'homeTeam' => data_get($feature, 'homeTeam.name'),
            'awayTeam' => data_get($feature, 'awayTeam.name'),
            'oddHome' => data_get($feature, 'odds.home'),
            'oddAway' => data_get($feature, 'odds.away'),
            'bttsProbability' => X7::pred($feature, 'btts_sim'),
            'homeGoalsAverage' => data_get($feature, 'statistics.homeGoalsAverage'),
            'awayGoalsAverage' => data_get($feature, 'statistics.awayGoalsAverage'),
            'over25Probability' => X7::pred($feature, 'over_25_ft_over'),
            'combinedGoalsAverage' => data_get($feature, 'statistics.combinedGoalsAverage'),
            'homeGoals' => (int) $home,
            'awayGoals' => (int) $away,
        ];
    }
}
