<?php
declare(strict_types=1);
require __DIR__ . '/../api/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\PublicEventCache;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$directory = sys_get_temp_dir() . '/perigallo-cache-test-' . bin2hex(random_bytes(8));
$calls = 0;
$load = static function () use (&$calls): array { $calls++; return [['slug' => 'fixture', 'title' => 'Test']]; };
try {
    $cache = new PublicEventCache($directory, 15);
    check($cache->remember($load)[0]['slug'] === 'fixture' && $cache->status() === 'MISS', 'First load must be a cache miss');
    for ($i = 0; $i < 100; $i++) check($cache->remember($load)[0]['slug'] === 'fixture', 'Listing changed');
    check($calls === 1 && $cache->status() === 'HIT', 'Repeated reads must perform one load');
    file_put_contents($directory . '/events.json', json_encode(['created' => time() - 16, 'events' => []]));
    check(count($cache->remember($load)) === 1 && $calls === 2, 'Expired listing must refresh');
    file_put_contents($directory . '/events.json', '{broken');
    $cache->remember($load);
    check($calls === 3, 'Corrupt cache must refresh');
    (new PublicEventCache($directory, 0))->remember($load);
    (new PublicEventCache($directory, 0))->remember($load);
    check($calls === 5, 'Disabling must bypass cache');
    unlink($directory . '/events.json');
    try {
        $cache->remember(static function (): array { throw new RuntimeException('simulated database failure'); });
        throw new LogicException('Expected exception');
    } catch (RuntimeException $e) { check($e->getMessage() === 'simulated database failure', 'Do not hide load failure'); }
    check(!is_file($directory . '/events.json'), 'Never cache errors');
    check(count($cache->remember($load)) === 1 && $calls === 6, 'Lock released after failure');
    // Concurrent refresh: serve the recent list instead of opening another DB connection.
    file_put_contents($directory . '/events.json', json_encode(['created' => time() - 16, 'events' => [['slug' => 'recent']]]));
    $lock = fopen($directory . '/refresh.lock', 'c');
    flock($lock, LOCK_EX);
    $probe = fopen($directory . '/refresh.lock', 'c');
    $hasRealLocks = !flock($probe, LOCK_EX | LOCK_NB);
    if (!$hasRealLocks) flock($probe, LOCK_UN);
    fclose($probe);
    try {
        if ($hasRealLocks) {
            check($cache->remember($load)[0]['slug'] === 'recent' && $calls === 6, 'Avoid simultaneous refreshes');
            file_put_contents($directory . '/events.json', json_encode(['created' => time() - 31, 'events' => [['slug' => 'too-old']]]));
            try {
                $cache->remember($load);
                throw new LogicException('Expected busy response');
            } catch (RuntimeException $error) {
                check($error->getCode() === 503 && $calls === 6, 'Bound wait and never serve an obsolete list');
            }
        } else {
            echo "Concurrent lock checks skipped: this runtime does not enforce flock between handles. Run with native PHP before deployment.\n";
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    foreach ([1040, 1203] as $driver) {
        $error = new PDOException('synthetic');
        $error->errorInfo = ['HY000', $driver, 'synthetic'];
        check(Database::isConnectionLimit($error), 'Detect connection limit');
    }
    $other = new PDOException('synthetic');
    $other->errorInfo = ['HY000', 1062, 'synthetic'];
    check(!Database::isConnectionLimit($other), 'Do not confuse duplicate key with overload');
    check(!Database::isConnectionLimit(new RuntimeException('1040')), 'Only classify driver exceptions');
    echo "Cache lifecycle, error recovery and database error classification passed (100 reads: 1 load).\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}
