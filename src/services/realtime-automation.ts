import { SokkerProApi } from '../api/client.js';
import { enrichMatchWithDetail, normalizeFixtureFromList } from '../api/normalizer.js';
import { getEnv } from '../config/env.js';
import { PostgresMatchStore } from '../storage/postgres-store.js';
import type { NormalizedMatch } from '../types/schemas.js';
import { getLogger } from '../utils/logger.js';
import { getBrasiliaDate, wait } from '../utils/time.js';

export interface RealtimeRefreshSummary {
  collectedAt: string;
  date: string;
  fixturesSeen: number;
  savedMatches: number;
  refreshedMatches: number;
  failedMatches: number;
}

export class RealtimeAutomationService {
  private readonly api: SokkerProApi;
  private readonly logger;

  constructor() {
    this.api = new SokkerProApi();
    this.logger = getLogger();
  }

  async refreshDate(date = getBrasiliaDate()): Promise<RealtimeRefreshSummary> {
    const env = getEnv();
    const collectedAt = new Date().toISOString();
    const fixturesResponse = await this.api.getFixtures(date);

    if (!fixturesResponse.success || !fixturesResponse.data) {
      throw new Error(`Failed to fetch fixtures for ${date}`);
    }

    const normalizedMatches = (fixturesResponse.data.sortedCategorizedFixtures ?? []).flatMap((category) =>
      (category.fixtures ?? []).map((fixture) =>
        normalizeFixtureFromList(fixture, category, collectedAt, 'America/Sao_Paulo'),
      ),
    );

    const store = new PostgresMatchStore({
      host: env.POSTGRES_HOST,
      port: env.POSTGRES_PORT,
      database: env.POSTGRES_DB,
      user: env.POSTGRES_USER,
      password: env.POSTGRES_PASSWORD,
      schema: env.POSTGRES_SCHEMA,
    });

    try {
      const storedCandidates = await store.getRefreshCandidates(
        collectedAt,
        env.REALTIME_LOOKBACK_HOURS,
        env.REALTIME_LOOKAHEAD_HOURS,
      );
      const refreshableMatches = normalizedMatches.filter((match) =>
        shouldRefreshMatch(match, collectedAt, env.REALTIME_LOOKBACK_HOURS, env.REALTIME_LOOKAHEAD_HOURS),
      );
      const candidatesById = new Set([
        ...storedCandidates.map((candidate) => candidate.providerMatchId),
        ...refreshableMatches.map((match) => match.providerMatchId ?? ''),
      ]);

      const detailedMatches = await this.enrichOpenMatches(
        normalizedMatches.filter((match) => match.providerMatchId && candidatesById.has(match.providerMatchId)),
        env.REALTIME_DETAIL_CONCURRENCY,
      );

      const detailedById = new Map(detailedMatches.map((match) => [match.providerMatchId, match] as const));
      const matchesToSave = normalizedMatches.map((match) =>
        (match.providerMatchId && detailedById.get(match.providerMatchId)) || match,
      );

      await store.saveLiveUpdates(matchesToSave);

      return {
        collectedAt,
        date,
        fixturesSeen: normalizedMatches.length,
        savedMatches: matchesToSave.length,
        refreshedMatches: detailedMatches.length,
        failedMatches: normalizedMatches.filter(
          (match) => match.providerMatchId && candidatesById.has(match.providerMatchId) && !detailedById.has(match.providerMatchId),
        ).length,
      };
    } finally {
      await store.close();
    }
  }

  async start(): Promise<never> {
    const env = getEnv();
    if (!env.REALTIME_UPDATE_ENABLED) {
      throw new Error('Realtime automation is disabled. Set REALTIME_UPDATE_ENABLED=true to run it continuously.');
    }

    const intervalMs = env.REALTIME_UPDATE_INTERVAL_SECONDS * 1000;
    this.logger.info(
      {
        intervalSeconds: env.REALTIME_UPDATE_INTERVAL_SECONDS,
        lookbackHours: env.REALTIME_LOOKBACK_HOURS,
        lookaheadHours: env.REALTIME_LOOKAHEAD_HOURS,
      },
      'Realtime automation started',
    );

    while (true) {
      try {
        const result = await this.refreshDate();
        this.logger.info(result, 'Realtime refresh completed');
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        this.logger.error({ error: message }, 'Realtime refresh failed');
      }

      await wait(intervalMs);
    }
  }

  private async enrichOpenMatches(
    matches: NormalizedMatch[],
    concurrency: number,
  ): Promise<NormalizedMatch[]> {
    const enriched: NormalizedMatch[] = [];

    for (let index = 0; index < matches.length; index += concurrency) {
      const batch = matches.slice(index, index + concurrency);
      const batchResults = await Promise.all(
        batch.map(async (match) => {
          if (!match.providerMatchId) return null;
          try {
            const detail = await this.api.getFixtureDetail(match.providerMatchId);
            return enrichMatchWithDetail(match, detail, undefined, match.collectedAt);
          } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.logger.warn({ fixtureId: match.providerMatchId, error: message }, 'Failed to refresh fixture detail');
            return null;
          }
        }),
      );

      enriched.push(...batchResults.filter((match): match is NormalizedMatch => Boolean(match)));
    }

    return enriched;
  }
}

const FINAL_STATUSES = new Set(['finished', 'after_extra_time', 'penalties', 'cancelled', 'awarded', 'walkover']);

function shouldRefreshMatch(
  match: NormalizedMatch,
  now: string,
  lookbackHours: number,
  lookaheadHours: number,
): boolean {
  if (!match.providerMatchId) return false;
  if (FINAL_STATUSES.has(match.status ?? '') || match.status === 'postponed' || match.status === 'abandoned') {
    return false;
  }

  if (!match.kickoffAt) {
    return match.status === 'live' || match.status === 'half_time';
  }

  const kickoffTimestamp = new Date(match.kickoffAt).getTime();
  const nowTimestamp = new Date(now).getTime();
  if (Number.isNaN(kickoffTimestamp) || Number.isNaN(nowTimestamp)) return false;

  return (
    kickoffTimestamp >= nowTimestamp - lookbackHours * 60 * 60 * 1000 &&
    kickoffTimestamp <= nowTimestamp + lookaheadHours * 60 * 60 * 1000
  );
}
