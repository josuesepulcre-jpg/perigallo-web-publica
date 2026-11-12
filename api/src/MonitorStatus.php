<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

/** Public liveness signal only. No DB access, credentials or detailed telemetry. */
final class MonitorStatus
{
    public static function healthy(?string $raw, int $now): bool
    {
        if ($raw === null || strlen($raw)>1024) return false;
        $state=json_decode($raw,true);
        return is_array($state) && ($state['version'] ?? null)===1
            && ($state['ok'] ?? null)===true
            && is_int($state['checked_at'] ?? null)
            && $state['checked_at'] <= $now+30
            && $state['checked_at'] >= $now-180;
    }
}
