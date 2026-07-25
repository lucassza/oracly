import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { SqliteMatchStore } from '../src/storage/sqlite-store.js';

const temporaryDirectories: string[] = [];

function createStore(): SqliteMatchStore {
  const directory = mkdtempSync(join(tmpdir(), 'sokkerpro-sqlite-'));
  temporaryDirectories.push(directory);
  return new SqliteMatchStore(join(directory, 'matches.db'));
}

afterEach(() => {
  temporaryDirectories.splice(0).forEach((directory) => rmSync(directory, { recursive: true, force: true }));
});

describe('SqliteMatchStore', () => {
  it('returns upcoming Over 0.5 FT predictions from the latest match snapshot', () => {
    const store = createStore();
    store.saveMatches([
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

    const predictions = store.getUpcomingOver05FtPredictions('2026-07-24T12:00:00.000Z');

    expect(predictions).toEqual([
      expect.objectContaining({
        providerMatchId: 'fixture-1',
        probability: 91,
        modelOdd: 1.12,
        homeTeam: 'Home',
      }),
    ]);
    store.close();
  });

  it('returns historical Over 0.5 HT accuracy from pre-kickoff snapshots', () => {
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
    store.saveMatches([
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

    expect(store.getUpcomingOver05HtPredictions('2026-07-24T12:00:00.000Z')).toHaveLength(0);
    expect(store.getHistoricalOver05HtResults()).toEqual([
      expect.objectContaining({ providerMatchId: 'fixture-ht', probability: 82, halftimeGoals: 1, hit: true }),
    ]);
    store.close();
  });
});
