import { v4 as uuid } from 'uuid';
import { getEnv } from '../config/env.js';
import type { FixtureBase, CategorizedFixtures, FixtureDetailResponse, X7Response, H2HEntry, Prognosticos } from '../api/schemas.js';
import type { NormalizedMatch, NormalizedStatistics, NormalizedOdds, HeadToHeadEntry } from '../types/schemas.js';
import { normalizedMatchSchema } from '../types/schemas.js';
import { getLogger } from '../utils/logger.js';

// ============================================================
// Parsers
// ============================================================

export function parseOddsValue(raw: string | undefined): number | undefined {
  if (!raw) return undefined;
  // Format: "1.81#0" -> 1.81
  const num = parseFloat(raw.split('#')[0]);
  return isNaN(num) ? undefined : num;
}

export function parseNumber(value: string | undefined | number): number | undefined {
  if (value === undefined || value === null || value === '') return undefined;
  if (typeof value === 'number') return value;
  const num = parseFloat(value);
  return isNaN(num) ? undefined : num;
}

export function parsePercentage(value: string | undefined): number | undefined {
  const num = parseNumber(value);
  if (num === undefined) return undefined;
  return num;
}

export function normalizeTimestamp(timestamp: string | undefined): string | undefined {
  if (!timestamp) return undefined;
  const ts = parseInt(timestamp, 10);
  if (isNaN(ts)) return undefined;
  return new Date(ts * 1000).toISOString();
}

export function normalizeStatus(status: string | undefined): string | undefined {
  if (!status) return undefined;
  const map: Record<string, string> = {
    NS: 'not_started',
    LIVE: 'live',
    HT: 'half_time',
    FT: 'finished',
    ET: 'extra_time',
    PEN: 'penalties',
    AET: 'after_extra_time',
    P: 'postponed',
    CANC: 'cancelled',
    ABN: 'abandoned',
    SUSP: 'suspended',
    WD: 'walkover',
    awarded: 'awarded',
  };
  return map[status] ?? status;
}

export function parsePrognosticos(raw: string | undefined): Prognosticos | undefined {
  if (!raw) return undefined;
  try {
    const parsed = JSON.parse(raw) as Prognosticos;
    return parsed;
  } catch {
    return undefined;
  }
}

// ============================================================
// Normalizer
// ============================================================

export function normalizeFixtureFromList(
  fixture: FixtureBase,
  category: CategorizedFixtures,
  collectedAt: string,
  timezone: string,
): NormalizedMatch {
  const logger = getLogger();
  const baseUrl = getEnv().SOKKERPRO_BASE_URL;

  const homeScore = parseNumber(fixture.scoresLocalTeam);
  const awayScore = parseNumber(fixture.scoresVisitorTeam);
  const htScore = fixture.scoresHT ? fixture.scoresHT.split('-') : undefined;
  const kickoff = normalizeTimestamp(fixture.startingAtTimestamp);

  // Convert to Brasilia time (UTC-3)
  let kickoffBrasilia: string | undefined;
  if (fixture.startingAtTimestamp) {
    const ts = parseInt(fixture.startingAtTimestamp, 10);
    if (!isNaN(ts)) {
      const dt = new Date(ts * 1000);
      // Subtract 3 hours for Brasilia
      const brasilia = new Date(dt.getTime() - 3 * 60 * 60 * 1000);
      kickoffBrasilia = brasilia.toISOString().replace('Z', '-03:00');
    }
  }

  const prognosticos = parsePrognosticos(fixture.prognosticos);

  // Parse odds from the fixture list
  const homeOdds = parseOddsValue(fixture.XBET_VENCEDOR_HOME);
  const drawOdds = parseOddsValue(fixture.XBET_VENCEDOR_DRAW);
  const awayOdds = parseOddsValue(fixture.XBET_VENCEDOR_AWAY);

  // Build statistics from medias fields
  const statistics: NormalizedStatistics = {
    homeGoalsAverage: parseNumber(fixture.medias_home_goal),
    awayGoalsAverage: parseNumber(fixture.medias_away_goal),
    homeScoredAverage: parseNumber(fixture.medias_home_goal),
    awayScoredAverage: parseNumber(fixture.medias_away_goal),
    homeConcededAverage: parseNumber(fixture.medias_away_goal),
    awayConcededAverage: parseNumber(fixture.medias_home_goal),
  };

  // Extract over percentages from prognosticos
  if (prognosticos?.mercado_gols) {
    const gols = prognosticos.mercado_gols as Record<string, { res?: number; value?: number }>;
    statistics.over05Percentage = gols['over_0_5']?.res;
    statistics.over15Percentage = gols['over_1_5']?.res;
    statistics.over25Percentage = gols['over_2_5']?.res;
  }

  // BTTS from prognosticos
  if (prognosticos?.mercado_ambos_marcam) {
    const btts = prognosticos.mercado_ambos_marcam as Record<string, { probabilidade?: number }>;
    statistics.bttsPercentage = btts['ambos_sim']?.probabilidade;
  }

  // Check for non-empty statistics
  const hasStats = Object.values(statistics).some((v) => v !== undefined);

  const match: NormalizedMatch = {
    provider: 'sokkerpro',
    providerMatchId: fixture.fixtureId,
    sourceUrl: `${baseUrl}/fixture/${fixture.fixtureId}`,

    collectedAt,
    matchDate: fixture.startingAtDate,
    kickoffAt: kickoff,
    kickoffBrasilia,
    timezone: 'America/Sao_Paulo',

    country: category.countryName,
    competition: category.leagueName,
    round: fixture.roundName,

    homeTeam: {
      name: fixture.localTeamName || 'Unknown',
      providerId: fixture.localTeamId,
    },
    awayTeam: {
      name: fixture.visitorTeamName || 'Unknown',
      providerId: fixture.visitorTeamId,
    },

    status: normalizeStatus(fixture.status),
    liveMinute: fixture.minute,

    score:
      homeScore !== undefined || awayScore !== undefined
        ? {
            home: homeScore,
            away: awayScore,
            halftimeHome: htScore?.[0] ? parseInt(htScore[0]) : undefined,
            halftimeAway: htScore?.[1] ? parseInt(htScore[1]) : undefined,
          }
        : undefined,

    odds:
      homeOdds !== undefined || drawOdds !== undefined || awayOdds !== undefined
        ? {
            home: homeOdds,
            draw: drawOdds,
            away: awayOdds,
            bookmaker: 'XBET',
            collectedAt,
          }
        : undefined,

    statistics: hasStats ? statistics : undefined,
  };

  return match;
}

