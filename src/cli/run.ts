#!/usr/bin/env node

import { ScraperService } from '../services/scraper.js';
import { SokkerProApi } from '../api/client.js';
import { getEnv } from '../config/env.js';
import { getLogger } from '../utils/logger.js';
import { PostgresMatchStore } from '../storage/postgres-store.js';
import { importOutputDirectory } from '../storage/import-output.js';
import { normalizeFixtureFromList } from '../api/normalizer.js';
import type { Env } from '../config/env.js';

function createStore(env: Env): PostgresMatchStore {
  return new PostgresMatchStore({
    host: env.POSTGRES_HOST,
    port: env.POSTGRES_PORT,
    database: env.POSTGRES_DB,
    user: env.POSTGRES_USER,
    password: env.POSTGRES_PASSWORD,
    schema: env.POSTGRES_SCHEMA,
  });
}

// ============================================================
// CLI Entry Point
// ============================================================

function parseArgs(): { date: string; headless: boolean; command: string; from: string; to: string; days: number } {
  const args = process.argv.slice(2);
  let date = '';
  let headless = true;
  let command = 'scrape';
  let from = '';
  let to = '';
  let days = 1;

  // First positional arg is the command
  if (args.length > 0 && !args[0].startsWith('--')) {
    command = args[0];
  }

  for (const arg of args) {
    if (arg.startsWith('--date=')) {
      date = arg.split('=')[1] || '';
    }
    if (arg.startsWith('--from=')) {
      from = arg.split('=')[1] || '';
    }
    if (arg.startsWith('--to=')) {
      to = arg.split('=')[1] || '';
    }
    if (arg.startsWith('--days=')) {
      days = parseInt(arg.split('=')[1] || '1', 10) || 1;
    }
    if (arg === '--headless=false' || arg === '--no-headless') {
      headless = false;
    }
  }

  // Default to today if no date provided
  if (!date) {
    const now = new Date();
    date = now.toISOString().split('T')[0];
  }

  return { date, headless, command, from, to, days };
}

async function runScrape(date: string, from?: string, to?: string, days = 1): Promise<void> {
  const logger = getLogger();
  const scraper = new ScraperService();

  // Generate list of dates
  const dates: string[] = [];
  const startDate = new Date(date + 'T12:00:00');
  for (let i = 0; i < days; i++) {
    const d = new Date(startDate);
    d.setDate(d.getDate() - i);
    dates.push(d.toISOString().split('T')[0]);
  }

  logger.info({ dates, from, to }, 'Starting scraper for multiple days');

  let totalFound = 0;
  let totalProcessed = 0;
  let totalFailed = 0;

  for (const d of dates) {
    logger.info({ date: d }, `Scraping ${d}...`);
    const result = await scraper.scrape(d, { from, to });
    
    totalFound += result.summary.matchesFound;
    totalProcessed += result.summary.matchesProcessed;
    totalFailed += result.summary.matchesFailed;

    logger.info(
      {
        date: d,
        found: result.summary.matchesFound,
        processed: result.summary.matchesProcessed,
        failed: result.summary.matchesFailed,
      },
      `Completed ${d}`,
    );
  }

  logger.info(
    {
      days: dates.length,
      totalFound,
      totalProcessed,
      totalFailed,
    },
    'All days completed',
  );
}

