import { Pool } from 'pg';
import type { NormalizedMatch } from '../types/schemas.js';
import { isMainLeague } from '../utils/league-filter.js';

export interface UpcomingOver05FtPrediction {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  probability: number;
  modelOdd: number | undefined;
  combinedGoalsAverage: number | undefined;
  over05Percentage: number | undefined;
  bttsPercentage: number | undefined;
}

export interface UpcomingOver05HtPrediction extends UpcomingOver05FtPrediction {}

// Curated 1ª divisão (A) por país, usando exatamente os nomes de competição do dado
// coletado. Cobre só quem já apareceu no scrape — grandes ligas europeias (Premier
// League, La Liga, Bundesliga, Serie A, Ligue 1) ficam de fora em julho por estarem
// de férias; some novo país/liga aqui quando aparecer no /api/leagues.
const TOP_FLIGHT_LEAGUES: Array<[string, string]> = [
  ['Argentina', 'Liga Profesional de Fútbol'], ['Austria', 'Admiral Bundesliga'], ['Belarus', 'Vysshaya Liga'],
  ['Bolivia', 'Liga De Futbol Prof'], ['Brazil', 'Serie A'],
  ['Bulgaria', 'First League'], ['Canada', 'Premier League'], ['Chile', 'Primera Division'], ['China', 'Super League'],
  ['Colombia', 'Liga BetPlay'], ['Costa Rica', 'Primera Division'], ['Croatia', '1. HNL'], ['Czech Republic', 'Chance Liga'],
  ['Denmark', 'Superliga'], ['Ecuador', 'Liga Pro'], ['El Salvador', 'Primera Division'], ['Estonia', 'Meistriliiga'],
  ['Finland', 'Veikkausliiga'],
  ['Guatemala', 'Liga Nacional'], ['Hungary', 'NB I'], ['Iceland', 'Besta deild'], ['Kazakhstan', 'Premier League'],
  ['Kuwait', 'Division 1'], ['Kyrgyzstan', 'Top Liga'], ['Latvia', 'Virsliga'], ['Lebanon', 'Premier League'],
  ['Lithuania', 'A Lyga'], ['Macau', 'Primeira Division'], ['Macedonia', 'First League'], ['Malawi', 'Super League'],
  ['Mexico', 'Liga MX'], ['Mozambique', 'Mocambola'], ['New Zealand', 'National League'], ['Nicaragua', 'Primera Division'],
  ['Norway', 'Eliteserien'], ['Panama', 'Lpf'], ['Paraguay', 'Division 1'], ['Peru', 'Primera Division'],
  ['Poland', 'Ekstraklasa'], ['Republic of Ireland', 'Premier Division'], ['Romania', 'Superliga'],
  ['Russia', 'Premier League'], ['Scotland', 'Premiership'], ['Serbia', 'Super Liga'], ['Slovakia', 'Niké Liga'],
  ['Slovenia', '1. SNL'], ['South Korea', 'K League 1'], ['Sri Lanka', 'Super League'], ['Sweden', 'Allsvenskan'],
  ['Switzerland', 'Super League'], ['Tajikistan', 'Vysshaya Liga'], ['Ukraine', 'Premier League'],
  ['United States', 'Major League Soccer'], ['Uruguay', 'Primera Division'],
  ['Uzbekistan', 'Super league'], ['Wales', 'Premier League'], ['Yemen', 'Yemeni League'], ['Zimbabwe', 'Premier Soccer League'],
];

// Curada 2ª divisão (B) por país — só onde o dado coletado já tem o nome exato da
// competição, cruzado com a estrutura real de cada pirâmide nacional. Onde o país não
// tem 2ª divisão ainda raspada, fica de fora (não adivinha nome).
const SECOND_DIVISION_LEAGUES: Array<[string, string]> = [
  ['Argentina', 'Primera B Nacional'], ['Austria', '2. Liga'], ['Belarus', 'Pershaya Liga'],
  ['Brazil', 'Serie B'], ['Bulgaria', 'Second League'], ['Chile', 'Primera B'], ['China', 'League One'],
  ['Colombia', 'Primera B'], ['Czech Republic', 'Chance Národní Liga'], ['Denmark', 'First Division'],
  ['Ecuador', 'Liga Pro Serie B'], ['Estonia', 'Esiliiga A'], ['Finland', 'Ykkösliiga'],
  ['Guatemala', 'Primera Division'], ['Hungary', 'Merkantil Bank Liga'], ['Iceland', 'Inkasso-Deildin'],
  ['Kazakhstan', 'First Division'], ['Latvia', 'First Liga'], ['Lithuania', '1. Lyga'], ['Mexico', 'Liga de Expansión MX'],
  ['Norway', '1. Division'], ['Panama', 'Liga Prom'], ['Paraguay', 'Division Intermedia'], ['Peru', 'Segunda Division'],
  ['Poland', '1. Liga'], ['Republic of Ireland', 'First Division'], ['Russia', 'FNL'], ['Serbia', 'Prva Liga'],
  ['Slovakia', '2. Liga'], ['South Korea', 'K League 2'], ['Sweden', 'Superettan'], ['Switzerland', 'Challenge League'],
  ['Ukraine', 'Persha Liga'], ['United States', 'USL Championship'], ['Uruguay', 'Segunda Division'],
  ['Uzbekistan', 'Pro Liga'],
];

