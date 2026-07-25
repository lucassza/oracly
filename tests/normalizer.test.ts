import { describe, it, expect } from 'vitest';
import {
  parseOddsValue,
  parseNumber,
  normalizeTimestamp,
  normalizeStatus,
  parsePrognosticos,
  normalizeFixtureFromList,
  deduplicateMatches,
  validateMatch,
} from '../src/api/normalizer.js';
import type { FixtureBase, CategorizedFixtures } from '../src/api/schemas.js';

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
