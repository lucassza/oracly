import { z } from 'zod';

// ============================================================
// SokkerPRO API Response Schemas
// ============================================================

const fixtureBaseSchema = z.object({
  fixtureId: z.string(),
  status: z.string().optional(),
  minute: z.string().optional(),

  startingAtDateTime: z.string().optional(),
  startingAtDate: z.string().optional(),
  startingAtTime: z.string().optional(),
  startingAtTimestamp: z.string().optional(),
  startingAtTimezone: z.string().optional(),

  roundId: z.string().optional(),
  roundName: z.string().optional(),
  groupId: z.string().optional(),
  seasonId: z.string().optional(),
  stageId: z.string().optional(),
  leagueId: z.string().optional(),
  leagueName: z.string().optional(),
  countryId: z.string().optional(),
  countryName: z.string().optional(),
  countryImagePath: z.string().optional(),

  localTeamId: z.string().optional(),
  localTeamName: z.string().optional(),
  localTeamFlag: z.string().optional(),
  visitorTeamId: z.string().optional(),
  visitorTeamName: z.string().optional(),
  visitorTeamFlag: z.string().optional(),

  scoresLocalTeam: z.string().optional(),
  scoresVisitorTeam: z.string().optional(),
  scoresHT: z.string().optional(),
  scoresFT: z.string().optional(),
  scoresET: z.string().optional(),
  scoresPS: z.string().optional(),
  scoresLocalTeamET: z.string().optional(),
  scoresVisitorTeamET: z.string().optional(),
  scoresLocalTeamPEN: z.string().optional(),
  scoresVisitorTeamPEN: z.string().optional(),

  // Odds
  XBET_VENCEDOR_AWAY: z.string().optional(),
  XBET_VENCEDOR_DRAW: z.string().optional(),
  XBET_VENCEDOR_HOME: z.string().optional(),
  BET365_VENCEDOR_X_LIVE: z.string().optional(),
  BET365_VENCEDOR_1_LIVE: z.string().optional(),
  BET365_VENCEDOR_2_LIVE: z.string().optional(),

  // Live stats
  localBallPossession: z.string().optional(),
  visitorBallPossession: z.string().optional(),
  localCorners: z.string().optional(),
  visitorCorners: z.string().optional(),
  localShotsOnGoal: z.string().optional(),
  visitorShotsOnGoal: z.string().optional(),
  localShotsOffGoal: z.string().optional(),
  visitorShotsOffGoal: z.string().optional(),
  localShotsTotal: z.string().optional(),
  visitorShotsTotal: z.string().optional(),
  localAttacksDangerousAttacks: z.string().optional(),
  visitorAttacksDangerousAttacks: z.string().optional(),
  localFouls: z.string().optional(),
  visitorFouls: z.string().optional(),
  localRedCards: z.string().optional(),
  visitorRedCards: z.string().optional(),
  localYellowCards: z.string().optional(),
  visitorYellowCards: z.string().optional(),

  // Prognostics
  prognosticos: z.string().optional(),

  // Media (averages)
  medias_home_goal: z.string().optional(),
  medias_away_goal: z.string().optional(),
  medias_home_possession: z.string().optional(),
  medias_away_possession: z.string().optional(),
  medias_home_corners: z.string().optional(),
  medias_away_corners: z.string().optional(),
  medias_home_dangerous_attacks: z.string().optional(),
  medias_away_dangerous_attacks: z.string().optional(),
  medias_home_shots_on_target: z.string().optional(),
  medias_away_shots_on_target: z.string().optional(),
  medias_home_shots_total: z.string().optional(),
  medias_away_shots_total: z.string().optional(),
  medias_home_fouls: z.string().optional(),
  medias_away_fouls: z.string().optional(),
  medias_home_shots_insidebox: z.string().optional(),
  medias_away_shots_insidebox: z.string().optional(),
  medias_home_attacks: z.string().optional(),
  medias_away_attacks: z.string().optional(),
  medias_home_yellow_cards: z.string().optional(),
  medias_away_yellow_cards: z.string().optional(),
  medias_home_successful_passes_percentage: z.string().optional(),
  medias_away_successful_passes_percentage: z.string().optional(),
  medias_home_shots_off_target: z.string().optional(),
  medias_away_shots_off_target: z.string().optional(),
  medias_home_shots_outsidebox: z.string().optional(),
  medias_away_shots_outsidebox: z.string().optional(),
}).passthrough();

