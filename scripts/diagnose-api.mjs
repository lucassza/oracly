#!/usr/bin/env node
/**
 * Diagnóstico rápido: API SokkerPRO + Postgres Oracly
 * Uso: node scripts/diagnose-api.mjs [YYYY-MM-DD]
 */
import pg from 'pg';
import { readFileSync, existsSync } from 'node:fs';

const date = process.argv[2] ?? new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const prev = new Date(`${date}T12:00:00Z`);
prev.setUTCDate(prev.getUTCDate() - 1);
const prevDate = prev.toISOString().slice(0, 10);

function loadEnv() {
  const path = new URL('../.env', import.meta.url).pathname;
  if (!existsSync(path)) return {};
  const out = {};
  for (const line of readFileSync(path, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z_]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}

const env = loadEnv();
const apiBase = env.SOKKERPRO_API_BASE_URL ?? 'https://m2.sokkerpro.com';

async function probeApi(label, d) {
  const url = `${apiBase}/home/fixtures/${d}/utc-3`;
  const t0 = Date.now();
  try {
    const res = await fetch(url, {
      headers: {
        Accept: 'application/json',
        Referer: 'https://sokkerpro.com/',
        Origin: 'https://sokkerpro.com',
        'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36',
      },
      signal: AbortSignal.timeout(30000),
    });
    const ms = Date.now() - t0;
    const text = await res.text();
    let body;
    try {
      body = JSON.parse(text);
    } catch {
      body = { _parseError: true, preview: text.slice(0, 300) };
    }
    return {
      label,
      date: d,
      httpStatus: res.status,
      ms,
      success: body?.success,
      fixturesTotal: body?.data?.fixtures_total,
      leagues: body?.data?.sortedCategorizedFixtures?.length,
      preview: body?.success === false ? text.slice(0, 300) : undefined,
    };
  } catch (err) {
    return { label, date: d, error: err.message, ms: Date.now() - t0 };
  }
}

async function probePostgres(d) {
  const pool = new pg.Pool({
    host: env.POSTGRES_HOST ?? '127.0.0.1',
    port: Number(env.POSTGRES_PORT ?? 5433),
    database: env.POSTGRES_DB ?? 'oracly',
    user: env.POSTGRES_USER ?? 'oracly_user',
    password: env.POSTGRES_PASSWORD,
  });
  try {
    const startUtc = `${d}T03:00:00.000Z`;
    const end = new Date(`${d}T12:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 1);
    const endUtc = end.toISOString().slice(0, 10) + 'T03:00:00.000Z';
    const { rows } = await pool.query(
      `SELECT COUNT(DISTINCT provider_match_id)::int AS partidas
       FROM sokkerpro.match_snapshots
       WHERE kickoff_at >= $1 AND kickoff_at < $2`,
      [startUtc, endUtc],
    );
    return { date: d, partidas: rows[0]?.partidas ?? 0 };
  } finally {
    await pool.end();
  }
}

console.log('=== Oracly diagnose-api ===');
console.log('Brasilia hoje:', date);
console.log('API base:', apiBase);
console.log('');

const apiResults = await Promise.all([
  probeApi('hoje', date),
  probeApi('ontem', prevDate),
  probeApi('amanha', (() => {
    const n = new Date(`${date}T12:00:00Z`);
    n.setUTCDate(n.getUTCDate() + 1);
    return n.toISOString().slice(0, 10);
  })()),
]);

console.log('--- API SokkerPRO ---');
for (const r of apiResults) console.log(JSON.stringify(r, null, 2));

console.log('\n--- Postgres (kickoff Brasília) ---');
try {
  for (const d of [prevDate, date]) {
    const r = await probePostgres(d);
    console.log(JSON.stringify(r));
  }
} catch (err) {
  console.log('Postgres ERRO:', err.message);
}
