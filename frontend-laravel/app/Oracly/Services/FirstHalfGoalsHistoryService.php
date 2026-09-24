<?php

namespace App\Oracly\Services;

use App\Oracly\Repositories\MatchSnapshotRepository;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\X7;

/**
 * Histórico rotulado do mercado Over 0.5 HT, com as features que a FirstHalfGoalsStrategy consome.
 *
 * Existe separado de PredictionService::history() por dois motivos:
 *
 * 1. PredictionService.php:154 conta placar de HT ausente como 0-0, transformando ~28% das
 *    partidas encerradas em red. Aqui essas partidas são descartadas. Corrigir lá no lugar
 *    mudaria silenciosamente as taxas já exibidas em três páginas.
 * 2. Aquele serviço não emite as médias de 1º tempo, gols_1t_15_over nem a procedência
 *    de backfill.
 */
final class FirstHalfGoalsHistoryService
{
    public function __construct(private readonly MatchSnapshotRepository $snapshots) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 20000, bool $cached = true): array
    {
        if (! $cached) {
            return $this->build($limit);
        }

        return OraclyCache::remember(
            OraclyCache::key("fhg:hist:{$limit}"),
            fn () => $this->build($limit),
            300,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(int $limit): array
    {
        $rows = [];
        foreach (array_chunk($this->snapshots->finishedProviderIds($limit), 250) as $providerIds) {
            $byFixture = [];
            foreach ($this->snapshots->allForProviderIds($providerIds) as $match) {
                $id = $match['providerMatchId'] ?? null;
                if (! $id) {
                    continue;
                }
                $byFixture[$id][] = $match;
            }

            foreach ($byFixture as $providerMatchId => $snapshots) {
                $row = self::rowFor($snapshots);
                if ($row !== null) {
                    $rows[] = ['providerMatchId' => $providerMatchId, ...$row];
                }
            }
        }

        usort($rows, fn ($a, $b) => strcmp($b['kickoffAt'] ?? '', $a['kickoffAt'] ?? ''));

        return $rows;
    }

    /**
     * Reduz os snapshots de uma partida a uma linha rotulada. Puro, sem banco.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @return array<string, mixed>|null
     */
    public static function rowFor(array $snapshots): ?array
    {
        usort($snapshots, fn ($a, $b) => strcmp($a['collectedAt'] ?? '', $b['collectedAt'] ?? ''));

        $settled = null;
        foreach (array_reverse($snapshots) as $snapshot) {
            if (($snapshot['status'] ?? null) === 'finished') {
                $settled = $snapshot;
                break;
            }
        }

        $kickoffAt = $settled['kickoffAt'] ?? null;
        if (! $settled || ! $kickoffAt) {
            return null;
        }

        // Sem placar de HT por time não há rótulo. Tratar como 0-0 enviesaria a amostra
        // inteira para baixo, que é o defeito de PredictionService.php:154.
        $halftimeHome = data_get($settled, 'score.halftimeHome');
        $halftimeAway = data_get($settled, 'score.halftimeAway');
        if ($halftimeHome === null || $halftimeAway === null) {
            return null;
        }

        // Anti-vazamento: só snapshots coletados antes do apito inicial. saveLiveUpdates
        // mescla as estatísticas anteriores para a frente, então linhas pós-kickoff
        // carregam predições pré-kickoff e parecem limpas sem ser.
        $feature = null;
        foreach ($snapshots as $snapshot) {
            if (($snapshot['collectedAt'] ?? '') >= $kickoffAt) {
                continue;
            }
            if (X7::pred($snapshot, FirstHalfGoalsStrategy::PRED_KEY) !== null) {
                $feature = $snapshot;
            }
        }
        if ($feature === null) {
            return null;
        }

        $halftimeGoals = (int) $halftimeHome + (int) $halftimeAway;

        return [
            'kickoffAt' => $kickoffAt,
            'country' => $settled['country'] ?? null,
            'competition' => $settled['competition'] ?? null,
            'homeTeam' => data_get($settled, 'homeTeam.name', ''),
            'awayTeam' => data_get($settled, 'awayTeam.name', ''),
            'probability' => X7::pred($feature, FirstHalfGoalsStrategy::PRED_KEY),
            'over15HtProbability' => X7::pred($feature, 'gols_1t_15_over'),
            'over25Probability' => X7::pred($feature, 'over_25_ft_over'),
            'bttsProbability' => X7::pred($feature, 'btts_sim'),
            'cornersProbability' => X7::pred($feature, 'corners_ft_95_over'),
            'combinedGoalsAverage' => data_get($feature, 'statistics.combinedGoalsAverage'),
            'firstHalfHomeGoalsAverage' => data_get($feature, 'statistics.firstHalf.homeGoalsAverage'),
            'firstHalfAwayGoalsAverage' => data_get($feature, 'statistics.firstHalf.awayGoalsAverage'),
            'over05HtSeals' => data_get($feature, 'statistics.additional.x7Predictions.'.FirstHalfGoalsStrategy::PRED_KEY.'.selo'),
            // X7::odd devolve `oj`, a odd justa do modelo — NÃO é preço de mercado.
            // Nenhum ROI pode ser calculado a partir dela.
            'fairOdd' => X7::odd($feature, FirstHalfGoalsStrategy::PRED_KEY),
            'halftimeGoals' => $halftimeGoals,
            'halftimeHomeScore' => $halftimeHome,
            'halftimeAwayScore' => $halftimeAway,
            'homeScore' => data_get($settled, 'score.home'),
            'awayScore' => data_get($settled, 'score.away'),
            'hit' => $halftimeGoals >= 1,
            'usedBackfilledFeatures' => isset($feature['backfilledHalfTimeStatsAt'])
                || isset($feature['backfilledFromX7At']),
        ];
    }
}
