import { describe, it, expect } from 'vitest';
import {
  parseOddsValue,
  parseNumber,
  normalizeTimestamp,
  normalizeStatus,
  parsePrognosticos,
  normalizeFixtureFromList,
  enrichMatchWithDetail,
  extractHalfTimeDetailFields,
  deduplicateMatches,
  validateMatch,
} from '../src/api/normalizer.js';
import type { FixtureBase, CategorizedFixtures, FixtureDetailResponse } from '../src/api/schemas.js';
import type { NormalizedMatch } from '../src/types/schemas.js';

describe('parseOddsValue', () => {
  it('should parse valid odds string', () => {
    expect(parseOddsValue('1.81#0')).toBe(1.81);
  });

  it('should parse odds without hash', () => {
    expect(parseOddsValue('2.5')).toBe(2.5);
  });

  it('should return undefined for empty string', () => {
    expect(parseOddsValue('')).toBeUndefined();
  });

  it('should return undefined for undefined', () => {
    expect(parseOddsValue(undefined)).toBeUndefined();
  });

  it('should handle zero odds', () => {
    expect(parseOddsValue('0#0')).toBe(0);
  });
});

describe('parseNumber', () => {
  it('should parse valid number string', () => {
    expect(parseNumber('42')).toBe(42);
  });

  it('should parse decimal string', () => {
    expect(parseNumber('3.14')).toBeCloseTo(3.14);
  });

  it('should return number as-is', () => {
    expect(parseNumber(42)).toBe(42);
  });

  it('should return undefined for empty string', () => {
    expect(parseNumber('')).toBeUndefined();
  });

  it('should return undefined for undefined', () => {
    expect(parseNumber(undefined)).toBeUndefined();
  });

  it('should return undefined for non-numeric string', () => {
    expect(parseNumber('abc')).toBeUndefined();
  });
});

describe('normalizeTimestamp', () => {
  it('should convert unix timestamp to ISO', () => {
    const result = normalizeTimestamp('1784898000');
    expect(result).toBe('2026-07-24T13:00:00.000Z');
  });

  it('should return undefined for undefined', () => {
    expect(normalizeTimestamp(undefined)).toBeUndefined();
  });

  it('should return undefined for non-numeric string', () => {
    expect(normalizeTimestamp('abc')).toBeUndefined();
  });
});

describe('normalizeStatus', () => {
  it('should map NS to not_started', () => {
    expect(normalizeStatus('NS')).toBe('not_started');
  });

  it('should map LIVE to live', () => {
    expect(normalizeStatus('LIVE')).toBe('live');
  });

  it('should map FT to finished', () => {
    expect(normalizeStatus('FT')).toBe('finished');
  });

  it('should map HT to half_time', () => {
    expect(normalizeStatus('HT')).toBe('half_time');
  });

  it('should return original for unknown status', () => {
    expect(normalizeStatus('CUSTOM')).toBe('CUSTOM');
  });

  it('should return undefined for undefined', () => {
    expect(normalizeStatus(undefined)).toBeUndefined();
  });
});

describe('parsePrognosticos', () => {
  it('should parse valid JSON', () => {
    const json = '{"fixtureId":"123","mercado_gols":{"over_0_5":{"res":70}}}';
    const result = parsePrognosticos(json);
    expect(result).toBeDefined();
    expect(result?.fixtureId).toBe('123');
  });

  it('should return undefined for invalid JSON', () => {
    expect(parsePrognosticos('invalid')).toBeUndefined();
  });

  it('should return undefined for undefined', () => {
    expect(parsePrognosticos(undefined)).toBeUndefined();
  });
});

