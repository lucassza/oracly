import pg from 'pg';
import { afterEach, describe, expect, it } from 'vitest';
import { PostgresMatchStore } from '../src/storage/postgres-store.js';
import { getEnv } from '../src/config/env.js';

// Each test gets its own throwaway Postgres schema so runs stay isolated from
// each other and from real data, against the actual configured server.
const schemasToClean: string[] = [];
let schemaCounter = 0;

function createStore(): PostgresMatchStore {
  const env = getEnv();
  schemaCounter += 1;
  const schema = `test_store_${process.pid}_${schemaCounter}`;
  schemasToClean.push(schema);
  return new PostgresMatchStore({
    host: env.POSTGRES_HOST,
    port: env.POSTGRES_PORT,
    database: env.POSTGRES_DB,
    user: env.POSTGRES_USER,
    password: env.POSTGRES_PASSWORD,
    schema,
  });
}

afterEach(async () => {
  const env = getEnv();
  const client = new pg.Client({
    host: env.POSTGRES_HOST,
    port: env.POSTGRES_PORT,
    database: env.POSTGRES_DB,
    user: env.POSTGRES_USER,
    password: env.POSTGRES_PASSWORD,
  });
  await client.connect();
  for (const schema of schemasToClean.splice(0)) {
    await client.query(`DROP SCHEMA IF EXISTS "${schema}" CASCADE`);
  }
  await client.end();
});

