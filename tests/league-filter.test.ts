import { describe, expect, it } from 'vitest';
import { isMainLeague } from '../src/utils/league-filter.js';

describe('isMainLeague', () => {
  it('keeps top professional leagues', () => {
    expect(isMainLeague('Serie A')).toBe(true);
    expect(isMainLeague('Liga MX')).toBe(true);
    expect(isMainLeague('Eliteserien')).toBe(true);
    expect(isMainLeague('Ekstraklasa')).toBe(true);
    expect(isMainLeague(undefined)).toBe(true);
  });

  it('excludes youth/age-grade competitions', () => {
    expect(isMainLeague('Paulista U20')).toBe(false);
    expect(isMainLeague('U19 League B')).toBe(false);
    expect(isMainLeague('A-Junior League')).toBe(false);
    expect(isMainLeague('Cotif Tournament')).toBe(false);
  });

  it('excludes women\'s football', () => {
    expect(isMainLeague('Brasileiro Women')).toBe(false);
    expect(isMainLeague("Women's National League")).toBe(false);
    expect(isMainLeague('Superliga Femenina')).toBe(false);
    expect(isMainLeague('Damallsvenskan')).toBe(false);
    expect(isMainLeague('NWSL')).toBe(false);
  });

  it('excludes friendlies', () => {
    expect(isMainLeague('Club Friendlies 1')).toBe(false);
    expect(isMainLeague('Hybrid Friendlies')).toBe(false);
  });

  it('excludes amateur/reserve squads', () => {
    expect(isMainLeague('Torneo Promocional Amateur')).toBe(false);
    expect(isMainLeague('Brisbane Reserves Premier League')).toBe(false);
    expect(isMainLeague('Northern NSW Reserve League')).toBe(false);
  });

  it('excludes semi-amateur regional/lower-tier competitions', () => {
    expect(isMainLeague('Oberliga: Bayern Nord')).toBe(false);
    expect(isMainLeague('Regionalliga: Nordost')).toBe(false);
    expect(isMainLeague('Npl Queensland')).toBe(false);
    expect(isMainLeague('New South Wales NPL 2')).toBe(false);
    expect(isMainLeague('3. Division - Group 1')).toBe(false);
    expect(isMainLeague('Kolmonen - Eastern Group')).toBe(false);
    expect(isMainLeague('Regional Leagues: Kanto')).toBe(false);
    expect(isMainLeague('Counties Leagues: Eastern Counties League')).toBe(false);
  });
});