export interface KnownLeague {
  country: string;
  competition: string;
  isTopFlight: boolean;
  // 'A' (1ª divisão) | 'B' (2ª divisão) | undefined (não classificada).
  division: 'A' | 'B' | undefined;
}

export const GOAL_MARKETS = [
  { key: 'gols_1t_05_over', label: 'Over 0.5 1T' },
  { key: 'gols_1t_15_over', label: 'Over 1.5 1T' },
  { key: 'over_05_ft_over', label: 'Over 0.5 FT' },
  { key: 'over_15_ft_over', label: 'Over 1.5 FT' },
  { key: 'over_25_ft_over', label: 'Over 2.5 FT' },
  { key: 'over_35_ft_under', label: 'Under 3.5 FT' },
  { key: 'btts_sim', label: 'BTTS' },
] as const;

export interface TodayMatch {
  providerMatchId: string;
  kickoffAt: string | undefined;
  kickoffBrasilia: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  status: string | undefined;
  liveMinute: string | undefined;
  homeScore: number | undefined;
  awayScore: number | undefined;
  combinedGoalsAverage: number | undefined;
  bttsPercentage: number | undefined;
  // Sinais 0x0 aos 30' confirmados (0-4) — ver ZERO_AT_30_SIGNALS.
  signalScore: number;
  predictions: Record<string, { probability: number | undefined; modelOdd: number | undefined }>;
}

export interface HistoricalMarketResult {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  probability: number;
  halftimeGoals: number;
  finalGoals: number;
  firstGoalMinute: number | undefined;
  hit: boolean;
  // Green "até 75'": false quando não houve gol; undefined quando houve gol mas
  // o snapshot final não trouxe a timeline (impossível saber o minuto).
  by75Hit: boolean | undefined;
  bttsPercentage: number | undefined;
  // Última previsão pré-jogo de um mercado companheiro (ex.: Over 0.5 FT no
  // resultado de Over 0.5 HT) — usado por combos validados no dashboard.
  companionProbability: number | undefined;
}

export interface HistoricalOver05HtResult extends HistoricalMarketResult {
  ftHit: boolean;
}

export interface HistoricalOver05FtResult extends HistoricalMarketResult {
  htHit: boolean;
}

// Padrão "0x0 aos 30'": jogo sem gol até os 30' que teve (ou não) gol até os 75'.
export interface ZeroAt30Result {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  halftimeGoals: number;
  finalGoals: number;
  firstGoalMinute: number | undefined;
  goalBand: string;
  over05HtProbability: number | undefined;
  over05FtProbability: number | undefined;
  over15FtProbability: number | undefined;
  over25FtProbability: number | undefined;
  bttsProbability: number | undefined;
  combinedGoalsAverage: number | undefined;
  over05Percentage: number | undefined;
  bttsPercentage: number | undefined;
  // Quantos dos 4 sinais de gol o jogo confirma (0-4): O2.5 FT >= 55,
  // BTTS >= 55, BTTS% dos times >= 60, média de gols >= 2.8.
  signalScore: number;
  hit: boolean;
}

// Estudo "Over 1.5 FT" (jogo inteiro, sem condicionar a placar aos 30'): descoberto por
// busca exaustiva com split aleatório (1567 jogos — cobertura de sinal x7/BTTS% ainda
// concentrada em poucos dias do banco, então validação temporal não é confiável ainda).
// Score 2/4 rendeu 83% de green tanto no treino quanto na validação (base ~74-76%).
export interface Over15FtResult {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  finalGoals: number;
  halftimeGoals: number;
  firstGoalMinute: number | undefined;
  over25FtProbability: number | undefined;
  bttsProbability: number | undefined;
  over05Percentage: number | undefined;
  combinedGoalsAverage: number | undefined;
  // Alias de over25FtProbability pra reaproveitar o corte ≥70/75/80/85/90% já existente na UI.
  probability: number;
  // Quantos dos 4 sinais o jogo confirma (0-4): O2.5 FT >= 70, BTTS >= 65,
  // média de gols >= 3.8, Over 0.5% dos times >= 90.
  signalScore: number;
  hit: boolean;
  // Mesmo score também funciona como régua pro Over 0.5 FT (dose-resposta monotônica
  // nos dois mercados juntos): score 0 -> 93%, score 4 -> 100%.
  hitOver05: boolean;
}

export interface UpcomingOver15FtSignal {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  over25FtProbability: number | undefined;
  bttsProbability: number | undefined;
  over05Percentage: number | undefined;
  combinedGoalsAverage: number | undefined;
  probability: number;
  signalScore: number;
}

// Um jogo (passado ou futuro) com as 4 previsões usadas no filtro "O0.5FT>=85 & U3.5FT<=70"
// validado por backtest. finalGoals só vem preenchido quando o jogo já terminou.
export interface DailyPick {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  status: string | undefined;
  finalGoals: number | undefined;
  homeScore: number | undefined;
  awayScore: number | undefined;
  over05: number | undefined;
  under35: number | undefined;
  over15: number | undefined;
  over25: number | undefined;
}

export interface PostgresConfig {
  host: string;
  port: number;
  database: string;
  user: string;
  password: string;
  schema?: string;
}

const getOver05FtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.over_05_ft_over?.pred;

const getOver05FtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.over_05_ft_over?.oj;

const getOver05HtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.gols_1t_05_over?.pred;

