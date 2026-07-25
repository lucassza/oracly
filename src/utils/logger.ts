import pino from 'pino';
import { getEnv } from '../config/env.js';

let _logger: pino.Logger | null = null;

export function getLogger(): pino.Logger {
  if (!_logger) {
    const env = getEnv();
    const isTest = process.env.NODE_ENV === 'test';
    _logger = pino({
      level: isTest ? 'silent' : env.LOG_LEVEL,
      transport: isTest
        ? undefined
        : {
            target: 'pino-pretty',
            options: {
              colorize: true,
              translateTime: 'SYS:yyyy-mm-dd HH:MM:ss.l',
              ignore: 'pid,hostname',
            },
          },
    });
  }
  return _logger;
}

export function resetLogger(): void {
  _logger = null;
}
