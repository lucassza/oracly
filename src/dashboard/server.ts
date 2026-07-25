import { createServer } from 'node:http';
import { readFile, readdir } from 'node:fs/promises';
import { join, extname } from 'node:path';
import { getEnv } from '../config/env.js';

const PORT = 3000;
const OUTPUT_DIR = getEnv().OUTPUT_PATH;

const MIME_TYPES: Record<string, string> = {
  '.html': 'text/html',
  '.css': 'text/css',
  '.js': 'application/javascript',
  '.json': 'application/json',
};

async function serveFile(res: any, filePath: string) {
  try {
    const content = await readFile(filePath);
    const ext = extname(filePath);
    res.writeHead(200, { 'Content-Type': MIME_TYPES[ext] || 'text/plain' });
    res.end(content);
  } catch {
    res.writeHead(404);
    res.end('Not found');
  }
}

async function main() {
  const server = createServer(async (req, res) => {
    const url = new URL(req.url || '/', `http://localhost:${PORT}`);

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
    if (url.pathname === '/' || url.pathname === '/index.html') {
      await serveFile(res, join(import.meta.dirname, 'index.html'));
      return;
    }

    await serveFile(res, join(import.meta.dirname, url.pathname));
  });

  server.listen(PORT, () => {
    console.log(`Dashboard: http://localhost:${PORT}`);
  });
}

main();