const getOver05HtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.gols_1t_05_over?.oj;

const getOver15FtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.over_15_ft_over?.pred;

const getOver15FtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.over_15_ft_over?.oj;

const getOver15HtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.gols_1t_15_over?.pred;

const getOver15HtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.gols_1t_15_over?.oj;

const getOver25FtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.over_25_ft_over?.pred;

const getUnder35FtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.over_35_ft_under?.pred;

const getUnder35FtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.over_35_ft_under?.oj;

const getBttsPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.btts_sim?.pred;

type ZeroAt30Signals = Pick<ZeroAt30Result, 'over25FtProbability' | 'bttsProbability' | 'bttsPercentage' | 'combinedGoalsAverage'>;

// Cortes descobertos por busca exaustiva com validacao temporal (644 jogos):
// score 4/4 rendeu 88% de green (93% na validacao); 0/4 apenas 63% (53% na validacao).
const ZERO_AT_30_SIGNALS: Array<(signals: ZeroAt30Signals) => boolean> = [
  (signals) => (signals.over25FtProbability ?? 0) >= 55,
  (signals) => (signals.bttsProbability ?? 0) >= 55,
  (signals) => (signals.bttsPercentage ?? 0) >= 60,
  (signals) => (signals.combinedGoalsAverage ?? 0) >= 2.8,
];

const getSignalScore = (signals: ZeroAt30Signals): number =>
  ZERO_AT_30_SIGNALS.filter((check) => check(signals)).length;

type Over15FtSignals = Pick<Over15FtResult, 'over25FtProbability' | 'bttsProbability' | 'combinedGoalsAverage' | 'over05Percentage'>;

// Cortes descobertos por busca exaustiva com split aleatorio (1567 jogos, ver nota na
// interface Over15FtResult): score 2/4 rendeu 83% de green tanto em treino quanto validacao.
const OVER_15_FT_SIGNALS: Array<(signals: Over15FtSignals) => boolean> = [
  (signals) => (signals.over25FtProbability ?? 0) >= 70,
  (signals) => (signals.bttsProbability ?? 0) >= 65,
  (signals) => (signals.combinedGoalsAverage ?? 0) >= 3.8,
  (signals) => (signals.over05Percentage ?? 0) >= 90,
];

const getOver15FtSignalScore = (signals: Over15FtSignals): number =>
  OVER_15_FT_SIGNALS.filter((check) => check(signals)).length;

const getGoalBand = (minute: number | undefined): string => {
  if (minute === undefined) return '—';
  if (minute <= 45) return "31–45'";
  if (minute <= 60) return "46–60'";
  if (minute <= 75) return "61–75'";
  return "76+'";
};

// Lista de INCLUSÃO (não exclusão): só considera "pendente" status sabidamente ainda
// em andamento. Descobrimos que 'FTP' e 'after_extra_time' são terminais de verdade
// (já vêm com placar final) mas nunca viram literalmente 'finished' — uma lista de
// exclusão (tipo "tudo que não é finished") reconferiria esses pra sempre, gastando
// chamada de API à toa todo dia. Status desconhecido = tratado como resolvido (mais
// seguro que loop infinito).
const STILL_PENDING_STATUSES = new Set(['not_started', 'live', 'half_time', '1st', '2nd', 'et', 'extra_time']);
export const isStillPending = (status: string | undefined): boolean => STILL_PENDING_STATUSES.has(status ?? '');

const groupByFixture = (matches: NormalizedMatch[]): Map<string, NormalizedMatch[]> =>
  matches.reduce((groups, match) => {
    if (!match.providerMatchId) return groups;
    const snapshots = groups.get(match.providerMatchId) ?? [];
    snapshots.push(match);
    groups.set(match.providerMatchId, snapshots);
    return groups;
  }, new Map<string, NormalizedMatch[]>());

export class PostgresMatchStore {
  private readonly pool: Pool;
  private readonly schema: string;
  private readonly ready: Promise<void>;

  constructor(config: PostgresConfig) {
    this.schema = config.schema ?? 'public';
    this.pool = new Pool({
      host: config.host,
      port: config.port,
      database: config.database,
      user: config.user,
      password: config.password,
    });
    this.ready = this.initializeSchema();
  }

