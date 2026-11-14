<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;

/** Reads the minimal atomic snapshot written by Suite's private read-only exporter. */
final class SuiteCalendar
{
    public function __construct(private string $path) {}

    public static function forApplication(): self
    {
        return new self(dirname(__DIR__, 3) . '/private/suite-admin-calendar/events.json');
    }

    public function read(?int $now = null): array
    {
        $now ??= time();
        $unavailable = ['events' => [], 'sync' => ['status' => 'unavailable', 'generated_at' => null]];
        if (!is_file($this->path) || !is_readable($this->path) || filesize($this->path) > 5 * 1024 * 1024) return $unavailable;
        $raw = @file_get_contents($this->path);
        $snapshot = $raw === false ? null : json_decode($raw, true);
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1 || ($snapshot['complete'] ?? null) !== true || !is_array($snapshot['events'] ?? null)) return $unavailable;
        $generated = strtotime((string) ($snapshot['generated_at'] ?? ''));
        if ($generated === false || $generated > $now + 300) return $unavailable;
        $events = []; $ids = [];
        foreach ($snapshot['events'] as $event) {
            if (!is_array($event) || ($event['source'] ?? '') !== 'suite' || !is_string($event['id'] ?? null) || !str_starts_with($event['id'], 'suite:') || isset($ids[$event['id']])) return $unavailable;
            if (!in_array($event['status'] ?? '', ['suite_pending', 'suite_confirmed'], true)) return $unavailable;
            $starts = (string) ($event['starts_at'] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $starts);
            if (!$date || $date->format('Y-m-d H:i:s') !== $starts) return $unavailable;
            $path = (string) ($event['suite_path'] ?? '');
            if (!preg_match('#^/(?:calendario|(?:bodas|celebraciones|leads|sistema/diseno-menus)/[a-zA-Z0-9-]+)$#D', $path)) return $unavailable;
            $people = $event['people'] ?? null;
            if ($people !== null && (!is_int($people) || $people < 0 || $people > 100000)) return $unavailable;
            if (!is_string($event['title'] ?? null) || strlen($event['title']) > 1000 || !is_string($event['client_name'] ?? null) || strlen($event['client_name']) > 1000) return $unavailable;
            $ids[$event['id']] = true;
            $events[] = array_intersect_key($event, array_flip(['id','source','title','client_name','starts_at','time_defined','status','people','suite_path','location']));
        }
        return ['events' => $events, 'sync' => ['status' => $now - $generated > 180 ? 'stale' : 'current', 'generated_at' => gmdate('c', $generated)]];
    }
}