// Adiciona/subtrai dias de calendário sobre uma string YYYY-MM-DD, sem depender de
// fuso horário (aritmética pura em UTC — a string já representa um dia civil).
function shiftDate(dateStr: string, days: number): string {
  const [year, month, day] = dateStr.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

async function runDaily(): Promise<void> {
  const logger = getLogger();
  const scraper = new ScraperService();
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
  const yesterday = shiftDate(today, -1);
  const tomorrow = shiftDate(today, 1);

  logger.info({ yesterday, today, tomorrow }, 'Daily job started');

  logger.info({ date: yesterday }, 'Step 1/3: rechecking unsettled matches from yesterday');
  const recheck = await scraper.rescrapeUnsettled(yesterday);
  logger.info(recheck, 'Step 1/3 done');

  logger.info({ date: today }, 'Step 2/3: scraping today');
  const todayResult = await scraper.scrape(today);
  logger.info(
    { status: todayResult.status, found: todayResult.summary.matchesFound, processed: todayResult.summary.matchesProcessed },
    'Step 2/3 done',
  );

  logger.info({ date: tomorrow }, 'Step 3/3: scraping tomorrow');
  const tomorrowResult = await scraper.scrape(tomorrow);
  logger.info(
    { status: tomorrowResult.status, found: tomorrowResult.summary.matchesFound, processed: tomorrowResult.summary.matchesProcessed },
    'Step 3/3 done',
  );

  logger.info(
    {
      yesterday: { date: yesterday, ...recheck },
      today: { date: today, status: todayResult.status, found: todayResult.summary.matchesFound, processed: todayResult.summary.matchesProcessed },
      tomorrow: { date: tomorrow, status: tomorrowResult.status, found: tomorrowResult.summary.matchesFound, processed: tomorrowResult.summary.matchesProcessed },
    },
    'Daily job finished',
  );
}

async function runSessionValidate(): Promise<void> {
  const logger = getLogger();
  logger.info('Validating SokkerPRO session...');

  // Since the API is public, session validation is about checking connectivity
  const api = new SokkerProApi();
  const today = new Date().toISOString().split('T')[0];

  try {
    const result = await api.getFixtures(today);
    if (result.success && result.data) {
      logger.info(
        {
          date: today,
          fixturesFound: result.data.fixtures_total,
          leagues: result.data.sortedCategorizedFixtures?.length || 0,
        },
        'Session/API is working',
      );
      process.exit(0);
    } else {
      logger.error('API returned unsuccessful response');
      process.exit(1);
    }
  } catch (error) {
    const msg = error instanceof Error ? error.message : String(error);
    logger.error({ error: msg }, 'Session validation failed');
    process.exit(1);
  }
}

async function runInspect(date: string): Promise<void> {
  const logger = getLogger();
  logger.info({ date }, 'Inspecting SokkerPRO API');

  const api = new SokkerProApi();

  // Fetch fixtures
  logger.info('Fetching fixtures...');
  const fixtures = await api.getFixtures(date);

  if (!fixtures.success || !fixtures.data) {
    logger.error('Failed to fetch fixtures');
    process.exit(1);
  }

  const leagues = fixtures.data.sortedCategorizedFixtures || [];
  let totalFixtures = 0;

  logger.info(
    {
      date,
      totalFixtures: fixtures.data.fixtures_total,
      leagues: leagues.length,
    },
    'Fixtures overview',
  );

  // Show sample fixtures
  for (const league of leagues.slice(0, 5)) {
    const fixturesInLeague = league.fixtures || [];
    totalFixtures += fixturesInLeague.length;

    logger.info(
      {
        league: league.leagueName,
        country: league.countryName,
        count: fixturesInLeague.length,
      },
      'League',
    );

    // Show first 2 fixtures from each league
    for (const fixture of fixturesInLeague.slice(0, 2)) {
      logger.info(
        {
          id: fixture.fixtureId,
          home: fixture.localTeamName,
          away: fixture.visitorTeamName,
          time: fixture.startingAtTime,
          status: fixture.status,
          odds: fixture.XBET_VENCEDOR_HOME
            ? {
                home: fixture.XBET_VENCEDOR_HOME,
                draw: fixture.XBET_VENCEDOR_DRAW,
                away: fixture.XBET_VENCEDOR_AWAY,
              }
            : undefined,
        },
        '  Fixture',
      );
    }
  }

  // Fetch detail for first fixture
  const firstLeague = leagues[0];
  if (firstLeague?.fixtures?.length > 0) {
    const firstFixture = firstLeague.fixtures[0];
    logger.info({ fixtureId: firstFixture.fixtureId }, 'Fetching detail for first fixture...');

    try {
      const detail = await api.getFixtureDetail(firstFixture.fixtureId);
      const d = detail.data;

      logger.info(
        {
          fixtureId: d.fixtureId,
          status: d.status,
          home: d.localTeamName,
          away: d.visitorTeamName,
          h2hHome: d.h2h_home_full_time?.length || 0,
          h2hAway: d.h2h_away_full_time?.length || 0,
          h2hBoth: d.h2h_dois_full_time?.length || 0,
        },
        'Detail fetched',
      );
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      logger.warn({ error: msg }, 'Failed to fetch detail');
    }
  }

  logger.info('Inspection complete');
}

async function runDatabaseImport(): Promise<void> {
  const env = getEnv();
  const store = createStore(env);
  try {
    const importedMatches = await importOutputDirectory(store, env.OUTPUT_PATH);
    console.log(`Imported ${importedMatches} match snapshots into Postgres (${env.POSTGRES_HOST}/${env.POSTGRES_DB})`);
  } finally {
    await store.close();
  }
}

async function runDashboard(): Promise<void> {
  const { createServer } = await import('node:http');
  const { readFile, readdir } = await import('node:fs/promises');
  const { join, extname } = await import('node:path');
  const { getEnv } = await import('../config/env.js');

  const env = getEnv();
  const PORT = env.DASHBOARD_PORT;
  const OUTPUT_DIR = env.OUTPUT_PATH;
  const DASHBOARD_DIR = join(import.meta.dirname, '..', 'dashboard');
  const store = createStore(env);

  const MIME_TYPES: Record<string, string> = {
    '.html': 'text/html',
    '.css': 'text/css',
    '.js': 'application/javascript',
    '.json': 'application/json',
  };

  const server = createServer(async (req, res) => {
    const url = new URL(req.url || '/', `http://localhost:${PORT}`);

    if (url.pathname === '/api/predictions/over-05-ft') {
      const minimumProbability = Number(url.searchParams.get('minProbability') || '0');
      const predictions = (await store.getUpcomingOver05FtPredictions(new Date().toISOString()))
        .filter((prediction) => prediction.probability >= minimumProbability);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(predictions));
      return;
    }

    if (url.pathname === '/api/history/over-05-ft') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getHistoricalOver05FtResults()));
      return;
    }

    if (url.pathname === '/api/history/zero-at-30') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getZeroAt30Results()));
      return;
    }

    if (url.pathname === '/api/history/over-15-ft-signal') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getOver15FtSignalResults()));
      return;
    }

    if (url.pathname === '/api/predictions/over-15-ft-signal') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getUpcomingOver15FtSignals(new Date().toISOString())));
      return;
    }

    if (url.pathname === '/api/predictions/over-05-ht') {
      const minimumProbability = Number(url.searchParams.get('minProbability') || '0');
      const predictions = (await store.getUpcomingOver05HtPredictions(new Date().toISOString()))
        .filter((prediction) => prediction.probability >= minimumProbability);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(predictions));
      return;
    }

    if (url.pathname === '/api/history/over-05-ht') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getHistoricalOver05HtResults()));
      return;
    }

    if (url.pathname === '/api/predictions/over-15-ht') {
      const minimumProbability = Number(url.searchParams.get('minProbability') || '0');
      const predictions = (await store.getUpcomingOver15HtPredictions(new Date().toISOString()))
        .filter((prediction) => prediction.probability >= minimumProbability);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(predictions));
      return;
    }

    if (url.pathname === '/api/history/over-15-ht') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getHistoricalOver15HtResults()));
      return;
    }

    if (url.pathname === '/api/predictions/over-15-ft') {
      const minimumProbability = Number(url.searchParams.get('minProbability') || '0');
      const predictions = (await store.getUpcomingOver15FtPredictions(new Date().toISOString()))
        .filter((prediction) => prediction.probability >= minimumProbability);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(predictions));
      return;
    }

    if (url.pathname === '/api/history/over-15-ft') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getHistoricalOver15FtResults()));
      return;
    }

    if (url.pathname === '/api/today') {
      const date = url.searchParams.get('date') || new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getTodayMatches(date)));
      return;
    }

    if (req.method === 'POST' && url.pathname === '/api/today/refresh') {
      const date = url.searchParams.get('date') || new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
      try {
        const api = new SokkerProApi();
        const collectedAt = new Date().toISOString();
        const fixturesResponse = await api.getFixtures(date);
        if (!fixturesResponse.success || !fixturesResponse.data) throw new Error('SokkerPRO retornou resposta sem sucesso');

        const matches = (fixturesResponse.data.sortedCategorizedFixtures ?? []).flatMap((category) =>
          (category.fixtures ?? []).map((fixture) => normalizeFixtureFromList(fixture, category, collectedAt, 'America/Sao_Paulo')),
        );
        await store.saveLiveUpdates(matches);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ updatedAt: collectedAt, matches: await store.getTodayMatches(date) }));
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        res.writeHead(502, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: message }));
      }
      return;
    }

    if (url.pathname === '/api/leagues') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getKnownLeagues()));
      return;
    }

    if (url.pathname === '/api/history/snapshots') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(await store.getAllSnapshots()));
      return;
    }

    // API: list available data files
    if (url.pathname === '/api/files') {
      try {
        const files = await readdir(OUTPUT_DIR);
        const jsonFiles = files.filter((f) => f.endsWith('.json'));
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(jsonFiles));
      } catch {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end('[]');
      }
      return;
    }

    // API: load a specific data file
    if (url.pathname === '/api/data') {
      const file = url.searchParams.get('file');
      if (!file) {
        res.writeHead(400);
        res.end('Missing file parameter');
        return;
      }
      try {
        const content = await readFile(join(OUTPUT_DIR, file), 'utf-8');
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(content);
      } catch {
        res.writeHead(404);
        res.end('File not found');
      }
      return;
    }

    // Serve static files
    const filePath = url.pathname === '/' ? '/index.html' : url.pathname;
    try {
      const content = await readFile(join(DASHBOARD_DIR, filePath));
      const ext = extname(filePath);
      res.writeHead(200, { 'Content-Type': MIME_TYPES[ext] || 'text/plain' });
      res.end(content);
    } catch {
      res.writeHead(404);
      res.end('Not found');
    }
  });

  server.listen(PORT, () => {
    console.log(`\n  SokkerPRO Dashboard`);
    console.log(`  http://localhost:${PORT}\n`);
  });
}

async function main(): Promise<void> {
  const { date, command, from, to, days } = parseArgs();

  switch (command) {
    case 'scrape':
      await runScrape(date, from, to, days);
      break;
    case 'daily':
      await runDaily();
      break;
    case 'dashboard':
      await runDashboard();
      break;
    case 'database:import':
      await runDatabaseImport();
      break;
    case 'session:create':
      console.log('Session creation is not needed for SokkerPRO API (public endpoints).');
      console.log('The API at m2.sokkerpro.com does not require authentication for match data.');
      console.log('You can proceed directly with: npm run scrape');
      break;
    case 'session:validate':
      await runSessionValidate();
      break;
    case 'inspect':
      await runInspect(date);
      break;
    default:
      console.error(`Unknown command: ${command}`);
      console.log('Available commands: scrape, daily, dashboard, inspect, database:import, session:create, session:validate');
      process.exit(1);
  }
}

main().catch((error) => {
  console.error('Fatal error:', error);
  process.exit(1);
});
