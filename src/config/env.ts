import { config } from 'dotenv';
import { z } from 'zod';

config();

const envSchema = z.object({
  SOKKERPRO_BASE_URL: z.string().url().default('https://sokkerpro.com'),
  SOKKERPRO_API_BASE_URL: z.string().url().default('https://m2.sokkerpro.com'),
  SOKKERPRO_EMAIL: z.string().optional(),
  SOKKERPRO_PASSWORD: z.string().optional(),
  SOKKERPRO_STORAGE_STATE_PATH: z.string().default('storage/sessions/sokkerpro.json'),

  PLAYWRIGHT_HEADLESS: z
    .string()
    .transform((s) => s !== 'false')
    .default('true'),
  PLAYWRIGHT_TIMEOUT: z.coerce.number().positive().default(30000),
  PLAYWRIGHT_LOCALE: z.string().default('pt-BR'),
  PLAYWRIGHT_TIMEZONE: z.string().default('America/Sao_Paulo'),

  SCRAPER_DATE: z.string().optional(),
  SCRAPER_CONCURRENCY: z.coerce.number().positive().max(10).default(2),
  SCRAPER_DELAY_MIN_MS: z.coerce.number().nonnegative().default(1000),
  SCRAPER_DELAY_MAX_MS: z.coerce.number().positive().default(3000),
  SCRAPER_MAX_RETRIES: z.coerce.number().nonnegative().default(3),
  SCRAPER_SAVE_RAW_DATA: z
    .string()
    .transform((s) => s === 'true')
    .default('false'),
  DAILY_UPDATE_ENABLED: z
    .string()
    .transform((s) => s === 'true')
    .default('false'),
  DAILY_UPDATE_HOUR_BRASILIA: z.coerce.number().int().min(0).max(23).default(6),
  DAILY_UPDATE_MINUTE_BRASILIA: z.coerce.number().int().min(0).max(59).default(0),
  DAILY_UPDATE_DAYS: z.coerce.number().int().min(1).max(7).default(1),
  REALTIME_UPDATE_ENABLED: z
    .string()
    .transform((s) => s === 'true')
    .default('false'),
  REALTIME_UPDATE_INTERVAL_SECONDS: z.coerce.number().int().min(15).max(3600).default(60),
  REALTIME_LOOKBACK_HOURS: z.coerce.number().int().min(0).max(24).default(3),
  REALTIME_LOOKAHEAD_HOURS: z.coerce.number().int().min(0).max(24).default(6),
  REALTIME_DETAIL_CONCURRENCY: z.coerce.number().int().min(1).max(10).default(3),

  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace']).default('info'),
  OUTPUT_PATH: z.string().default('storage/output'),
  DASHBOARD_PORT: z.coerce.number().int().min(1).max(65535).default(3000),
  SCREENSHOT_PATH: z.string().default('storage/screenshots'),

  POSTGRES_HOST: z.string(),
  POSTGRES_PORT: z.coerce.number().int().min(1).max(65535).default(5432),
  POSTGRES_DB: z.string(),
  POSTGRES_USER: z.string(),
  POSTGRES_PASSWORD: z.string(),
  POSTGRES_SCHEMA: z.string().default('public'),
});

export type Env = z.infer<typeof envSchema>;

let _env: Env | null = null;

export function getEnv(): Env {
  if (!_env) {
    const result = envSchema.safeParse(process.env);
    if (!result.success) {
      console.error('Invalid environment variables:', result.error.flatten().fieldErrors);
      process.exit(1);
    }
    _env = result.data;
  }
  return _env;
}

export function resetEnv(): void {
  _env = null;
}
