import type { SokkerProApi } from '../api/client.js';
import { extractHalfTimeDetailFields } from '../api/normalizer.js';
import type { PostgresMatchStore } from '../storage/postgres-store.js';
import type { NormalizedMatch } from '../types/schemas.js';
import { getLogger } from '../utils/logger.js';
import { ProgressBar } from '../utils/progress.js';

export interface BackfillHtOptions {
  concurrency?: number;
  delayMinMs?: number;
  delayMaxMs?: number;
  batchSize?: number;
}

export interface BackfillHtSummary {
  candidates: number;
  updatedSettled: number;
  updatedFeatures: number;
  missingHtScore: number;
  failed: number;
}

interface BackfillCandidate {
  providerMatchId: string;
  settled: NormalizedMatch;
  // Última snapshot coletada antes do kickoff, se existir — é nela que as médias de 1º
  // tempo/odds HT precisam ser gravadas para não vazar (ver getHalfTimeExclusionResults,
  // que só lê feature de snapshot com collectedAt < kickoffAt).
  preKickoff: NormalizedMatch | undefined;
}

const byCollectedAtAsc = (a: NormalizedMatch, b: NormalizedMatch): number => a.collectedAt.localeCompare(b.collectedAt);

function groupByFixture(matches: NormalizedMatch[]): Map<string, NormalizedMatch[]> {
  const groups = new Map<string, NormalizedMatch[]>();
  for (const match of matches) {
    if (!match.providerMatchId) continue;
    const list = groups.get(match.providerMatchId) ?? [];
    list.push(match);
    groups.set(match.providerMatchId, list);
  }
  return groups;
}

// Fixtures finalizados cujo snapshot settled ainda não tem placar HT por time — reexecutar
// o comando é seguro, quem já foi corrigido numa run anterior não aparece de novo aqui.
function findCandidates(snapshots: NormalizedMatch[]): BackfillCandidate[] {
  const candidates: BackfillCandidate[] = [];
  for (const [providerMatchId, fixtureSnapshots] of groupByFixture(snapshots)) {
    const sorted = [...fixtureSnapshots].sort(byCollectedAtAsc);
    const settled = [...sorted].reverse().find((match) => match.status === 'finished');
    const kickoffAt = settled?.kickoffAt;
    if (!settled || !kickoffAt) continue;
    if (settled.score?.halftimeHome !== undefined && settled.score?.halftimeAway !== undefined) continue;
    const preKickoff = [...sorted].reverse().find((match) => match.collectedAt < kickoffAt);
    candidates.push({ providerMatchId, settled, preKickoff });
  }
  return candidates;
}

// Refaz GET /fixture/{id} para jogos finalizados cujo placar HT por time nunca foi
// normalizado corretamente (ver normalizer.ts: scoresHT da lista é um total, não "H-A").
// Corrige o placar real no snapshot settled e anexa as médias de 1º tempo/odds HT ao
// snapshot pré-kickoff (quando existir) para viabilizar o backtest sem vazamento.
export async function backfillHalfTimeStats(
  store: PostgresMatchStore,
  api: SokkerProApi,
  options: BackfillHtOptions = {},
): Promise<BackfillHtSummary> {
  const logger = getLogger();
  const concurrency = options.concurrency ?? 6;
  const delayMinMs = options.delayMinMs ?? 200;
  const delayMaxMs = options.delayMaxMs ?? 600;
  const batchSize = options.batchSize ?? 500;

  const candidates = findCandidates(await store.getAllSnapshots());
  logger.info({ candidates: candidates.length }, 'backfill-ht: candidatos encontrados');

  const summary: BackfillHtSummary = { candidates: candidates.length, updatedSettled: 0, updatedFeatures: 0, missingHtScore: 0, failed: 0 };
  const progress = new ProgressBar(candidates.length || 1);
  let pending: NormalizedMatch[] = [];

  const flush = async (): Promise<void> => {
    if (!pending.length) return;
    await store.saveMatches(pending);
    pending = [];
  };

  for (let i = 0; i < candidates.length; i += concurrency) {
    const batch = candidates.slice(i, i + concurrency);

    const results = await Promise.all(batch.map(async (candidate) => {
      try {
        const detail = await api.getFixtureDetail(candidate.providerMatchId);
        return { candidate, fields: extractHalfTimeDetailFields(detail.data) };
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        logger.warn({ providerMatchId: candidate.providerMatchId, error: message }, 'backfill-ht: falha ao buscar detalhe');
        summary.failed++;
        return null;
      }
    }));

    for (const result of results) {
      if (!result) continue;
      const { candidate, fields } = result;

      if (fields.halftimeHome === undefined || fields.halftimeAway === undefined) {
        summary.missingHtScore++;
        continue;
      }

      const updatedSettled: NormalizedMatch = {
        ...candidate.settled,
        score: { ...candidate.settled.score, halftimeHome: fields.halftimeHome, halftimeAway: fields.halftimeAway },
      };
      summary.updatedSettled++;

      const preKickoff = candidate.preKickoff;
      if (!preKickoff || (!fields.firstHalf && !fields.oddsHalfTime)) {
        pending.push(updatedSettled);
        continue;
      }

      summary.updatedFeatures++;
      const updatedPreKickoff: NormalizedMatch = {
        ...preKickoff,
        statistics: fields.firstHalf ? { ...preKickoff.statistics, firstHalf: fields.firstHalf } : preKickoff.statistics,
        oddsHalfTime: fields.oddsHalfTime ? { ...fields.oddsHalfTime, collectedAt: preKickoff.collectedAt } : preKickoff.oddsHalfTime,
        backfilledHalfTimeStatsAt: new Date().toISOString(),
      };

      // (provider_match_id, collected_at) é a PK — um único INSERT não pode ter duas linhas
      // de VALUES batendo na mesma PK. Só colidiria se o snapshot settled também tivesse
      // collectedAt < kickoffAt (dado inconsistente, praticamente nunca acontece), mas
      // proteger explicitamente é mais seguro do que confiar nisso.
      if (preKickoff.collectedAt === candidate.settled.collectedAt) {
        pending.push({
          ...candidate.settled,
          score: updatedSettled.score,
          statistics: updatedPreKickoff.statistics,
          oddsHalfTime: updatedPreKickoff.oddsHalfTime,
          backfilledHalfTimeStatsAt: updatedPreKickoff.backfilledHalfTimeStatsAt,
        });
      } else {
        pending.push(updatedSettled, updatedPreKickoff);
      }
    }

    progress.update(batch.length);
    if (pending.length >= batchSize) await flush();

    if (i + concurrency < candidates.length) {
      const delay = Math.floor(Math.random() * (delayMaxMs - delayMinMs) + delayMinMs);
      await new Promise((resolve) => setTimeout(resolve, delay));
    }
  }

  await flush();

  logger.info(summary, 'backfill-ht: finalizado');
  return summary;
}
