#!/usr/bin/env python3
"""Private, credential-free preflight ledger. All amounts are integer EUR micro-units."""
import datetime, http.server, json, os, socket, socketserver, sqlite3
from zoneinfo import ZoneInfo

LIMIT = 100_000  # EUR 0.10 TOTAL, never per application
SOCKET = '/run/perigallo-ai-spend/guard.sock'
DATABASE = '/var/lib/perigallo-ai-spend/ledger.sqlite'
MODELS = {'gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14', 'gpt-5-mini', 'gpt-5-mini-2025-08-07', 'gpt-5.4-mini', 'gpt-5.4-mini-2026-03-17'}

def quote(value, today=None):
    today = today or datetime.datetime.now(ZoneInfo('Europe/Madrid')).date()
    # Reviewed upper rates: USD 1/M input and USD 5/M output. EUR 2/USD
    # includes a conservative FX/tax/fee margin. Never discount caching/reasoning.
    # Expired or unknown tariffs fail closed, rather than silently undercharging.
    if today >= datetime.date(2026, 12, 1):
        raise ValueError('Revisar tarifas antes de reactivar IA.')
    if set(value) != {'app', 'model', 'input_tokens_bound', 'output_tokens_max'}:
        raise ValueError('Invalid reservation')
    if value['app'] not in {'suite', 'lab', 'instagram', 'assistant'} or value['model'] not in MODELS:
        raise ValueError('Modelo sin tarifa de seguridad: no se enviará la petición.')
    a, b = value['input_tokens_bound'], value['output_tokens_max']
    if type(a) is not int or type(b) is not int or not 0 < a <= 2_000_000 or not 0 < b <= 128_000:
        raise ValueError('Invalid token ceiling')
    return 2 * (a + 5 * b)

def initialize(database):
    with sqlite3.connect(database) as db:
        db.execute('CREATE TABLE IF NOT EXISTS reservations(day TEXT NOT NULL, app TEXT NOT NULL, eur_micros INTEGER NOT NULL CHECK(eur_micros > 0))')

def reserve(database, value, now=None):
    now = now or datetime.datetime.now(ZoneInfo('Europe/Madrid'))
    now = now.astimezone(ZoneInfo('Europe/Madrid'))
    cost, day = quote(value, now.date()), now.date().isoformat()
    # A committed reservation is never refunded: timeouts, retries, crashes and
    # ambiguous provider responses cannot manufacture extra spendable credit.
    with sqlite3.connect(database, timeout=3) as db:
        db.execute('BEGIN IMMEDIATE')
        used = db.execute('SELECT COALESCE(SUM(eur_micros),0) FROM reservations WHERE day=?', (day,)).fetchone()[0]
        if used + cost > LIMIT:
            raise ValueError('La petición no cabe en el presupuesto total de IA de 0,10 € de hoy. Puedes continuar manualmente; se renueva a medianoche de Madrid.')
        db.execute('INSERT INTO reservations VALUES(?,?,?)', (day, value['app'], cost))
    return {'allowed': True, 'day': day, 'reserved_eur_micros': cost, 'remaining_eur_micros': LIMIT-used-cost}

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass  # Never log requests or private application content.

    def do_POST(self):
        try:
            if self.path != '/reserve' or self.headers.get('Transfer-Encoding'):
                raise ValueError('Invalid request')
            size = int(self.headers.get('Content-Length', '0'))
            if not 0 < size <= 2048:
                raise ValueError('Invalid request size')
            result = reserve(self.server.database, json.loads(self.rfile.read(size)))
            status = 200
        except (ValueError, TypeError, KeyError, sqlite3.Error):
            result = {'allowed': False, 'message': 'Presupuesto de IA de 0,10 € agotado, insuficiente o no verificable. Continúa manualmente; se renueva a medianoche de Madrid.'}
            status = 429
        body = json.dumps(result).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

if __name__ == '__main__':
    os.umask(0o077)
    initialize(DATABASE)
    if os.path.exists(SOCKET):
        os.unlink(SOCKET)
    with Server(SOCKET, Handler) as server:
        server.database = DATABASE
        os.chmod(SOCKET, 0o666)  # Local callers can only consume quota, never add it.
        server.serve_forever()
