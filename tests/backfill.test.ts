import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { resetEnv } from '../src/config/env.js';
import { resetLogger } from '../src/utils/logger.js';
import { ScraperService } from '../src/services/scraper.js';
import { SokkerProApi } from '../src/api/client.js';
import { PostgresMatchStore } from '../src/storage/postgres-store.js';

const KICKOFF = 1788026400; // 2026-08-29T18:00:00Z
const KICKOFF_ISO = new Date(KICKOFF * 1000).toISOString();

function fixture(id: string) {
  return {
    fixtureId: id,
    localTeamName: 'Casa',
    visitorTeamName: 'Fora',
    startingAtTimestamp: String(KICKOFF),
    startingAtDate: '2026-08-29',
    status: 'FT',
    scoresLocalTeam: '2',
    scoresVisitorTeam: '1',
    scoresHT: '1-0',
    medias_home_goal: '1.8',
    medias_away_goal: '0.9',
    // Closing odds — present on the API response for a past fixture, must not be persisted.
    XBET_VENCEDOR_HOME: '1.87#0',
    XBET_VENCEDOR_DRAW: '3.40#0',
    XBET_VENCEDOR_AWAY: '4.10#0',
  };
}

function stubApi(x7: unknown) {
  vi.spyOn(SokkerProApi.prototype, 'getFixtures').mockResolvedValue({
    success: true,
    data: {
      fixtures_total: 1,
      sortedCategorizedFixtures: [{ countryName: 'Brasil', leagueName: 'Serie A', fixtures: [fixture('1')] }],
    },
  } as never);
  vi.spyOn(SokkerProApi.prototype, 'getFixtureDetail').mockResolvedValue({ data: { fixtureId: '1', status: 'FT' } } as never);
  const x7Spy = vi.spyOn(SokkerProApi.prototype, 'getFixtureX7');
  if (x7 === undefined) {
    x7Spy.mockRejectedValue(new Error('X7 not available (HTTP 404)'));
  } else {
    x7Spy.mockResolvedValue(x7 as never);
  }
}

describe('ScraperService.backfill', () => {
  let saved: Parameters<PostgresMatchStore['saveMatches']>[0];

  beforeEach(() => {
    resetEnv();
    resetLogger();
    vi.stubEnv('SCRAPER_DELAY_MIN_MS', '0');
    vi.stubEnv('SCRAPER_DELAY_MAX_MS', '1');
    saved = [];
    vi.spyOn(PostgresMatchStore.prototype, 'saveMatches').mockImplementation(async (matches) => {
      saved = matches;
    });
    vi.spyOn(PostgresMatchStore.prototype, 'close').mockResolvedValue(undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllEnvs();
  });

  it('stamps collectedAt with the X7 generated_at, not the wall clock', async () => {
    stubApi({ picks: { over_15_ft_over: { pred: 76 } }, generated_at: '2026-08-26T07:00:55.013658+00:00' });

    const result = await new ScraperService().backfill('2026-08-29');

    expect(result.persisted).toBe(1);
    expect(saved[0].collectedAt).toBe('2026-08-26T07:00:55.013Z');
    expect(saved[0].collectedAt < KICKOFF_ISO).toBe(true);
    expect(saved[0].backfilledFromX7At).toBeTruthy();
  });

  it('drops the odds, which the API serves as closing prices long after the prediction', async () => {
    stubApi({ picks: { over_15_ft_over: { pred: 76 } }, generated_at: '2026-08-26T07:00:55.013658+00:00' });

    await new ScraperService().backfill('2026-08-29');

    expect(saved[0].odds).toBeUndefined();
    expect(saved[0].oddsHalfTime).toBeUndefined();
    // The outcome is still recovered — that's the point of the backfill.
    expect(saved[0].score).toMatchObject({ home: 2, away: 1 });
  });

  it('skips a fixture whose prediction was generated after kickoff', async () => {
    stubApi({ picks: { over_15_ft_over: { pred: 76 } }, generated_at: '2026-08-29T19:00:00.000Z' });

    const result = await new ScraperService().backfill('2026-08-29');

    expect(result).toMatchObject({ persisted: 0, skippedPostKickoff: 1 });
    expect(saved).toHaveLength(0);
  });

  it('skips a fixture with no X7 rather than guessing a timestamp', async () => {
    stubApi(undefined);

    const result = await new ScraperService().backfill('2026-08-29');

    expect(result).toMatchObject({ persisted: 0, skippedNoX7: 1 });
    expect(saved).toHaveLength(0);
  });
});
