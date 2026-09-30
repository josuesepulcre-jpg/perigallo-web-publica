import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { extname, resolve } from 'node:path';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const port = Number(process.env.PORT || 4173);
const eventPath = '/experiencias/la-perigalla-01-ibicenca/';
const localEventFixture = resolve(root, 'scripts/fixtures/la-perigalla-01.local.json');
const types = { '.avif': 'image/avif', '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.woff2': 'font/woff2' };

function localFile(pathname) {
  let relative = pathname === eventPath || pathname === '/eventos/evento/' ? 'eventos/evento.html' : pathname.replace(/^\/+/, '');
  if (pathname.endsWith('/') && relative !== 'eventos/evento.html') relative += 'index.html';
  const file = resolve(root, relative || 'index.html');
  return file.startsWith(root) ? file : null;
}

createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host}`);
  if (url.pathname.startsWith('/api/')) {
    if (req.method !== 'GET') { res.writeHead(405); res.end('Preview read-only'); return; }
    try {
      if (url.pathname === '/api/events') {
        res.writeHead(200, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
        res.end(JSON.stringify({ ok: true, events: [{ id: 1, slug: 'la-perigalla-01-ibicenca', title: 'La Perigalla 01', starts_at: '2026-08-29 19:00:00', status: 'published' }] }));
        return;
      }
      if (url.pathname === '/api/events/la-perigalla-01-ibicenca') {
        res.writeHead(200, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
        res.end(await readFile(localEventFixture));
        return;
      }
      res.writeHead(404); res.end('API no disponible en la vista previa local.');
    } catch { res.writeHead(500); res.end('No se pudo cargar el contenido local de prueba.'); }
    return;
  }
  try {
    const file = localFile(url.pathname);
    if (!file || !(await stat(file)).isFile()) throw new Error('not found');
    res.writeHead(200, { 'content-type': types[extname(file).toLowerCase()] || 'application/octet-stream', 'cache-control': 'no-store' });
    res.end(await readFile(file));
  } catch { res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' }); res.end('No encontrado'); }
}).listen(port, '127.0.0.1', () => console.log(`Preview local: http://127.0.0.1:${port}${eventPath}`));