  async saveMatches(matches: NormalizedMatch[]): Promise<void> {
    await this.ready;
    const validMatches = matches.filter((match): match is NormalizedMatch & { providerMatchId: string } => Boolean(match.providerMatchId));
    if (!validMatches.length) return;

    const client = await this.pool.connect();
    try {
      await client.query('BEGIN');

      const snapshotColumns = 20;
      const snapshotValues = validMatches
        .map((_, i) => `(${Array.from({ length: snapshotColumns }, (_, j) => `$${i * snapshotColumns + j + 1}`).join(',')})`)
        .join(',');
      const snapshotParams = validMatches.flatMap((match) => [
        match.providerMatchId,
        match.collectedAt,
        match.kickoffAt ?? null,
        match.matchDate ?? null,
        match.status ?? null,
        match.competition ?? null,
        match.homeTeam.name,
        match.awayTeam.name,
        match.score?.home ?? null,
        match.score?.away ?? null,
        match.score?.halftimeHome ?? null,
        match.score?.halftimeAway ?? null,
        getOver05FtPrediction(match) ?? null,
        getOver05FtModelOdd(match) ?? null,
        match.statistics?.combinedGoalsAverage ?? null,
        match.statistics?.over05Percentage ?? null,
        match.statistics?.bttsPercentage ?? null,
        getOver05HtPrediction(match) ?? null,
        getOver05HtModelOdd(match) ?? null,
        JSON.stringify(match),
      ]);

      // Batched into one round trip per save (instead of one per match) — this
      // runs against a remote server, and a scrape/live-refresh batch can be
      // hundreds of matches, so per-row round trips would be painfully slow.
      await client.query(
        `INSERT INTO ${this.t('match_snapshots')} (
          provider_match_id, collected_at, kickoff_at, match_date, status, competition,
          home_team, away_team, home_score, away_score, halftime_home, halftime_away,
          over_05_ft_prediction, over_05_ft_model_odd, combined_goals_average,
          over_05_percentage, btts_percentage, over_05_ht_prediction, over_05_ht_model_odd, match_json
        ) VALUES ${snapshotValues}
        ON CONFLICT (provider_match_id, collected_at) DO UPDATE SET
          status = excluded.status, home_score = excluded.home_score, away_score = excluded.away_score,
          halftime_home = excluded.halftime_home, halftime_away = excluded.halftime_away,
          over_05_ft_prediction = excluded.over_05_ft_prediction, over_05_ft_model_odd = excluded.over_05_ft_model_odd,
          over_05_ht_prediction = excluded.over_05_ht_prediction, over_05_ht_model_odd = excluded.over_05_ht_model_odd,
          match_json = excluded.match_json`,
        snapshotParams,
      );

      const leaguePairs = new Map<string, [string, string]>();
      for (const match of validMatches) {
        if (match.competition && isMainLeague(match.competition)) {
          const country = match.country ?? 'Desconhecido';
          leaguePairs.set(`${country}|${match.competition}`, [country, match.competition]);
        }
      }
      if (leaguePairs.size) {
        const pairs = [...leaguePairs.values()];
        const leagueValues = pairs.map((_, i) => `($${i * 2 + 1}, $${i * 2 + 2}, FALSE)`).join(',');
        await client.query(
          `INSERT INTO ${this.t('leagues')} (country, competition, is_top_flight) VALUES ${leagueValues}
           ON CONFLICT (country, competition) DO NOTHING`,
          pairs.flat(),
        );
      }

      await client.query('COMMIT');
    } catch (error) {
      await client.query('ROLLBACK');
      throw error;
    } finally {
      client.release();
    }
  }

  // Saves matches from the plain fixture-list endpoint (score/status/minute only,
  // no X7 predictions or goal timeline). Merges each one onto the fixture's latest
  // known snapshot so a live-score refresh doesn't wipe out prior enrichment data.
  async saveLiveUpdates(matches: NormalizedMatch[]): Promise<void> {
    const previousByFixture = new Map((await this.getLatestSnapshots()).map((match) => [match.providerMatchId, match]));
    const merged = matches.map((match) => {
      const previous = match.providerMatchId ? previousByFixture.get(match.providerMatchId) : undefined;
      if (!previous) return match;
      return {
        ...match,
        status: match.status ?? previous.status,
        score: match.score ?? previous.score,
        statistics: previous.statistics ? { ...previous.statistics, ...match.statistics } : match.statistics,
      };
    });
    await this.saveMatches(merged);
  }

  async getUpcomingOver05FtPredictions(now: string): Promise<UpcomingOver05FtPrediction[]> {
    return this.buildUpcomingPredictions(now, getOver05FtPrediction, getOver05FtModelOdd);
  }

  async getHistoricalOver05FtResults(): Promise<HistoricalOver05FtResult[]> {
    const results = await this.buildHistoricalResults(getOver05FtPrediction, (settled) => (settled.score?.home ?? 0) + (settled.score?.away ?? 0) >= 1);
    return results.map((result) => ({ ...result, htHit: result.halftimeGoals >= 1 }));
  }

  async getUpcomingUnder35FtPredictions(now: string): Promise<UpcomingOver05FtPrediction[]> {
    return this.buildUpcomingPredictions(now, getUnder35FtPrediction, getUnder35FtModelOdd);
  }

  async getHistoricalUnder35FtResults(): Promise<HistoricalOver05FtResult[]> {
    const results = await this.buildHistoricalResults(getUnder35FtPrediction, (settled) => (settled.score?.home ?? 0) + (settled.score?.away ?? 0) <= 3);
    return results.map((result) => ({ ...result, htHit: result.halftimeGoals <= 3 }));
  }

  async getUpcomingOver05HtPredictions(now: string): Promise<UpcomingOver05HtPrediction[]> {
    return this.buildUpcomingPredictions(now, getOver05HtPrediction, getOver05HtModelOdd);
  }

  async getHistoricalOver05HtResults(): Promise<HistoricalOver05HtResult[]> {
    const results = await this.buildHistoricalResults(
      getOver05HtPrediction,
      (settled) => (settled.score?.halftimeHome ?? 0) + (settled.score?.halftimeAway ?? 0) >= 1,
      getOver05FtPrediction,
    );
    return results.map((result) => ({ ...result, ftHit: result.finalGoals >= 1 }));
  }

  async getUpcomingOver15HtPredictions(now: string): Promise<UpcomingOver05HtPrediction[]> {
    return this.buildUpcomingPredictions(now, getOver15HtPrediction, getOver15HtModelOdd);
  }

