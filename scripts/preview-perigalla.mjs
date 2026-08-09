import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { extname, resolve } from 'node:path';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const port = Number(process.env.PORT || 4173);
const eventPath = '/experiencias/la-perigalla-01-ibicenca/';
const types = { '.avif': 'image/avif', '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.woff2': 'font/woff2' };

function localFile(pathname) {
  const relative = pathname === eventPath || pathname === '/eventos/evento/' ? 'eventos/evento.html' : pathname.replace(/^\/+/, '');
  const file = resolve(root, relative || 'index.html');
  return file.startsWith(root) ? file : null;
}

createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host}`);
  if (url.pathname.startsWith('/api/')) {
    if (req.method !== 'GET') { res.writeHead(405); res.end('Preview read-only'); return; }
    try {
      if (url.pathname === '/api/events/la-perigalla-01-ibicenca') {
        const listing = await fetch('https://perigallo.com/api/events', { headers: { accept: 'application/json' } });
        const listingData = await listing.json();
        const event = (listingData.events || []).find((item) => item.slug === 'la-perigalla-01-ibicenca');
        if (!event) throw new Error('event not found');
        // La API pública desplegada todavía no expone la ficha individual. El
        // adaptador solo completa la forma de lectura que espera la plantilla;
        // no escribe ni simula ninguna transacción de pago.
        event.ticket_types = [{ id: 'preview-general', name: 'Entrada general', price_cents: event.price_from_cents, reference_price_cents: event.reference_price_from_cents, final_price_cents: event.price_from_cents, show_reference_price: true, promotional_label: 'Precio especial de lanzamiento', available: 300, max_per_order: 10, status: 'on_sale', effective_status: 'on_sale' }];
        res.writeHead(200, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
        res.end(JSON.stringify({ ok: true, event }));
        return;
      }
      const upstream = await fetch(`https://perigallo.com${url.pathname}${url.search}`, { headers: { accept: 'application/json' } });
      res.writeHead(upstream.status, { 'content-type': upstream.headers.get('content-type') || 'application/json', 'cache-control': 'no-store' });
      res.end(Buffer.from(await upstream.arrayBuffer()));
    } catch { res.writeHead(502); res.end('No se pudo obtener la información pública del evento.'); }
    return;
  }
  if (url.pathname.startsWith('/assets/uploads/')) {
    try {
      const upstream = await fetch(`https://perigallo.com${url.pathname}`);
      res.writeHead(upstream.status, { 'content-type': upstream.headers.get('content-type') || 'application/octet-stream', 'cache-control': 'no-store' });
      res.end(Buffer.from(await upstream.arrayBuffer()));
    } catch { res.writeHead(502); res.end('No se pudo obtener el recurso público.'); }
    return;
  }
  try {
    const file = localFile(url.pathname);
    if (!file || !(await stat(file)).isFile()) throw new Error('not found');
    res.writeHead(200, { 'content-type': types[extname(file).toLowerCase()] || 'application/octet-stream', 'cache-control': 'no-store' });
    res.end(await readFile(file));
  } catch { res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' }); res.end('No encontrado'); }
}).listen(port, '127.0.0.1', () => console.log(`Preview local: http://127.0.0.1:${port}${eventPath}`));
