<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use RuntimeException;

/** Only the anonymous event listing. Never cache orders, availability or payments. */
final class PublicEventCache
{
    public function __construct(private string $directory, private int $ttl = 15)
    {
    }

    public static function forApplication(): self
    {
        // Isolate deployments and databases, without writing credentials to disk.
        $scope = hash('sha256', dirname(__DIR__, 2) . '|' . env_value('DB_HOST') . '|' . env_value('DB_DATABASE'));
        return new self(sys_get_temp_dir() . '/perigallo-events-' . $scope,
            max(0, min(60, (int) env_value('PUBLIC_EVENTS_CACHE_SECONDS', '15'))));
    }

    public function remember(callable $load): array
    {
        if ($this->ttl === 0) return $load();
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) return $load();
        $path = $this->directory . '/events.json';
        $cached = $this->read($path);
        if ($cached !== null && time() - $cached['created'] < $this->ttl) return $cached['events'];
        $lock = @fopen($this->directory . '/refresh.lock', 'c');
        if ($lock === false) return $load();
        try {
            // One refresh at a time. Other visitors may use the previous public
            // list for at most another 15s; checkout always reads live inventory.
            $deadline = microtime(true) + 1;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if ($cached !== null && time() - $cached['created'] < $this->ttl + 15) return $cached['events'];
                if (microtime(true) >= $deadline) throw new RuntimeException('El listado se está actualizando. Inténtalo de nuevo en unos segundos.', 503);
                usleep(20000);
            }
            // A previous request may have filled the cache while we waited.
            $cached = $this->read($path);
            if ($cached !== null && time() - $cached['created'] < $this->ttl) return $cached['events'];
            $events = $load();
            $json = json_encode(['created' => time(), 'events' => $events], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $temporary = @tempnam($this->directory, 'events-');
            if ($temporary !== false) {
                try {
                    if ($json !== false && @file_put_contents($temporary, $json) !== false) @rename($temporary, $path);
                } finally {
                    if (is_file($temporary)) @unlink($temporary);
                }
            }
            return $events;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(string $path): ?array
    {
        $raw = @file_get_contents($path);
        $entry = $raw === false ? null : json_decode($raw, true);
        return is_array($entry) && is_int($entry['created'] ?? null) && is_array($entry['events'] ?? null) ? $entry : null;
    }
}