  async getHistoricalOver15HtResults(): Promise<HistoricalOver05HtResult[]> {
    const results = await this.buildHistoricalResults(getOver15HtPrediction, (settled) => (settled.score?.halftimeHome ?? 0) + (settled.score?.halftimeAway ?? 0) >= 2);
    return results.map((result) => ({ ...result, ftHit: result.finalGoals >= 2 }));
  }

  async getUpcomingOver15FtPredictions(now: string): Promise<UpcomingOver05FtPrediction[]> {
    return this.buildUpcomingPredictions(now, getOver15FtPrediction, getOver15FtModelOdd);
  }

  async getHistoricalOver15FtResults(): Promise<HistoricalMarketResult[]> {
    return this.buildHistoricalResults(getOver15FtPrediction, (settled) => (settled.score?.home ?? 0) + (settled.score?.away ?? 0) >= 2);
  }

  async getZeroAt30Results(): Promise<ZeroAt30Result[]> {
    const byFixture = groupByFixture(await this.getAllSnapshots());

    return [...byFixture.entries()].flatMap(([providerMatchId, snapshots]) => {
      const settled = snapshots.filter((match) => match.status === 'finished').sort(byLatestCollection).at(-1);
      const kickoffAt = settled?.kickoffAt;
      if (!settled || !kickoffAt) return [];
      const finalGoals = (settled.score?.home ?? 0) + (settled.score?.away ?? 0);
      const goalMinutes = settled.statistics?.goals?.map((goal) => goal.minute) ?? [];
      // Com gol mas sem timeline não dá para saber o placar aos 30' — fica de fora.
      if (finalGoals > 0 && goalMinutes.length === 0) return [];
      // Gol até os 30' significa que o jogo não estava 0x0 no corte — fica de fora.
      if (goalMinutes.some((minute) => minute <= 30)) return [];
      const firstGoalMinute = goalMinutes.length ? Math.min(...goalMinutes) : undefined;
      const preKickoff = snapshots.filter((match) => match.collectedAt < kickoffAt).sort(byLatestCollection);
      const lastPreKickoffValue = (getValue: (match: NormalizedMatch) => number | undefined): number | undefined =>
        preKickoff.map(getValue).filter((value): value is number => value !== undefined).at(-1);
      const signals: ZeroAt30Signals = {
        over25FtProbability: lastPreKickoffValue(getOver25FtPrediction),
        bttsProbability: lastPreKickoffValue(getBttsPrediction),
        bttsPercentage: lastPreKickoffValue((match) => match.statistics?.bttsPercentage),
        combinedGoalsAverage: lastPreKickoffValue((match) => match.statistics?.combinedGoalsAverage),
      };
      return [{
        providerMatchId,
        kickoffAt,
        country: settled.country,
        competition: settled.competition,
        homeTeam: settled.homeTeam.name,
        awayTeam: settled.awayTeam.name,
        halftimeGoals: (settled.score?.halftimeHome ?? 0) + (settled.score?.halftimeAway ?? 0),
        finalGoals,
        firstGoalMinute,
        goalBand: getGoalBand(firstGoalMinute),
        over05HtProbability: lastPreKickoffValue(getOver05HtPrediction),
        over05FtProbability: lastPreKickoffValue(getOver05FtPrediction),
        over15FtProbability: lastPreKickoffValue(getOver15FtPrediction),
        over05Percentage: lastPreKickoffValue((match) => match.statistics?.over05Percentage),
        ...signals,
        signalScore: getSignalScore(signals),
        hit: firstGoalMinute !== undefined && firstGoalMinute <= 75,
      }];
    }).sort((a, b) => (b.kickoffAt ?? '').localeCompare(a.kickoffAt ?? ''));
  }

  async getOver15FtSignalResults(): Promise<Over15FtResult[]> {
    const byFixture = groupByFixture(await this.getAllSnapshots());

    return [...byFixture.entries()].flatMap(([providerMatchId, snapshots]) => {
      const settled = snapshots.filter((match) => match.status === 'finished').sort(byLatestCollection).at(-1);
      const kickoffAt = settled?.kickoffAt;
      if (!settled || !kickoffAt) return [];
      const finalGoals = (settled.score?.home ?? 0) + (settled.score?.away ?? 0);
      const halftimeGoals = (settled.score?.halftimeHome ?? 0) + (settled.score?.halftimeAway ?? 0);
      const goalMinutes = settled.statistics?.goals?.map((goal) => goal.minute) ?? [];
      const firstGoalMinute = goalMinutes.length ? Math.min(...goalMinutes) : undefined;
      const preKickoff = snapshots.filter((match) => match.collectedAt < kickoffAt).sort(byLatestCollection);
      const lastPreKickoffValue = (getValue: (match: NormalizedMatch) => number | undefined): number | undefined =>
        preKickoff.map(getValue).filter((value): value is number => value !== undefined).at(-1);
      const signals: Over15FtSignals = {
        over25FtProbability: lastPreKickoffValue(getOver25FtPrediction),
        bttsProbability: lastPreKickoffValue(getBttsPrediction),
        combinedGoalsAverage: lastPreKickoffValue((match) => match.statistics?.combinedGoalsAverage),
        over05Percentage: lastPreKickoffValue((match) => match.statistics?.over05Percentage),
      };
      return [{
        providerMatchId,
        kickoffAt,
        country: settled.country,
        competition: settled.competition,
        homeTeam: settled.homeTeam.name,
        awayTeam: settled.awayTeam.name,
        finalGoals,
        halftimeGoals,
        firstGoalMinute,
        ...signals,
        probability: signals.over25FtProbability ?? 0,
        signalScore: getOver15FtSignalScore(signals),
        hit: finalGoals >= 2,
        hitOver05: finalGoals >= 1,
      }];
    }).sort((a, b) => (b.kickoffAt ?? '').localeCompare(a.kickoffAt ?? ''));
  }

