<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use RuntimeException;

/** Canonical contact directory. Historical imports deliberately create no marketing consent. */
final class Contacts
{
    public function __construct(private PDO $pdo) {}

    public function syncPaidOrder(int $orderId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT o.*, (SELECT oi.event_id FROM ticket_order_items oi WHERE oi.order_id = o.id ORDER BY oi.id LIMIT 1) AS event_id
             FROM ticket_orders o WHERE o.id = ? AND (o.status = "paid" OR o.payment_status = "paid") LIMIT 1'
        );
        $statement->execute([$orderId]);
        $order = $statement->fetch();
        if (!$order) return;
        $contactId = $this->upsert([
            'first_name' => (string) $order['first_name'], 'last_name' => (string) $order['last_name'], 'name' => (string) $order['name'],
            'email' => (string) $order['email'], 'phone' => (string) (($order['whatsapp_phone_e164'] ?? '') ?: $order['phone']), 'source' => 'compra_entrada',
        ]);
        $this->pdo->prepare('INSERT IGNORE INTO contact_order_links (contact_id, order_id, created_at) VALUES (?, ?, NOW())')->execute([$contactId, $orderId]);
        foreach ([
            'email' => ['field' => 'marketing_email_consent', 'version' => 'marketing_email_consent_version'],
            'whatsapp' => ['field' => 'marketing_whatsapp_consent', 'version' => 'marketing_whatsapp_consent_version'],
        ] as $channel => $config) {
            if (!empty($order[$config['field']])) {
                $this->recordConsent($contactId, $channel, 'granted', 'checkout', (string) ($order[$config['version']] ?: 'marketing_' . $channel . '_v1'), (int) ($order['event_id'] ?: 0), $orderId, null);
            }
        }
    }

    public function syncLead(int $leadId): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM lead_requests WHERE id = ? LIMIT 1');
        $statement->execute([$leadId]);
        $lead = $statement->fetch();
        if (!$lead) return;
        $name = trim((string) $lead['name']);
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $contactId = $this->upsert(['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'name' => $name, 'email' => (string) ($lead['email'] ?? ''), 'phone' => (string) ($lead['phone'] ?? ''), 'source' => 'formulario_web']);
        $this->pdo->prepare('INSERT IGNORE INTO contact_lead_links (contact_id, lead_request_id, created_at) VALUES (?, ?, NOW())')->execute([$contactId, $leadId]);
    }

    public function backfill(): array
    {
        $orders = $this->pdo->query('SELECT id FROM ticket_orders WHERE status = "paid" OR payment_status = "paid"')->fetchAll(PDO::FETCH_COLUMN);
        $leads = $this->pdo->query('SELECT id FROM lead_requests WHERE email IS NOT NULL OR phone IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orders as $id) $this->syncPaidOrder((int) $id);
        foreach ($leads as $id) $this->syncLead((int) $id);
        return ['orders' => count($orders), 'leads' => count($leads)];
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') { $where[] = '(c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        $filter = (string) ($filters['consent'] ?? 'all');
        $email = '(SELECT status FROM contact_consent_events ce WHERE ce.contact_id = c.id AND ce.channel = "email" ORDER BY ce.id DESC LIMIT 1)';
        $whatsapp = '(SELECT status FROM contact_consent_events ce WHERE ce.contact_id = c.id AND ce.channel = "whatsapp" ORDER BY ce.id DESC LIMIT 1)';
        if ($filter === 'email') $where[] = $email . ' = "granted"';
        if ($filter === 'whatsapp') $where[] = $whatsapp . ' = "granted"';
        if ($filter === 'marketing') $where[] = '(' . $email . ' = "granted" OR ' . $whatsapp . ' = "granted")';
        if ($filter === 'none') $where[] = '(' . $email . ' IS NULL AND ' . $whatsapp . ' IS NULL)';
        if ($filter === 'revoked') $where[] = '(' . $email . ' = "revoked" OR ' . $whatsapp . ' = "revoked")';
        $sql = 'SELECT c.*, ' . $email . ' AS email_marketing, ' . $whatsapp . ' AS whatsapp_marketing,
                (SELECT MAX(o.paid_at) FROM contact_order_links col JOIN ticket_orders o ON o.id = col.order_id WHERE col.contact_id = c.id) AS last_purchase_at,
                (SELECT COUNT(*) FROM contact_order_links col WHERE col.contact_id = c.id) AS order_count
                FROM contacts c' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY COALESCE(last_purchase_at, c.updated_at) DESC LIMIT 250';
        $statement = $this->pdo->prepare($sql); $statement->execute($params);
        return $statement->fetchAll();
    }

    public function detail(int $contactId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM contacts WHERE id = ? LIMIT 1'); $statement->execute([$contactId]);
        $contact = $statement->fetch(); if (!$contact) throw new RuntimeException('Contacto no encontrado.', 404);
        $orders = $this->pdo->prepare('SELECT o.id, o.redsys_order, o.test_reference, o.total_cents, o.paid_at, (SELECT GROUP_CONCAT(DISTINCT e.title ORDER BY e.title SEPARATOR " · ") FROM ticket_order_items oi JOIN events e ON e.id = oi.event_id WHERE oi.order_id = o.id) AS event_title, (SELECT SUM(oi.quantity) FROM ticket_order_items oi WHERE oi.order_id = o.id) AS ticket_quantity FROM contact_order_links col JOIN ticket_orders o ON o.id = col.order_id WHERE col.contact_id = ? ORDER BY o.paid_at DESC'); $orders->execute([$contactId]);
        $leads = $this->pdo->prepare('SELECT lr.public_reference, lr.event_type, lr.event_date, lr.created_at FROM contact_lead_links cl JOIN lead_requests lr ON lr.id = cl.lead_request_id WHERE cl.contact_id = ? ORDER BY lr.created_at DESC'); $leads->execute([$contactId]);
        $consents = $this->pdo->prepare('SELECT ce.*, e.title AS event_title FROM contact_consent_events ce LEFT JOIN events e ON e.id = ce.event_id WHERE ce.contact_id = ? ORDER BY ce.id DESC'); $consents->execute([$contactId]);
        return ['contact' => $contact, 'orders' => $orders->fetchAll(), 'leads' => $leads->fetchAll(), 'consents' => $consents->fetchAll()];
    }

    public function revoke(int $contactId, string $channel, string $operator): void
    {
        if (!in_array($channel, ['email', 'whatsapp'], true)) throw new RuntimeException('Canal no válido.');
        $this->recordConsent($contactId, $channel, 'revoked', 'administracion', null, 0, 0, $operator);
    }

    private function upsert(array $data): int
    {
        $email = $this->email((string) $data['email']); $phone = $this->phone((string) $data['phone']);
        if ($email === '' && $phone === '') throw new RuntimeException('El contacto necesita email o teléfono.');
        $find = $this->pdo->prepare('SELECT id, email_normalized, phone_normalized FROM contacts WHERE email_normalized = ? OR phone_normalized = ? ORDER BY id'); $find->execute([$email ?: null, $phone ?: null]); $matches = $find->fetchAll();
        $emailMatch = 0; $phoneMatch = 0;
        foreach ($matches as $match) { if ($email !== '' && $match['email_normalized'] === $email) $emailMatch = (int) $match['id']; if ($phone !== '' && $match['phone_normalized'] === $phone) $phoneMatch = (int) $match['id']; }
        $id = $emailMatch ?: $phoneMatch;
        $first = clean_string((string) $data['first_name'], 120); $last = clean_string((string) $data['last_name'], 160); $name = clean_string((string) $data['name'], 255) ?: trim($first . ' ' . $last); $source = clean_string((string) $data['source'], 80);
        if ($id) { $safeEmail = ($emailMatch && $emailMatch !== $id) ? '' : $email; $safePhone = ($phoneMatch && $phoneMatch !== $id) ? '' : $phone; $this->pdo->prepare('UPDATE contacts SET first_name = COALESCE(NULLIF(?, ""), first_name), last_name = COALESCE(NULLIF(?, ""), last_name), full_name = COALESCE(NULLIF(?, ""), full_name), email = COALESCE(NULLIF(?, ""), email), email_normalized = COALESCE(NULLIF(?, ""), email_normalized), phone = COALESCE(NULLIF(?, ""), phone), phone_normalized = COALESCE(NULLIF(?, ""), phone_normalized), last_source = ?, updated_at = NOW() WHERE id = ?')->execute([$first, $last, $name, $safeEmail, $safeEmail, $safePhone ? (string) $data['phone'] : '', $safePhone, $source, $id]); return $id; }
        $this->pdo->prepare('INSERT INTO contacts (first_name,last_name,full_name,email,email_normalized,phone,phone_normalized,initial_source,last_source,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([$first ?: null, $last ?: null, $name, $email ?: null, $email ?: null, (string) $data['phone'] ?: null, $phone ?: null, $source, $source]); return (int) $this->pdo->lastInsertId();
    }

    private function recordConsent(int $contactId, string $channel, string $status, string $source, ?string $version, int $eventId, int $orderId, ?string $operator): void
    {
        $exists = $this->pdo->prepare('SELECT id FROM contact_consent_events WHERE contact_id = ? AND channel = ? AND status = ? AND order_id ' . ($orderId ? '= ?' : 'IS NULL') . ' LIMIT 1'); $exists->execute($orderId ? [$contactId, $channel, $status, $orderId] : [$contactId, $channel, $status]); if ($exists->fetchColumn()) return;
        $this->pdo->prepare('INSERT INTO contact_consent_events (contact_id,channel,status,source,consent_text_version,event_id,order_id,recorded_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')->execute([$contactId, $channel, $status, $source, $version, $eventId ?: null, $orderId ?: null, $operator]);
    }
    private function email(string $value): string { $value = mb_strtolower(trim($value)); return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : ''; }
    private function phone(string $value): string { $value = preg_replace('/\D+/', '', $value) ?: ''; if (str_starts_with($value, '00')) $value = substr($value, 2); if (strlen($value) === 9 && preg_match('/^[6-9]/', $value)) $value = '34' . $value; return strlen($value) >= 8 ? '+' . $value : ''; }
}
