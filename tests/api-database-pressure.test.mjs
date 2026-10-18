import { spawnSync } from 'node:child_process';
import { strict as assert } from 'node:assert';
import { resolve } from 'node:path';

// Use PHP_BIN=/path/to/php (or php-wasm-cli) to run against PHP 8.4.
const php = process.env.PHP_BIN || 'php';
const root = resolve(import.meta.dirname, '..');
const stub = `namespace Perigallo\\Ticketing {
 final class Database {
  public static int $calls = 0;
  public static function pdo(): \\PDO {
   self::$calls++;
   $error = new \\PDOException('Synthetic connection limit');
   $error->errorInfo = ['HY000', 1040, 'synthetic'];
   throw $error;
  }
  public static function isConnectionLimit(\\Throwable $e): bool {
   return $e instanceof \\PDOException && ($e->errorInfo[1] ?? null) === 1040;
  }
 }
}`;
const cases = [
  ['GET', '/api/payment-methods', 200, 0],
  ['GET', '/api/unknown-route', 404, 0],
  ['POST', '/api/analytics/events', 200, 0, { HTTP_USER_AGENT: 'ExampleBot' }],
  ['POST', '/api/analytics/events', 413, 0, { CONTENT_LENGTH: '12001' }],
  ['POST', '/api/formulario', 413, 0, { CONTENT_LENGTH: '150001' }],
  ['GET', '/api/internal/experiences', 401, 0],
  ['GET', '/api/admin/summary', 401, 0],
  ['GET', '/api/whatsapp/webhook', 403, 0],
  ['GET', '/api/events', 200, 0, {}, true],
  ['GET', '/api/orders/test-fixture-token', 503, 1],
  ['POST', '/api/redsys/notification', 503, 1],
];
for (const [method, uri, status, calls, server = {}, cached = false] of cases) {
  const setup = Object.entries({ REQUEST_METHOD: method, REQUEST_URI: uri, ...server })
    .map(([k, v]) => `$_SERVER[${JSON.stringify(k)}] = ${JSON.stringify(v)};`).join('\n');
  const fill = cached ? `require ${JSON.stringify(root + '/api/src/PublicEventCache.php')};
    (new \\Perigallo\\Ticketing\\PublicEventCache(sys_get_temp_dir() . '/perigallo-events-' . hash('sha256', ${JSON.stringify(root)} . '|fixture.test|isolated-pressure-test-${process.pid}')))->remember(static fn() => [['slug'=>'fixture']]);` : '';
  const code = stub + `namespace {
    putenv('DB_DATABASE=isolated-pressure-test-${process.pid}');
    putenv('DB_HOST=fixture.test');
    if (!is_dir(sys_get_temp_dir())) mkdir(sys_get_temp_dir(), 0700, true);
    ini_set('session.save_path', sys_get_temp_dir());
    putenv('PUBLIC_EVENTS_CACHE_SECONDS=15');
    ${setup}
    ${fill}
    register_shutdown_function(static function() {
      echo "\\n__RESULT__" . json_encode(['status'=>http_response_code() ?: 200,'calls'=>\\Perigallo\\Ticketing\\Database::$calls]);
    });
    require ${JSON.stringify(root + '/api/index.php')};
  }`;
  const result = spawnSync(php, ['-r', code], { encoding: 'utf8', cwd: root });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const [body, diagnostic] = result.stdout.split('\n__RESULT__');
  assert.ok(diagnostic, result.stdout);
  const actual = JSON.parse(diagnostic);
  assert.equal(actual.status, status, method + ' ' + uri + ': ' + body);
  assert.equal(actual.calls, calls, method + ' ' + uri + ': unexpected database connection');
  assert.equal(JSON.parse(body).ok, status < 400);
}
console.log(`${cases.length} isolated API scenarios passed: no unnecessary connections, cached listing and 503 on overload (including payment callback).`);
