import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import pino from 'pino';
import { getEnv } from '../config/env.js';

let _logger: pino.Logger | null = null;

export function getLogger(): pino.Logger {
  if (!_logger) {
    const env = getEnv();
    const isTest = process.env.NODE_ENV === 'test';

    if (isTest) {
      _logger = pino({ level: 'silent' });
      return _logger;
    }

    mkdirSync(env.LOG_PATH, { recursive: true });
    const logFile = join(env.LOG_PATH, 'oracly.log');

    _logger = pino({
      level: env.LOG_LEVEL,
      transport: {
        targets: [
          {
            target: 'pino-pretty',
            level: env.LOG_LEVEL,
            options: {
              colorize: true,
              translateTime: 'SYS:yyyy-mm-dd HH:MM:ss.l',
              ignore: 'pid,hostname',
            },
          },
          {
            target: 'pino/file',
            level: env.LOG_LEVEL,
            options: {
              destination: logFile,
              mkdir: true,
            },
          },
        ],
      },
    });
  }
  return _logger;
}

export function resetLogger(): void {
  _logger = null;
}
