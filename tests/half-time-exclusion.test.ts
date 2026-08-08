import { describe, it, expect } from 'vitest';
import { poissonOutcomeProbs, impliedProbs, pickExclusion } from '../src/analysis/half-time-exclusion.js';

describe('poissonOutcomeProbs', () => {
  it('sums to 1', () => {
    const probs = poissonOutcomeProbs(1.2, 0.7);
    expect(probs.home + probs.draw + probs.away).toBeCloseTo(1, 10);
  });

  it('is symmetric when both lambdas are equal', () => {
    const probs = poissonOutcomeProbs(0.9, 0.9);
    expect(probs.home).toBeCloseTo(probs.away, 10);
  });

  it('favors the side with the higher lambda', () => {
    const probs = poissonOutcomeProbs(2, 0.3);
    expect(probs.home).toBeGreaterThan(probs.away);
    expect(probs.home).toBeGreaterThan(probs.draw);
  });

  it('returns draw=1 when both lambdas are exactly zero', () => {
    expect(poissonOutcomeProbs(0, 0)).toEqual({ home: 0, draw: 1, away: 0 });
  });
});

describe('impliedProbs', () => {
  it('removes the bookmaker overround', () => {
    // 1/2.00 + 1/3.00 + 1/3.50 = 1.119 — a real book's overround, not exactly 1.
    const probs = impliedProbs(2.0, 3.0, 3.5);
    expect(probs.home + probs.draw + probs.away).toBeCloseTo(1, 10);
  });

  it('splits evenly across equal odds regardless of overround', () => {
    const probs = impliedProbs(2, 2, 2);
    expect(probs.home).toBeCloseTo(1 / 3, 10);
    expect(probs.draw).toBeCloseTo(1 / 3, 10);
    expect(probs.away).toBeCloseTo(1 / 3, 10);
  });

  it('gives the lowest odd the highest implied probability', () => {
    const probs = impliedProbs(1.5, 4.0, 6.0);
    expect(probs.home).toBeGreaterThan(probs.draw);
    expect(probs.draw).toBeGreaterThan(probs.away);
  });
});

describe('pickExclusion', () => {
  it('returns undefined when either first-half average is missing', () => {
    expect(pickExclusion({ homeFirstHalfGoalsAverage: undefined, awayFirstHalfGoalsAverage: 1 })).toBeUndefined();
    expect(pickExclusion({ homeFirstHalfGoalsAverage: 1, awayFirstHalfGoalsAverage: undefined })).toBeUndefined();
  });

  it('delegates the pick to poissonOutcomeProbs (same probs, same argmin)', () => {
    const home = 1.5;
    const away = 0.1;
    const expectedProbs = poissonOutcomeProbs(home, away);
    const result = pickExclusion({ homeFirstHalfGoalsAverage: home, awayFirstHalfGoalsAverage: away });
    expect(result?.probs).toEqual(expectedProbs);
    expect(result?.sources.poisson).toBe(result?.excluded);
  });

  it('picks the side that almost never scores as the exclusion, with no other sources', () => {
    const result = pickExclusion({ homeFirstHalfGoalsAverage: 5, awayFirstHalfGoalsAverage: 0.01 });
    expect(result?.excluded).toBe('away');
    expect(result?.sources).toEqual({ poisson: 'away' });
    expect(result?.agreement).toBe(1);
    expect(result?.sourcesAvailable).toBe(1);
  });

  it('floors a zero average instead of collapsing that side to ~0 probability', () => {
    // Both sides floored to the same MIN_LAMBDA — symmetric, so home and away tie and
    // draw (0-0) dominates. Ties resolve to 'home' (first checked in argmin).
    const result = pickExclusion({ homeFirstHalfGoalsAverage: 0, awayFirstHalfGoalsAverage: 0 });
    expect(result?.probs.home).toBeCloseTo(result!.probs.away, 10);
    expect(result?.probs.draw).toBeGreaterThan(result!.probs.home);
    expect(result?.excluded).toBe('home');
  });

  it('bumps agreement when half-time odds point to the same outcome', () => {
    const base = { homeFirstHalfGoalsAverage: 5, awayFirstHalfGoalsAverage: 0.01 };
    const result = pickExclusion({ ...base, oddsHalfTime: { home: 1.3, draw: 4.0, away: 12.0 } });
    expect(result?.excluded).toBe('away');
    expect(result?.sources.oddsHt).toBe('away');
    expect(result?.agreement).toBe(2);
    expect(result?.sourcesAvailable).toBe(2);
  });

  it('counts a disagreeing odds source in sourcesAvailable but not in agreement, and never overrides Poisson', () => {
    const base = { homeFirstHalfGoalsAverage: 5, awayFirstHalfGoalsAverage: 0.01 };
    // Draw priced at the highest odd here, so the market's least-likely pick is 'draw', not 'away'.
    const result = pickExclusion({ ...base, oddsHalfTime: { home: 1.3, draw: 15.0, away: 4.0 } });
    expect(result?.excluded).toBe('away');
    expect(result?.sources.oddsHt).toBe('draw');
    expect(result?.agreement).toBe(1);
    expect(result?.sourcesAvailable).toBe(2);
  });

  it('reaches full agreement across all three sources when they all concur', () => {
    const base = { homeFirstHalfGoalsAverage: 5, awayFirstHalfGoalsAverage: 0.01 };
    const result = pickExclusion({
      ...base,
      oddsHalfTime: { home: 1.3, draw: 4.0, away: 12.0 },
      oddsFullTime: { home: 1.2, draw: 5.0, away: 15.0 },
    });
    expect(result?.excluded).toBe('away');
    expect(result?.sources).toEqual({ poisson: 'away', oddsHt: 'away', oddsFt: 'away' });
    expect(result?.agreement).toBe(3);
    expect(result?.sourcesAvailable).toBe(3);
  });

  it('ignores an odds source left partially undefined', () => {
    const base = { homeFirstHalfGoalsAverage: 5, awayFirstHalfGoalsAverage: 0.01 };
    const result = pickExclusion({ ...base, oddsHalfTime: { home: 1.3, draw: undefined, away: 12.0 } });
    expect(result?.sources.oddsHt).toBeUndefined();
    expect(result?.sourcesAvailable).toBe(1);
  });
});
