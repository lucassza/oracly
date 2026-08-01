import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import type { NormalizedMatch } from '../types/schemas.js';
import { PostgresMatchStore } from './postgres-store.js';

interface StoredOutput {
  matches?: NormalizedMatch[];
}

export async function importOutputDirectory(store: PostgresMatchStore, outputPath: string): Promise<number> {
  const files = await readdir(outputPath);
  const outputFiles = files.filter((file) => file.endsWith('.json'));
  const outputs = await Promise.all(outputFiles.map(async (file) => {
    const content = await readFile(join(outputPath, file), 'utf-8');
    return JSON.parse(content) as StoredOutput;
  }));

  const matches = outputs.flatMap((output) => output.matches ?? []);

  // One INSERT with 20 params/row can hit Postgres's 65535 bind-parameter
  // limit once the output directory holds several thousand matches.
  const BATCH_SIZE = 1000;
  for (let i = 0; i < matches.length; i += BATCH_SIZE) {
    await store.saveMatches(matches.slice(i, i + BATCH_SIZE));
  }

  return matches.length;
}