  async getUpcomingOver15FtSignals(now: string): Promise<UpcomingOver15FtSignal[]> {
    return (await this.getLatestSnapshots())
      .filter((match) => isMainLeague(match.competition))
      .filter((match) => match.status === 'not_started' && (match.kickoffAt ?? '') > now)
      .map((match) => {
        const signals: Over15FtSignals = {
          over25FtProbability: getOver25FtPrediction(match),
          bttsProbability: getBttsPrediction(match),
          combinedGoalsAverage: match.statistics?.combinedGoalsAverage,
          over05Percentage: match.statistics?.over05Percentage,
        };
        return {
          providerMatchId: match.providerMatchId ?? '',
          kickoffAt: match.kickoffAt,
          country: match.country,
          competition: match.competition,
          homeTeam: match.homeTeam.name,
          awayTeam: match.awayTeam.name,
          ...signals,
          probability: signals.over25FtProbability ?? 0,
          signalScore: getOver15FtSignalScore(signals),
        };
      })
      .sort((a, b) => (a.kickoffAt ?? '').localeCompare(b.kickoffAt ?? '') || b.signalScore - a.signalScore);
  }

  // Cobre passado (jogos finalizados, pra analisar acerto histórico) e futuro (jogos de
  // hoje/próximos dias já importados) num único dataset — o front agrupa por dia.
  async getDailyPicks(): Promise<DailyPick[]> {
    const byFixture = groupByFixture(await this.getAllSnapshots());

    return [...byFixture.entries()].flatMap(([providerMatchId, snapshots]) => {
      const sorted = [...snapshots].sort(byLatestCollection);
      const latest = sorted.at(-1);
      if (!latest || !latest.kickoffAt) return [];
      const kickoffAt = latest.kickoffAt;
      const isFinished = latest.status === 'finished';
      const preKickoff = snapshots.filter((match) => match.collectedAt < kickoffAt).sort(byLatestCollection);
      const lastPreKickoffValue = (getValue: (match: NormalizedMatch) => number | undefined): number | undefined =>
        preKickoff.map(getValue).filter((value): value is number => value !== undefined).at(-1);
      return [{
        providerMatchId,
        kickoffAt,
        country: latest.country,
        competition: latest.competition,
        homeTeam: latest.homeTeam.name,
        awayTeam: latest.awayTeam.name,
        status: latest.status,
        finalGoals: isFinished ? (latest.score?.home ?? 0) + (latest.score?.away ?? 0) : undefined,
        homeScore: isFinished ? latest.score?.home : undefined,
        awayScore: isFinished ? latest.score?.away : undefined,
        over05: lastPreKickoffValue(getOver05FtPrediction),
        under35: lastPreKickoffValue(getUnder35FtPrediction),
        over15: lastPreKickoffValue(getOver15FtPrediction),
        over25: lastPreKickoffValue(getOver25FtPrediction),
      }];
    }).sort((a, b) => (b.kickoffAt ?? '').localeCompare(a.kickoffAt ?? ''));
  }

  async getUnsettledMatches(dateBrasilia: string): Promise<NormalizedMatch[]> {
    const byFixture = groupByFixture(await this.getAllSnapshots());

    return [...byFixture.values()]
      .map((snapshots) => [...snapshots].sort(byLatestCollection).at(-1) as NormalizedMatch)
      .filter((match) => toBrasiliaDate(match.kickoffAt) === dateBrasilia)
      .filter((match) => isStillPending(match.status))
      .sort((a, b) => (a.kickoffAt ?? '').localeCompare(b.kickoffAt ?? ''));
  }

  async getTodayMatches(dateBrasilia: string): Promise<TodayMatch[]> {
    const byFixture = groupByFixture(await this.getAllSnapshots());

    return [...byFixture.values()]
      .map((snapshots) => {
        const sorted = [...snapshots].sort(byLatestCollection);
        const latest = sorted.at(-1) as NormalizedMatch;
        const latestWithStats = [...sorted].reverse().find((match) => match.statistics?.additional?.x7Predictions);
        if (!latestWithStats || latestWithStats === latest) return latest;
        return { ...latest, statistics: { ...latestWithStats.statistics, ...latest.statistics } };
      })
      .filter((match) => toBrasiliaDate(match.kickoffAt) === dateBrasilia)
      .sort((a, b) => (a.kickoffAt ?? '').localeCompare(b.kickoffAt ?? ''))
      .map(toTodayMatch);
  }

  async getAllSnapshots(): Promise<NormalizedMatch[]> {
    await this.ready;
    const { rows } = await this.pool.query(`SELECT match_json FROM ${this.t('match_snapshots')} ORDER BY collected_at ASC`);
    return rows
      .map((row) => row.match_json as NormalizedMatch)
      .filter((match) => isMainLeague(match.competition));
  }

