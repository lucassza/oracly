import { setTimeout as sleep } from 'node:timers/promises';

const BRASILIA_TIMEZONE = 'America/Sao_Paulo';

export function wait(ms: number): Promise<void> {
  return sleep(ms).then(() => undefined);
}

export function getBrasiliaDate(now = new Date()): string {
  return now.toLocaleDateString('en-CA', { timeZone: BRASILIA_TIMEZONE });
}

export function getBrasiliaTimeParts(now = new Date()): { hour: number; minute: number; second: number } {
  const formatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: BRASILIA_TIMEZONE,
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  });

  const parts = formatter.formatToParts(now);
  const read = (type: 'hour' | 'minute' | 'second') =>
    Number(parts.find((part) => part.type === type)?.value ?? '0');

  return {
    hour: read('hour'),
    minute: read('minute'),
    second: read('second'),
  };
}

export function getMsUntilNextBrasiliaTime(
  hour: number,
  minute: number,
  now = new Date(),
): number {
  const currentParts = getBrasiliaTimeParts(now);
  const currentMinutes = currentParts.hour * 60 + currentParts.minute;
  const targetMinutes = hour * 60 + minute;
  const remainingMinutes =
    targetMinutes > currentMinutes ||
    (targetMinutes === currentMinutes && currentParts.second === 0)
      ? targetMinutes - currentMinutes
      : 24 * 60 - currentMinutes + targetMinutes;

  const waitMs = remainingMinutes * 60 * 1000 - currentParts.second * 1000;
  return waitMs >= 0 ? waitMs : 24 * 60 * 60 * 1000;
}

export function addDays(date: string, offset: number): string {
  const value = new Date(`${date}T12:00:00.000Z`);
  value.setUTCDate(value.getUTCDate() + offset);
  return value.toISOString().slice(0, 10);
}