describe('normalizeFixtureFromList', () => {
  const mockFixture: FixtureBase = {
    fixtureId: '12345',
    status: 'NS',
    startingAtDate: '2026-07-24',
    startingAtTime: '19:00:00',
    startingAtTimestamp: '1784922000',
    localTeamId: '100',
    localTeamName: 'Team A',
    visitorTeamId: '200',
    visitorTeamName: 'Team B',
    scoresLocalTeam: '',
    scoresVisitorTeam: '',
    scoresHT: '',
    scoresFT: '',
    XBET_VENCEDOR_HOME: '1.81#0',
    XBET_VENCEDOR_DRAW: '3.30#0',
    XBET_VENCEDOR_AWAY: '5.04#0',
    leagueId: '500',
    leagueName: 'Test League',
    countryId: '100',
    countryName: 'Brazil',
    roundName: '1',
  };

  const mockCategory: CategorizedFixtures = {
    leagueId: '500',
    leagueName: 'Test League',
    seasonId: '1000',
    countryId: '100',
    countryName: 'Brazil',
    fixtures: [mockFixture],
  };

  it('should normalize a basic fixture', () => {
    const result = normalizeFixtureFromList(
      mockFixture,
      mockCategory,
      '2026-07-24T12:00:00Z',
      'utc-3',
    );

    expect(result.provider).toBe('sokkerpro');
    expect(result.providerMatchId).toBe('12345');
    expect(result.homeTeam.name).toBe('Team A');
    expect(result.awayTeam.name).toBe('Team B');
    expect(result.country).toBe('Brazil');
    expect(result.competition).toBe('Test League');
    expect(result.round).toBe('1');
    expect(result.status).toBe('not_started');
    expect(result.matchDate).toBe('2026-07-24');
  });

  it('should include odds when available', () => {
    const result = normalizeFixtureFromList(
      mockFixture,
      mockCategory,
      '2026-07-24T12:00:00Z',
      'utc-3',
    );

    expect(result.odds).toBeDefined();
    expect(result.odds?.home).toBe(1.81);
    expect(result.odds?.draw).toBe(3.30);
    expect(result.odds?.away).toBe(5.04);
    expect(result.odds?.bookmaker).toBe('XBET');
  });

  it('should handle fixture without odds', () => {
    const fixtureWithoutOdds = { ...mockFixture, XBET_VENCEDOR_HOME: '', XBET_VENCEDOR_DRAW: '', XBET_VENCEDOR_AWAY: '' };
    const result = normalizeFixtureFromList(
      fixtureWithoutOdds,
      mockCategory,
      '2026-07-24T12:00:00Z',
      'utc-3',
    );

    expect(result.odds).toBeUndefined();
  });
});

describe('deduplicateMatches', () => {
  it('should remove duplicate matches', () => {
    const matches = [
      {
        provider: 'sokkerpro' as const,
        providerMatchId: '123',
        sourceUrl: 'https://sokkerpro.com/fixture/123',
        collectedAt: '2026-07-24T12:00:00Z',
        homeTeam: { name: 'Team A' },
        awayTeam: { name: 'Team B' },
      },
      {
        provider: 'sokkerpro' as const,
        providerMatchId: '123',
        sourceUrl: 'https://sokkerpro.com/fixture/123',
        collectedAt: '2026-07-24T12:00:00Z',
        homeTeam: { name: 'Team A' },
        awayTeam: { name: 'Team B' },
      },
      {
        provider: 'sokkerpro' as const,
        providerMatchId: '456',
        sourceUrl: 'https://sokkerpro.com/fixture/456',
        collectedAt: '2026-07-24T12:00:00Z',
        homeTeam: { name: 'Team C' },
        awayTeam: { name: 'Team D' },
      },
    ];

    const result = deduplicateMatches(matches);
    expect(result).toHaveLength(2);
  });

  it('should keep unique matches', () => {
    const matches = [
      {
        provider: 'sokkerpro' as const,
        providerMatchId: '123',
        sourceUrl: 'https://sokkerpro.com/fixture/123',
        collectedAt: '2026-07-24T12:00:00Z',
        homeTeam: { name: 'Team A' },
        awayTeam: { name: 'Team B' },
      },
    ];

    const result = deduplicateMatches(matches);
    expect(result).toHaveLength(1);
  });
});

describe('validateMatch', () => {
  it('should validate a valid match', () => {
    const match = {
      provider: 'sokkerpro' as const,
      sourceUrl: 'https://sokkerpro.com/fixture/123',
      collectedAt: '2026-07-24T12:00:00Z',
      homeTeam: { name: 'Team A' },
      awayTeam: { name: 'Team B' },
    };

    const result = validateMatch(match);
    expect(result.valid).toBe(true);
    expect(result.errors).toHaveLength(0);
  });

  it('should fail for missing required fields', () => {
    const match = {
      provider: 'sokkerpro' as const,
      // missing sourceUrl and collectedAt
      homeTeam: { name: 'Team A' },
      awayTeam: { name: 'Team B' },
    } as any;

    const result = validateMatch(match);
    expect(result.valid).toBe(false);
    expect(result.errors.length).toBeGreaterThan(0);
  });
});