  async getKnownLeagues(): Promise<KnownLeague[]> {
    await this.ready;
    const { rows } = await this.pool.query(
      `SELECT country, competition, is_top_flight AS "isTopFlight", division FROM ${this.t('leagues')} ORDER BY country, competition`,
    );
    return rows.map((row) => ({
      country: String(row.country),
      competition: String(row.competition),
      isTopFlight: Boolean(row.isTopFlight),
      division: row.division === 'A' || row.division === 'B' ? row.division : undefined,
    }));
  }

  async close(): Promise<void> {
    await this.pool.end();
  }

  private async buildHistoricalResults(
    getPrediction: (match: NormalizedMatch) => number | undefined,
    hitCheck: (settled: NormalizedMatch) => boolean,
    getCompanionPrediction?: (match: NormalizedMatch) => number | undefined,
  ): Promise<HistoricalMarketResult[]> {
    const byFixture = groupByFixture(await this.getAllSnapshots());

    return [...byFixture.entries()].flatMap(([providerMatchId, snapshots]) => {
      const settled = snapshots.filter((match) => match.status === 'finished').sort(byLatestCollection).at(-1);
      const kickoffAt = settled?.kickoffAt;
      if (!settled || !kickoffAt) return [];
      const predicted = snapshots
        .filter((match) => match.collectedAt < kickoffAt && getPrediction(match) !== undefined)
        .sort(byLatestCollection)
        .at(-1);
      const probability = predicted && getPrediction(predicted);
      if (!predicted || probability === undefined) return [];
      const halftimeGoals = (settled.score?.halftimeHome ?? 0) + (settled.score?.halftimeAway ?? 0);
      const finalGoals = (settled.score?.home ?? 0) + (settled.score?.away ?? 0);
      const goalMinutes = settled.statistics?.goals?.map((goal) => goal.minute) ?? [];
      const firstGoalMinute = goalMinutes.length ? Math.min(...goalMinutes) : undefined;
      const by75Hit = firstGoalMinute !== undefined ? firstGoalMinute <= 75
        : finalGoals === 0 ? false : undefined;
      const preKickoff = snapshots.filter((match) => match.collectedAt < kickoffAt).sort(byLatestCollection);
      const lastPreKickoffValue = (getValue: (match: NormalizedMatch) => number | undefined): number | undefined =>
        preKickoff.map(getValue).filter((value): value is number => value !== undefined).at(-1);
      return [{
        providerMatchId,
        kickoffAt,
        country: settled.country,
        competition: settled.competition,
        homeTeam: settled.homeTeam.name,
        awayTeam: settled.awayTeam.name,
        probability,
        halftimeGoals,
        finalGoals,
        firstGoalMinute,
        hit: hitCheck(settled),
        by75Hit,
        bttsPercentage: lastPreKickoffValue((match) => match.statistics?.bttsPercentage),
        companionProbability: getCompanionPrediction ? lastPreKickoffValue(getCompanionPrediction) : undefined,
      }];
    });
  }

  private async buildUpcomingPredictions(
    now: string,
    getPrediction: (match: NormalizedMatch) => number | undefined,
    getModelOdd: (match: NormalizedMatch) => number | undefined,
  ): Promise<UpcomingOver05FtPrediction[]> {
    return (await this.getLatestSnapshots())
      .filter((match) => isMainLeague(match.competition))
      .filter((match) => match.status === 'not_started' && (match.kickoffAt ?? '') > now)
      .flatMap((match) => {
        const probability = getPrediction(match);
        if (probability === undefined) return [];
        return [{
          providerMatchId: match.providerMatchId ?? '',
          kickoffAt: match.kickoffAt,
          country: match.country,
          competition: match.competition,
          homeTeam: match.homeTeam.name,
          awayTeam: match.awayTeam.name,
          probability,
          modelOdd: getModelOdd(match),
          combinedGoalsAverage: match.statistics?.combinedGoalsAverage,
          over05Percentage: match.statistics?.over05Percentage,
          bttsPercentage: match.statistics?.bttsPercentage,
        }];
      })
      .sort((a, b) => (a.kickoffAt ?? '').localeCompare(b.kickoffAt ?? '') || b.probability - a.probability);
  }

  private async getLatestSnapshots(): Promise<NormalizedMatch[]> {
    await this.ready;
    const { rows } = await this.pool.query(`
      WITH latest AS (
        SELECT provider_match_id, MAX(collected_at) AS collected_at
        FROM ${this.t('match_snapshots')} GROUP BY provider_match_id
      )
      SELECT match_json FROM ${this.t('match_snapshots')}
      INNER JOIN latest USING (provider_match_id, collected_at)
    `);
    return rows.map((row) => row.match_json as NormalizedMatch);
  }