describe('PostgresMatchStore', () => {
  it('returns upcoming Over 0.5 FT predictions from the latest match snapshot', async () => {
    const store = createStore();
    await store.saveMatches([
      {
        provider: 'sokkerpro',
        providerMatchId: 'fixture-1',
        sourceUrl: 'https://sokkerpro.com/fixture/fixture-1',
        collectedAt: '2026-07-24T10:00:00.000Z',
        kickoffAt: '2026-07-24T18:00:00.000Z',
        status: 'not_started',
        competition: 'Test League',
        homeTeam: { name: 'Home' },
        awayTeam: { name: 'Away' },
        statistics: {
          combinedGoalsAverage: 3.1,
          over05Percentage: 82,
          bttsPercentage: 64,
          additional: {
            x7Predictions: {
              over_05_ft_over: { pred: 91, oj: 1.12 },
              gols_1t_05_over: { pred: 84, oj: 1.5 },
            },
          },
        },
      },
    ]);

    const predictions = await store.getUpcomingOver05FtPredictions('2026-07-24T12:00:00.000Z');

    expect(predictions).toEqual([
      expect.objectContaining({
        providerMatchId: 'fixture-1',
        probability: 91,
        modelOdd: 1.12,
        homeTeam: 'Home',
      }),
    ]);
    await store.close();
  });

  it('returns historical Over 0.5 HT accuracy from pre-kickoff snapshots', async () => {
    const store = createStore();
    const baseMatch = {
      provider: 'sokkerpro' as const,
      providerMatchId: 'fixture-ht',
      sourceUrl: 'https://sokkerpro.com/fixture/fixture-ht',
      kickoffAt: '2026-07-24T18:00:00.000Z',
      competition: 'Test League',
      homeTeam: { name: 'Home' },
      awayTeam: { name: 'Away' },
    };
    await store.saveMatches([
      {
        ...baseMatch,
        collectedAt: '2026-07-24T10:00:00.000Z',
        status: 'not_started',
        statistics: { additional: { x7Predictions: { gols_1t_05_over: { pred: 82, oj: 1.53 } } } },
      },
      {
        ...baseMatch,
        collectedAt: '2026-07-24T20:00:00.000Z',
        status: 'finished',
        score: { home: 2, away: 0, halftimeHome: 1, halftimeAway: 0 },
        statistics: { goals: [{ minute: 19, team: 'home' }] },
      },
    ]);

    expect(await store.getUpcomingOver05HtPredictions('2026-07-24T12:00:00.000Z')).toHaveLength(0);
    expect(await store.getHistoricalOver05HtResults()).toEqual([
      expect.objectContaining({ providerMatchId: 'fixture-ht', probability: 82, halftimeGoals: 1, hit: true, ftHit: true, by75Hit: true }),
    ]);
    await store.close();
  });

  it('returns historical Over 0.5 FT results with HT and 75-minute greens', async () => {
    const store = createStore();
    const baseMatch = {
      provider: 'sokkerpro' as const,
      sourceUrl: 'https://sokkerpro.com/fixture/fixture-ft',
      kickoffAt: '2026-07-24T18:00:00.000Z',
      competition: 'Test League',
      homeTeam: { name: 'Home' },
      awayTeam: { name: 'Away' },
    };
    const fixture = (
      providerMatchId: string,
      pred: number,
      score: { home: number; away: number; halftimeHome: number; halftimeAway: number },
      goals: { minute: number; team: string }[],
    ) => [
      {
        ...baseMatch,
        providerMatchId,
        collectedAt: '2026-07-24T10:00:00.000Z',
        status: 'not_started',
        statistics: { additional: { x7Predictions: { over_05_ft_over: { pred, oj: 1.1 } } } },
      },
      {
        ...baseMatch,
        providerMatchId,
        collectedAt: '2026-07-24T20:00:00.000Z',
        status: 'finished',
        score,
        statistics: { goals },
      },
    ];
    await store.saveMatches([
      ...fixture('fixture-ft-early', 90, { home: 2, away: 0, halftimeHome: 1, halftimeAway: 0 }, [{ minute: 19, team: 'home' }, { minute: 60, team: 'home' }]),
      ...fixture('fixture-ft-late', 85, { home: 1, away: 0, halftimeHome: 0, halftimeAway: 0 }, [{ minute: 80, team: 'home' }]),
      ...fixture('fixture-ft-zero', 80, { home: 0, away: 0, halftimeHome: 0, halftimeAway: 0 }, []),
    ]);

    expect(await store.getHistoricalOver05FtResults()).toEqual(expect.arrayContaining([
      expect.objectContaining({ providerMatchId: 'fixture-ft-early', probability: 90, hit: true, htHit: true, by75Hit: true }),
      expect.objectContaining({ providerMatchId: 'fixture-ft-late', probability: 85, hit: true, htHit: false, by75Hit: false }),
      expect.objectContaining({ providerMatchId: 'fixture-ft-zero', probability: 80, hit: false, htHit: false, by75Hit: false }),
    ]));
    await store.close();
  });

  it('returns 0x0-at-30 pattern results, excluding early goals and missing timelines', async () => {
    const store = createStore();
    const baseMatch = {
      provider: 'sokkerpro' as const,
      sourceUrl: 'https://sokkerpro.com/fixture/fixture-z30',
      kickoffAt: '2026-07-24T18:00:00.000Z',
      competition: 'Test League',
      homeTeam: { name: 'Home' },
      awayTeam: { name: 'Away' },
    };
    const fixture = (
      providerMatchId: string,
      score: { home: number; away: number; halftimeHome: number; halftimeAway: number },
      goals: { minute: number; team: string }[],
      pred?: number,
    ) => [
      {
        ...baseMatch,
        providerMatchId,
        collectedAt: '2026-07-24T10:00:00.000Z',
        status: 'not_started',
        statistics: pred === undefined ? undefined : {
          combinedGoalsAverage: 3.4,
          over05Percentage: 85,
          bttsPercentage: 70,
          additional: {
            x7Predictions: {
              gols_1t_05_over: { pred: pred - 4, oj: 1.5 },
              over_05_ft_over: { pred, oj: 1.1 },
              over_15_ft_over: { pred: pred - 10, oj: 1.3 },
              over_25_ft_over: { pred: pred - 25, oj: 2.1 },
              btts_sim: { pred: pred - 20, oj: 1.8 },
            },
          },
        },
      },
      {
        ...baseMatch,
        providerMatchId,
        collectedAt: '2026-07-24T20:00:00.000Z',
        status: 'finished',
        score,
        statistics: goals.length ? { goals } : undefined,
      },
    ];
    await store.saveMatches([
      ...fixture('fixture-z30-green', { home: 2, away: 0, halftimeHome: 1, halftimeAway: 0 }, [{ minute: 45, team: 'home' }, { minute: 60, team: 'home' }], 88),
      ...fixture('fixture-z30-late', { home: 1, away: 0, halftimeHome: 0, halftimeAway: 0 }, [{ minute: 80, team: 'home' }]),
      ...fixture('fixture-z30-zero', { home: 0, away: 0, halftimeHome: 0, halftimeAway: 0 }, []),
      ...fixture('fixture-z30-early', { home: 1, away: 0, halftimeHome: 1, halftimeAway: 0 }, [{ minute: 25, team: 'home' }]),
      ...fixture('fixture-z30-notimeline', { home: 1, away: 0, halftimeHome: 0, halftimeAway: 0 }, []),
    ]);

    const results = await store.getZeroAt30Results();
    expect(results).toHaveLength(3);
    expect(results).toEqual(expect.arrayContaining([
      expect.objectContaining({
        providerMatchId: 'fixture-z30-green', hit: true, firstGoalMinute: 45, goalBand: "31–45'",
        over05HtProbability: 84, over05FtProbability: 88, over15FtProbability: 78,
        over25FtProbability: 63, bttsProbability: 68,
        combinedGoalsAverage: 3.4, over05Percentage: 85, bttsPercentage: 70,
        signalScore: 5,
      }),
      expect.objectContaining({ providerMatchId: 'fixture-z30-late', hit: false, firstGoalMinute: 80, goalBand: "76+'", over05HtProbability: undefined, over05FtProbability: undefined, over15FtProbability: undefined, signalScore: 0 }),
      expect.objectContaining({ providerMatchId: 'fixture-z30-zero', hit: false, firstGoalMinute: undefined, goalBand: '—', signalScore: 0 }),
    ]));
    await store.close();
  });

  it('saveLiveUpdates keeps the known score/status when a live refresh returns incomplete data', async () => {
    const store = createStore();
    const kickoffAt = '2026-07-24T18:00:00.000Z';
    await store.saveMatches([
      {
        provider: 'sokkerpro',
        providerMatchId: 'fixture-live',
        sourceUrl: 'https://sokkerpro.com/fixture/fixture-live',
        collectedAt: '2026-07-24T20:00:00.000Z',
        kickoffAt,
        status: 'finished',
        competition: 'Test League',
        homeTeam: { name: 'Home' },
        awayTeam: { name: 'Away' },
        score: { home: 2, away: 1 },
        statistics: { additional: { x7Predictions: { over_15_ft_over: { pred: 88, oj: 1.3 } } } },
      },
    ]);

    // Simulates a live-list refresh where the provider momentarily omits score/status
    // (e.g. postponed/awarded edge cases) — must not clobber the known result.
    await store.saveLiveUpdates([
      {
        provider: 'sokkerpro',
        providerMatchId: 'fixture-live',
        sourceUrl: 'https://sokkerpro.com/fixture/fixture-live',
        collectedAt: '2026-07-24T21:00:00.000Z',
        kickoffAt,
        competition: 'Test League',
        homeTeam: { name: 'Home' },
        awayTeam: { name: 'Away' },
      },
    ]);

    const [today] = await store.getTodayMatches('2026-07-24');
    expect(today).toEqual(expect.objectContaining({
      status: 'finished',
      homeScore: 2,
      awayScore: 1,
      predictions: expect.objectContaining({ over_15_ft_over: expect.objectContaining({ probability: 88 }) }),
    }));
    await store.close();
  });

  it('persists known leagues in a dedicated table and flags curated top-flight leagues', async () => {
    const store = createStore();
    await store.saveMatches([
      {
        provider: 'sokkerpro',
        providerMatchId: 'fixture-league',
        sourceUrl: 'https://sokkerpro.com/fixture/fixture-league',
        collectedAt: '2026-07-24T10:00:00.000Z',
        status: 'not_started',
        country: 'Brazil',
        competition: 'Serie A',
        homeTeam: { name: 'Home' },
        awayTeam: { name: 'Away' },
      },
      {
        provider: 'sokkerpro',
        providerMatchId: 'fixture-league-2',
        sourceUrl: 'https://sokkerpro.com/fixture/fixture-league-2',
        collectedAt: '2026-07-24T10:00:00.000Z',
        status: 'not_started',
        country: 'Brazil',
        competition: 'Paulista Série B',
        homeTeam: { name: 'Home2' },
        awayTeam: { name: 'Away2' },
      },
    ]);

    const leagues = await store.getKnownLeagues();
    expect(leagues).toEqual(expect.arrayContaining([
      { country: 'Brazil', competition: 'Serie A', isTopFlight: true },
      { country: 'Brazil', competition: 'Paulista Série B', isTopFlight: false },
    ]));
    await store.close();
  });

  it('reopening a store against the same schema preserves previously known leagues without duplicating them', async () => {
    const env = getEnv();
    schemaCounter += 1;
    const schema = `test_store_${process.pid}_${schemaCounter}`;
    schemasToClean.push(schema);
    const config = {
      host: env.POSTGRES_HOST,
      port: env.POSTGRES_PORT,
      database: env.POSTGRES_DB,
      user: env.POSTGRES_USER,
      password: env.POSTGRES_PASSWORD,
      schema,
    };

    const first = new PostgresMatchStore(config);
    await first.saveMatches([
      {
        provider: 'sokkerpro',
        providerMatchId: 'fixture-reopen',
        sourceUrl: 'https://sokkerpro.com/fixture/fixture-reopen',
        collectedAt: '2026-07-24T10:00:00.000Z',
        status: 'not_started',
        country: 'Chile',
        competition: 'Primera Division',
        homeTeam: { name: 'Home' },
        awayTeam: { name: 'Away' },
      },
    ]);
    await first.close();

    const second = new PostgresMatchStore(config);
    const leagues = (await second.getKnownLeagues()).filter((l) => l.country === 'Chile');
    expect(leagues).toEqual([{ country: 'Chile', competition: 'Primera Division', isTopFlight: true }]);
    await second.close();
  });
});
