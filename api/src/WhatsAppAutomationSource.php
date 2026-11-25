<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class WhatsAppAutomationSource
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ids(DateTimeImmutable $since, string $after): array
    {
        $local = $since->setTimezone($this->applicationTimezone())->format('Y-m-d H:i:s');
        $query = $this->pdo->prepare(
            "SELECT o.id FROM ticket_orders o
            WHERE o.id > ? AND o.updated_at >= ? AND o.is_test = 0 AND o.environment = 'production'
            AND (o.paid_at IS NOT NULL OR o.order_status IN ('confirmed','cancelled'))
            AND EXISTS (SELECT 1 FROM ticket_order_items i JOIN events e ON e.id=i.event_id
                WHERE i.order_id=o.id AND e.is_test=0
                  AND e.starts_at>=CURRENT_DATE - INTERVAL 1 DAY)
            ORDER BY o.id LIMIT 50"
        );
        $query->execute([$after === '' ? '0' : $after, $local]);
        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function record(string $id): ?array
    {
        $query = $this->pdo->prepare(
            "SELECT DISTINCT e.id event_id,o.name,o.phone,o.whatsapp_phone_e164,o.whatsapp_consent,o.whatsapp_consent_version,o.status,
                o.order_status,o.payment_status,o.paid_at,o.created_at,e.title,e.location,e.address,e.starts_at,e.timezone,e.status event_status
            FROM ticket_orders o JOIN ticket_order_items i ON i.order_id=o.id JOIN events e ON e.id=i.event_id
            WHERE o.id=? AND o.is_test=0 AND o.environment='production' AND e.is_test=0
            LIMIT 2"
        );
        $query->execute([$id]);
        $records = $query->fetchAll();
        if (count($records) !== 1) {
            return null;
        }
        $row = $records[0];
        $phone = (string) ($row['whatsapp_phone_e164'] ?: $row['phone']);
        $date = new DateTimeImmutable($row['paid_at'] ?: $row['created_at'], $this->applicationTimezone());
        $eventZone = $row['timezone'] ?: 'Europe/Madrid';
        if (!in_array($eventZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return null;
        }

        return [
            'active' => ($row['status'] === 'paid' || $row['order_status'] === 'confirmed')
                && !in_array($row['status'], ['cancelled', 'refunded', 'expired', 'denied'], true)
                && !in_array($row['order_status'], ['cancelled', 'expired'], true)
                && in_array($row['event_status'], ['published', 'sold_out'], true),
            'phone' => $phone,
            'name' => (string) ($row['name'] ?: 'cliente'),
            'title' => (string) $row['title'],
            'location' => implode(' · ', array_unique(array_filter([$row['location'], $row['address']]))),
            'localDate' => substr($row['starts_at'], 0, 10),
            'localTime' => substr($row['starts_at'], 11, 5),
            'zone' => $eventZone,
            'eventAt' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'consent' => (bool) $row['whatsapp_consent'] && !empty($row['whatsapp_phone_e164']),
            'reminderConsent' => (bool) $row['whatsapp_consent'] && !empty($row['whatsapp_phone_e164'])
                && $row['whatsapp_consent_version'] === 'v2_operational',
        ];
    }

    private function applicationTimezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }
}