  private async initializeSchema(): Promise<void> {
    if (this.schema !== 'public') {
      await this.pool.query(`CREATE SCHEMA IF NOT EXISTS "${this.schema}"`);
    }
    await this.pool.query(`
      CREATE TABLE IF NOT EXISTS ${this.t('match_snapshots')} (
        provider_match_id TEXT NOT NULL,
        collected_at TEXT NOT NULL,
        kickoff_at TEXT,
        match_date TEXT,
        status TEXT,
        competition TEXT,
        home_team TEXT NOT NULL,
        away_team TEXT NOT NULL,
        home_score INTEGER,
        away_score INTEGER,
        halftime_home INTEGER,
        halftime_away INTEGER,
        over_05_ft_prediction REAL,
        over_05_ft_model_odd REAL,
        combined_goals_average REAL,
        over_05_percentage REAL,
        btts_percentage REAL,
        over_05_ht_prediction REAL,
        over_05_ht_model_odd REAL,
        match_json JSONB NOT NULL,
        PRIMARY KEY (provider_match_id, collected_at)
      );
      CREATE INDEX IF NOT EXISTS match_snapshots_upcoming
        ON ${this.t('match_snapshots')} (status, kickoff_at, collected_at);
      CREATE TABLE IF NOT EXISTS ${this.t('leagues')} (
        country TEXT NOT NULL,
        competition TEXT NOT NULL,
        is_top_flight BOOLEAN NOT NULL DEFAULT FALSE,
        division TEXT,
        PRIMARY KEY (country, competition)
      );
      ALTER TABLE ${this.t('leagues')} ADD COLUMN IF NOT EXISTS division TEXT;
    `);
    await this.backfillLeaguesIfEmpty();
    await this.seedTopFlightLeagues();
  }

  // One-time backfill: the `leagues` table starts empty on first run against an
  // existing database, so populate it from whatever match history is already there.
  private async backfillLeaguesIfEmpty(): Promise<void> {
    const { rows } = await this.pool.query(`SELECT COUNT(*) AS count FROM ${this.t('leagues')}`);
    if (Number(rows[0].count) > 0) return;
    const { rows: snapshotRows } = await this.pool.query(`SELECT match_json FROM ${this.t('match_snapshots')}`);
    const seen = new Map<string, [string, string]>();
    for (const row of snapshotRows) {
      const match = row.match_json as NormalizedMatch;
      if (!match.competition || !isMainLeague(match.competition)) continue;
      const country = match.country ?? 'Desconhecido';
      seen.set(`${country}|${match.competition}`, [country, match.competition]);
    }
    await this.upsertLeagues([...seen.values()], undefined);
  }

  private async seedTopFlightLeagues(): Promise<void> {
    await this.upsertLeagues(TOP_FLIGHT_LEAGUES, 'A');
    await this.upsertLeagues(SECOND_DIVISION_LEAGUES, 'B');
  }

  // Single batched round trip regardless of how many (country, competition) pairs
  // there are — called during store construction, so this must stay fast even
  // against a remote server.
  private async upsertLeagues(pairs: Array<[string, string]>, division: 'A' | 'B' | undefined): Promise<void> {
    if (!pairs.length) return;
    const isTopFlight = division === 'A';
    const values = pairs.map((_, i) => `($${i * 2 + 1}, $${i * 2 + 2}, ${isTopFlight}, ${division ? `'${division}'` : 'NULL'})`).join(',');
    const conflictAction = division
      ? `UPDATE SET is_top_flight = ${isTopFlight}, division = '${division}'`
      : 'NOTHING';
    await this.pool.query(
      `INSERT INTO ${this.t('leagues')} (country, competition, is_top_flight, division) VALUES ${values}
       ON CONFLICT (country, competition) DO ${conflictAction}`,
      pairs.flat(),
    );
  }

  private t(table: string): string {
    return this.schema === 'public' ? table : `"${this.schema}".${table}`;
  }
}

const byLatestCollection = (left: NormalizedMatch, right: NormalizedMatch): number => left.collectedAt.localeCompare(right.collectedAt);

// Brazil has used a fixed UTC-3 offset (no DST) since 2019 — safe to derive
// the Brasília calendar date straight from kickoffAt instead of trusting the
// precomputed `kickoffBrasilia` field, which is missing on some older snapshots.
const toBrasiliaDate = (kickoffAt: string | undefined): string | undefined => {
  if (!kickoffAt) return undefined;
  const timestamp = new Date(kickoffAt).getTime();
  if (Number.isNaN(timestamp)) return undefined;
  return new Date(timestamp - 3 * 60 * 60 * 1000).toISOString().slice(0, 10);
};

const toTodayMatch = (match: NormalizedMatch): TodayMatch => {
  const x7 = match.statistics?.additional?.x7Predictions as Record<string, { pred?: number; oj?: number }> | undefined;
  const predictions: Record<string, { probability: number | undefined; modelOdd: number | undefined }> = {};
  for (const { key } of GOAL_MARKETS) {
    predictions[key] = { probability: x7?.[key]?.pred, modelOdd: x7?.[key]?.oj };
  }
  const signals: ZeroAt30Signals = {
    over25FtProbability: getOver25FtPrediction(match),
    bttsProbability: getBttsPrediction(match),
    bttsPercentage: match.statistics?.bttsPercentage,
    combinedGoalsAverage: match.statistics?.combinedGoalsAverage,
  };
  return {
    providerMatchId: match.providerMatchId ?? '',
    kickoffAt: match.kickoffAt,
    kickoffBrasilia: match.kickoffBrasilia,
    country: match.country,
    competition: match.competition,
    homeTeam: match.homeTeam.name,
    awayTeam: match.awayTeam.name,
    status: match.status,
    liveMinute: match.liveMinute,
    homeScore: match.score?.home,
    awayScore: match.score?.away,
    combinedGoalsAverage: match.statistics?.combinedGoalsAverage,
    bttsPercentage: signals.bttsPercentage,
    signalScore: getSignalScore(signals),
    predictions,
  };
};
