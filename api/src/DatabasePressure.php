<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;

use PDO;

final class DatabasePressure
{
    public static function sample(PDO $pdo): array
    {
        $limit = $pdo->query("SHOW VARIABLES LIKE 'max_connections'")->fetch(PDO::FETCH_ASSOC);
        $values = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Connection_errors_max_connections','Uptime')")->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['limit' => (int) $limit['Value'], 'connected' => (int) $values['Threads_connected'], 'running' => (int) $values['Threads_running'], 'rejected' => (int) $values['Connection_errors_max_connections'], 'uptime' => (int) $values['Uptime']];
    }

    public static function assess(array $sample, ?array $previous): array
    {
        // Counter resets/restarts must not be counted as new failures.
        $delta = $previous && $sample['uptime'] >= $previous['uptime'] && $sample['rejected'] >= $previous['rejected']
            ? $sample['rejected'] - $previous['rejected'] : 0;
        $warning = max(1, (int) floor($sample['limit'] * 0.8));
        $level = $delta > 0 || $sample['connected'] >= $sample['limit'] ? 'critical' : ($sample['connected'] >= $warning ? 'warning' : 'ok');
        return ['level' => $level, 'warning_at' => $warning, 'new_rejections' => $delta] + $sample;
    }
}