export function enrichMatchWithDetail(
  match: NormalizedMatch,
  detail: FixtureDetailResponse,
  x7: X7Response | undefined,
  collectedAt: string,
): NormalizedMatch {
  const logger = getLogger();
  const d = detail.data;

  // Update status if more specific
  if (d.status) {
    match.status = normalizeStatus(d.status);
  }

  // Enrich odds from detail (BET365/XBET)
  const homeOdds = parseOddsValue(d.XBET_VENCEDOR_HOME) ?? match.odds?.home;
  const drawOdds = parseOddsValue(d.XBET_VENCEDOR_DRAW) ?? match.odds?.draw;
  const awayOdds = parseOddsValue(d.XBET_VENCEDOR_AWAY) ?? match.odds?.away;

  if (homeOdds !== undefined || drawOdds !== undefined || awayOdds !== undefined) {
    match.odds = {
      home: homeOdds,
      draw: drawOdds,
      away: awayOdds,
      bookmaker: 'XBET',
      collectedAt,
    };
  }

  // Parse prognosticos from detail (more complete)
  const prognosticos = parsePrognosticos(d.prognosticos);
  if (prognosticos) {
    // Goals market
    if (prognosticos.mercado_gols) {
      const gols = prognosticos.mercado_gols as Record<string, { res?: number; value?: number; detalhes?: Record<string, unknown> }>;
      const stats = match.statistics || {};
      stats.over05Percentage = gols['over_0_5']?.res;
      stats.over15Percentage = gols['over_1_5']?.res;
      stats.over25Percentage = gols['over_2_5']?.res;

      if (gols['over_0_5']?.detalhes) {
        const det = gols['over_0_5'].detalhes as { media_casa?: number; media_fora?: number };
        stats.homeGoalsAverage = det.media_casa;
        stats.awayGoalsAverage = det.media_fora;
        if (det.media_casa !== undefined && det.media_fora !== undefined) {
          stats.combinedGoalsAverage = det.media_casa + det.media_fora;
        }
      }
      match.statistics = stats;
    }

    // BTTS
    if (prognosticos.mercado_ambos_marcam) {
      const btts = prognosticos.mercado_ambos_marcam as Record<string, { probabilidade?: number }>;
      if (!match.statistics) match.statistics = {};
      match.statistics.bttsPercentage = btts['ambos_sim']?.probabilidade;
    }

    // 1x2 probabilities
    if (prognosticos.mercado_1x2 && !Array.isArray(prognosticos.mercado_1x2)) {
      const mercado = prognosticos.mercado_1x2 as Record<string, { probabilidade?: number }>;
      if (!match.statistics) match.statistics = {};
      match.statistics.additional = {
        ...match.statistics.additional,
        homeWinProbability: mercado['casa_vencer']?.probabilidade,
        drawProbability: mercado['empate']?.probabilidade,
        awayWinProbability: mercado['fora_vencer']?.probabilidade,
      };
    }
  }

  // H2H data
  let h2hBoth = d.h2h_dois_full_time;
  if (typeof h2hBoth === 'string') {
    try {
      h2hBoth = JSON.parse(h2hBoth) as H2HEntry[];
    } catch {
      h2hBoth = undefined;
    }
  }
  if (Array.isArray(h2hBoth) && h2hBoth.length > 0) {
    if (!match.statistics) match.statistics = {};
    match.statistics.headToHead = h2hBoth.map((h: H2HEntry) => ({
      date: h.starting_at,
      homeTeam: h.awayTeam, // Note: h2h_dois is "both teams" - awayTeam is the second team's perspective
      awayTeam: h.homeTeam,
      homeScore: h.FullTime?.goal_home,
      awayScore: h.FullTime?.goal_away,
    }));
  }

  // X7 predictions
  if (x7?.picks) {
    if (!match.statistics) match.statistics = {};
    match.statistics.additional = {
      ...match.statistics.additional,
      x7Predictions: x7.picks,
      x7HasRecommended: x7.has_recommended,
      x7HasBetsignal: x7.has_betsignal,
      x7ModelVersion: x7.model_version,
      x7GeneratedAt: x7.generated_at,
    };
  }

  // Timeline (goals, cards, substitutions)
  let timelineRaw = d.timeline;
  if (typeof timelineRaw === 'string') {
    try {
      timelineRaw = JSON.parse(timelineRaw);
    } catch {
      timelineRaw = undefined;
    }
  }

  if (Array.isArray(timelineRaw) && timelineRaw.length > 0) {
    if (!match.statistics) match.statistics = {};

    const homeTeamId = d.localTeamId;

    // Map type_ids to readable types
    const typeMap: Record<number, string> = {
      14: 'goal',
      18: 'substitution',
      19: 'yellow_card',
      126: 'corner',
      569: 'shot_on_target',
      570: 'shot_off_target',
      1514: 'offside',
    };

    // Extract goals
    const goals = timelineRaw
      .filter((e: { type_id?: number }) => e.type_id === 14)
      .map((e: Record<string, unknown>) => ({
        minute: e.minute as number,
        extraMinute: (e.extra_minute as number) || undefined,
        player: (e.player_name as string) || undefined,
        team: String(e.participant_id) === String(homeTeamId) ? ('home' as const) : ('away' as const),
        type: 'goal',
      }));

    if (goals.length > 0) {
      match.statistics.goals = goals;
    }

    // Extract full timeline (excluding noise like shots/corners)
    const importantTypes = new Set([14, 18, 19, 1514]);
    const timeline = timelineRaw
      .filter((e: { type_id?: number }) => importantTypes.has(e.type_id ?? 0))
      .map((e: Record<string, unknown>) => ({
        minute: e.minute as number,
        extraMinute: (e.extra_minute as number) || undefined,
        type: typeMap[e.type_id as number] || `type_${e.type_id}`,
        player: (e.player_name as string) || undefined,
        team: String(e.participant_id) === String(homeTeamId) ? ('home' as const) : ('away' as const),
        description: (e.addition as string) || undefined,
      }));

    if (timeline.length > 0) {
      match.statistics.timeline = timeline;
    }
  }

  return match;
}

// ============================================================
// Deduplication
// ============================================================

export function deduplicateMatches(matches: NormalizedMatch[]): NormalizedMatch[] {
  const seen = new Set<string>();
  const result: NormalizedMatch[] = [];

  for (const match of matches) {
    const key = `${match.providerMatchId}-${match.homeTeam.name}-${match.awayTeam.name}`;
    if (!seen.has(key)) {
      seen.add(key);
      result.push(match);
    }
  }

  return result;
}

// ============================================================
// Validation
// ============================================================

export function validateMatch(match: NormalizedMatch): {
  valid: boolean;
  errors: string[];
} {
  const result = normalizedMatchSchema.safeParse(match);
  if (result.success) {
    return { valid: true, errors: [] };
  }
  return {
    valid: false,
    errors: result.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`),
  };
}