export const categorizedFixturesSchema = z.object({
  leagueId: z.string(),
  leagueName: z.string(),
  seasonId: z.string(),
  countryId: z.string(),
  countryName: z.string(),
  countryImagePath: z.string().optional(),
  fixtures: z.array(fixtureBaseSchema),
});

export const fixturesResponseSchema = z.object({
  success: z.boolean(),
  data: z.object({
    dateKey: z.string(),
    fixtures_total: z.number(),
    sortedCategorizedFixtures: z.array(categorizedFixturesSchema),
  }),
});

// ============================================================
// Fixture Detail Schema
// ============================================================

const h2hEntrySchema = z.object({
  fixture_id: z.number(),
  starting_at: z.string(),
  league: z.object({
    id: z.number(),
    short_code: z.string(),
    image_path: z.string().optional(),
  }),
  homeTeam: z.string(),
  awayTeam: z.string(),
  FullTime: z.object({
    goal_home: z.number(),
    goal_away: z.number(),
    corners_home: z.number().optional(),
    corners_away: z.number().optional(),
    yellow_cards_home: z.number().optional(),
    yellow_cards_away: z.number().optional(),
    fouls_home: z.number().optional(),
    fouls_away: z.number().optional(),
    shots_total_home: z.number().optional(),
    shots_total_away: z.number().optional(),
  }),
}).passthrough();

const prognosticosSchema = z.object({
  fixtureId: z.string(),
  mercado_1x2: z.union([z.record(z.unknown()), z.array(z.unknown())]).optional(),
  mercado_gols: z.record(z.unknown()).optional(),
  mercado_escanteios: z.union([z.record(z.unknown()), z.array(z.unknown())]).optional(),
  mercado_ambos_marcam: z.record(z.unknown()).optional(),
  mercado_gols_primeiro_tempo: z.record(z.unknown()).optional(),
  mercado_1x2_1t: z.record(z.unknown()).optional(),
}).passthrough();

export const fixtureDetailResponseSchema = z.object({
  success: z.boolean(),
  data: z.object({
    fixtureId: z.number(),
    status: z.string().optional(),
    localTeamId: z.number().optional(),
    localTeamName: z.string().optional(),
    visitorTeamId: z.number().optional(),
    visitorTeamName: z.string().optional(),
    startingAtDate: z.string().optional(),
    startingAtTime: z.string().optional(),
    startingAtTimestamp: z.string().optional(),

    // H2H
    h2h_home_full_time: z.array(h2hEntrySchema).optional(),
    h2h_away_full_time: z.array(h2hEntrySchema).optional(),
    h2h_dois_full_time: z.array(h2hEntrySchema).optional(),

    // Prognostics
    prognosticos: z.string().optional(),

    // Statistics
    medias_home_goal: z.union([z.string(), z.number()]).optional(),
    medias_away_goal: z.union([z.string(), z.number()]).optional(),
    medias_home_possession: z.union([z.string(), z.number()]).optional(),
    medias_away_possession: z.union([z.string(), z.number()]).optional(),

    // Odds
    XBET_VENCEDOR_HOME: z.string().optional(),
    XBET_VENCEDOR_DRAW: z.string().optional(),
    XBET_VENCEDOR_AWAY: z.string().optional(),
  }).passthrough(),
});

export const x7ResponseSchema = z.object({
  fixtureId: z.number(),
  picks: z.record(z.unknown()).optional(),
  has_recommended: z.boolean().optional(),
  has_betsignal: z.boolean().optional(),
  has_ev: z.boolean().optional(),
  model_version: z.string().optional(),
  generated_at: z.string().optional(),
}).passthrough();

// ============================================================
// Export types
// ============================================================

export type FixtureBase = z.infer<typeof fixtureBaseSchema>;
export type CategorizedFixtures = z.infer<typeof categorizedFixturesSchema>;
export type FixturesResponse = z.infer<typeof fixturesResponseSchema>;
export type FixtureDetailResponse = z.infer<typeof fixtureDetailResponseSchema>;
export type X7Response = z.infer<typeof x7ResponseSchema>;
export type H2HEntry = z.infer<typeof h2hEntrySchema>;
export type Prognosticos = z.infer<typeof prognosticosSchema>;