describe('extractHalfTimeDetailFields', () => {
  it('extracts half-time score, odds, first-half averages and xG', () => {
    const fields = extractHalfTimeDetailFields({
      fixtureId: 1,
      scoresLocalTeamHT: '1',
      scoresVisitorTeamHT: '0',
      BET365_VENCEDOR1T_HOME: '1.80#0',
      BET365_VENCEDOR1T_DRAW: '2.50#0',
      BET365_VENCEDOR1T_AWAY: '4.00#0',
      medias_home_primeiro_tempo_goal: '0.6',
      medias_away_primeiro_tempo_goal: '0.3',
      medias_home_primeiro_tempo_shots_on_target: '1.4',
      medias_away_primeiro_tempo_shots_on_target: '0.9',
      medias_home_primeiro_tempo_possession: '55',
      medias_away_primeiro_tempo_possession: '45',
      medias_home_primeiro_tempo_dangerous_attacks: '22.1',
      medias_away_primeiro_tempo_dangerous_attacks: '14.7',
      medias_home_xg: '1.9',
      medias_away_xg: '0.8',
    });

    expect(fields.halftimeHome).toBe(1);
    expect(fields.halftimeAway).toBe(0);
    expect(fields.oddsHalfTime).toEqual({ home: 1.8, draw: 2.5, away: 4.0, bookmaker: 'BET365' });
    expect(fields.firstHalf).toEqual({
      homeGoalsAverage: 0.6,
      awayGoalsAverage: 0.3,
      homeShotsOnTargetAverage: 1.4,
      awayShotsOnTargetAverage: 0.9,
      homePossessionAverage: 55,
      awayPossessionAverage: 45,
      homeDangerousAttacksAverage: 22.1,
      awayDangerousAttacksAverage: 14.7,
    });
    expect(fields.homeXg).toBe(1.9);
    expect(fields.awayXg).toBe(0.8);
  });

  it('falls back to XBET half-time odds when BET365 is absent', () => {
    const fields = extractHalfTimeDetailFields({
      fixtureId: 1,
      XBET_VENCEDOR1T_HOME: '2.10#0',
      XBET_VENCEDOR1T_DRAW: '2.90#0',
      XBET_VENCEDOR1T_AWAY: '3.60#0',
    });
    expect(fields.oddsHalfTime).toEqual({ home: 2.1, draw: 2.9, away: 3.6, bookmaker: 'XBET' });
  });

  it('extracts whichever half-time score field is present, independently', () => {
    const fields = extractHalfTimeDetailFields({ fixtureId: 1, scoresLocalTeamHT: '2' });
    expect(fields.halftimeHome).toBe(2);
    expect(fields.halftimeAway).toBeUndefined();
  });

  it('leaves everything undefined when the fixture carries none of these fields', () => {
    const fields = extractHalfTimeDetailFields({ fixtureId: 1 });
    expect(fields.halftimeHome).toBeUndefined();
    expect(fields.halftimeAway).toBeUndefined();
    expect(fields.oddsHalfTime).toBeUndefined();
    expect(fields.firstHalf).toBeUndefined();
    expect(fields.homeXg).toBeUndefined();
    expect(fields.awayXg).toBeUndefined();
  });
});

describe('enrichMatchWithDetail (half-time fields)', () => {
  const baseMatch: NormalizedMatch = {
    provider: 'sokkerpro',
    providerMatchId: '999',
    sourceUrl: 'https://sokkerpro.com/fixture/999',
    collectedAt: '2026-07-24T10:00:00Z',
    homeTeam: { name: 'Home' },
    awayTeam: { name: 'Away' },
  };

  const buildDetail = (data: Partial<FixtureDetailResponse['data']> & { fixtureId: number }): FixtureDetailResponse => ({
    success: true,
    data: data as FixtureDetailResponse['data'],
  });

  it('sets score.halftimeHome/halftimeAway and oddsHalfTime from the detail response', () => {
    const detail = buildDetail({
      fixtureId: 999,
      scoresLocalTeamHT: '1',
      scoresVisitorTeamHT: '1',
      BET365_VENCEDOR1T_HOME: '2.00#0',
      BET365_VENCEDOR1T_DRAW: '2.40#0',
      BET365_VENCEDOR1T_AWAY: '4.50#0',
    });
    const result = enrichMatchWithDetail({ ...baseMatch }, detail, undefined, '2026-07-24T20:00:00Z');

    expect(result.score).toEqual({ halftimeHome: 1, halftimeAway: 1 });
    expect(result.oddsHalfTime).toEqual({ home: 2.0, draw: 2.4, away: 4.5, bookmaker: 'BET365', collectedAt: '2026-07-24T20:00:00Z' });
  });

  it('does not touch score when the detail response has no half-time score', () => {
    const match: NormalizedMatch = { ...baseMatch, score: { home: 2, away: 1, halftimeHome: 1, halftimeAway: 0 } };
    const result = enrichMatchWithDetail(match, buildDetail({ fixtureId: 999 }), undefined, '2026-07-24T20:00:00Z');
    expect(result.score).toEqual({ home: 2, away: 1, halftimeHome: 1, halftimeAway: 0 });
  });

  it('attaches statistics.firstHalf without discarding other statistics already on the match', () => {
    const match: NormalizedMatch = { ...baseMatch, statistics: { combinedGoalsAverage: 3.1 } };
    const detail = buildDetail({
      fixtureId: 999,
      medias_home_primeiro_tempo_goal: '0.4',
      medias_away_primeiro_tempo_goal: '0.2',
    });
    const result = enrichMatchWithDetail(match, detail, undefined, '2026-07-24T20:00:00Z');

    expect(result.statistics?.combinedGoalsAverage).toBe(3.1);
    expect(result.statistics?.firstHalf?.homeGoalsAverage).toBe(0.4);
    expect(result.statistics?.firstHalf?.awayGoalsAverage).toBe(0.2);
  });
});
