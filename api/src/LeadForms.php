<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class LeadForms
{
    private const STATUSES = ['new', 'contacted', 'follow_up', 'proposal_sent', 'closed', 'discarded'];

    public function __construct(private PDO $pdo, private Mailer $mailer)
    {
    }

    public function publicSettings(): array
    {
        $settings = $this->settings();
        return [
            'enabled' => (bool) $settings['enabled'],
            'title' => $settings['title'],
            'subtitle' => $settings['subtitle'],
            'confirmation_message' => $settings['confirmation_message'],
        ];
    }

    public function submit(array $data, string $ip): array
    {
        $settings = $this->settings();
        if (!(int) $settings['enabled']) {
            throw new RuntimeException('El formulario está temporalmente en pausa. Puedes escribirnos a hola@perigallo.com.', 409);
        }
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return ['reference' => 'PGF-PROCESADA', 'email_status' => 'pending'];
        }

        $name = clean_string((string) ($data['name'] ?? ''), 190);
        $partnerName = clean_string((string) ($data['partner_name'] ?? ''), 190);
        $email = mb_strtolower(clean_string((string) ($data['email'] ?? ''), 190));
        $phone = clean_string((string) ($data['phone'] ?? ''), 60);
        if ($name === '') {
            throw new InvalidArgumentException('Indica al menos un nombre para continuar.');
        }
        if ($email === '' && $phone === '') {
            throw new InvalidArgumentException('Indica un correo electrónico o un teléfono para poder responderte.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Revisa el correo electrónico antes de enviar la solicitud.');
        }
        if (empty($data['privacy_accepted'])) {
            throw new InvalidArgumentException('Acepta la política de privacidad para enviar la solicitud.');
        }
        if (!is_array($data['answers'] ?? null)) {
            throw new InvalidArgumentException('No se han recibido los datos de la solicitud.');
        }

        $ipHash = hash('sha256', $ip);
        $rate = $this->pdo->prepare('SELECT COUNT(*) FROM lead_requests WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $rate->execute([$ipHash]);
        if ((int) $rate->fetchColumn() >= 4) {
            throw new RuntimeException('Hemos recibido varias solicitudes desde esta conexión. Espera unos minutos antes de volver a intentarlo.', 429);
        }

        $answers = $this->normaliseAnswers($data['answers']);
        $eventDate = clean_string((string) ($data['event_date'] ?? ''), 32);
        $guestCount = clean_string((string) ($data['guest_count'] ?? ''), 80);
        $eventType = clean_string((string) ($data['event_type'] ?? ''), 190) ?: 'Boda o celebración';
        $reference = 'PGF-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $source = clean_string((string) ($data['source'] ?? 'web-formulario'), 80) ?: 'web-formulario';

        $insert = $this->pdo->prepare(
            'INSERT INTO lead_requests
             (public_reference, source, name, partner_name, email, phone, event_type, event_date, guest_count, answers_json, status, privacy_accepted, privacy_accepted_at, privacy_version, email_status, ip_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new", 1, NOW(), ?, "pending", ?, NOW(), NOW())'
        );
        $insert->execute([$reference, $source, $name, $partnerName ?: null, $email ?: null, $phone ?: null, $eventType, $eventDate ?: null, $guestCount ?: null, json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), clean_string((string) ($data['privacy_version'] ?? 'web-form-v1'), 64), $ipHash]);
        $id = (int) $this->pdo->lastInsertId();

        $notification = $this->mailer->sendLeadNotification(
            (string) $settings['recipient_email'],
            'Nueva solicitud Perigallo — ' . $name . ' — ' . $eventType,
            $this->emailBody($reference, $name, $partnerName, $email, $phone, $eventType, $eventDate, $guestCount, $answers, $id)
        );
        $this->pdo->prepare('UPDATE lead_requests SET email_status = ?, email_error = ?, email_sent_at = IF(? = "sent", NOW(), NULL), updated_at = NOW() WHERE id = ?')
            ->execute([$notification['sent'] ? 'sent' : 'failed', $notification['error'], $notification['sent'] ? 'sent' : 'failed', $id]);

        return ['reference' => $reference, 'email_status' => $notification['sent'] ? 'sent' : 'failed', 'confirmation_message' => $settings['confirmation_message']];
    }

    public function adminSettings(): array
    {
        return $this->settings();
    }

    public function saveSettings(array $data): array
    {
        $current = $this->settings();
        $next = [
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'title' => clean_string((string) ($data['title'] ?? $current['title']), 190) ?: $current['title'],
            'subtitle' => clean_string((string) ($data['subtitle'] ?? $current['subtitle']), 500) ?: $current['subtitle'],
            'recipient_email' => mb_strtolower(clean_string((string) ($data['recipient_email'] ?? $current['recipient_email']), 190)),
            'confirmation_message' => clean_string((string) ($data['confirmation_message'] ?? $current['confirmation_message']), 500) ?: $current['confirmation_message'],
        ];
        if (!filter_var($next['recipient_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Indica un email de destino válido.');
        }
        $this->pdo->prepare('UPDATE lead_form_settings SET enabled = ?, title = ?, subtitle = ?, recipient_email = ?, confirmation_message = ?, updated_at = NOW() WHERE id = 1')
            ->execute([$next['enabled'], $next['title'], $next['subtitle'], $next['recipient_email'], $next['confirmation_message']]);
        return $this->settings();
    }

    public function adminRequests(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? '') !== '' && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        foreach (['event_type', 'event_date'] as $field) {
            if (trim((string) ($filters[$field] ?? '')) !== '') {
                $where[] = $field . ' LIKE ?';
                $params[] = '%' . clean_string((string) $filters[$field], 190) . '%';
            }
        }
        if (trim((string) ($filters['created_date'] ?? '')) !== '') {
            $where[] = 'DATE(created_at) = ?';
            $params[] = clean_string((string) $filters['created_date'], 10);
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(name LIKE ? OR partner_name LIKE ? OR email LIKE ? OR phone LIKE ? OR public_reference LIKE ?)';
            $term = '%' . clean_string((string) $filters['q'], 190) . '%';
            array_push($params, $term, $term, $term, $term, $term);
        }
        $sql = 'SELECT id, public_reference, name, partner_name, email, phone, event_type, event_date, guest_count, status, email_status, created_at, updated_at FROM lead_requests'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 250';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function adminRequest(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM lead_requests WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $request = $statement->fetch();
        if (!$request) {
            throw new InvalidArgumentException('Solicitud no encontrada.');
        }
        $request['answers'] = json_decode((string) $request['answers_json'], true) ?: [];
        unset($request['answers_json'], $request['ip_hash']);
        return $request;
    }

    public function updateStatus(int $id, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Estado de solicitud no válido.');
        }
        $this->pdo->prepare('UPDATE lead_requests SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $id]);
        return $this->adminRequest($id);
    }

    private function settings(): array
    {
        $row = $this->pdo->query('SELECT * FROM lead_form_settings WHERE id = 1')->fetch();
        if (!$row) {
            throw new RuntimeException('La configuración del formulario no está disponible. Aplica la migración pendiente.');
        }
        return $row;
    }

    private function normaliseAnswers(array $answers): array
    {
        $normalised = $this->normaliseAnswerValue($answers);
        $json = json_encode($normalised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 120000) {
            throw new InvalidArgumentException('La solicitud es demasiado extensa. Reduce el contenido e inténtalo de nuevo.');
        }
        return $normalised;
    }

    private function normaliseAnswerValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            throw new InvalidArgumentException('La estructura de la solicitud no es válida.');
        }
        if (is_array($value)) {
            $normalised = [];
            foreach ($value as $key => $item) {
                $normalised[clean_string((string) $key, 120)] = $this->normaliseAnswerValue($item, $depth + 1);
            }
            return $normalised;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return clean_string((string) $value, 5000);
    }

    private function emailBody(string $reference, string $name, string $partnerName, string $email, string $phone, string $eventType, string $eventDate, string $guestCount, array $answers, int $id): string
    {
        $lines = [
            'Nueva solicitud de Perigallo',
            'Referencia: ' . $reference,
            'Nombre: ' . $name . ($partnerName ? ' y ' . $partnerName : ''),
            'Email: ' . ($email ?: '—'),
            'Teléfono: ' . ($phone ?: '—'),
            'Tipo de evento: ' . $eventType,
            'Fecha prevista: ' . ($eventDate ?: '—'),
            'Invitados: ' . ($guestCount ?: '—'),
            '',
            'Ver solicitud: ' . app_base_url() . '/admin/formulario/?request=' . $id,
            '',
            'Respuestas completas:',
            json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
        return implode("\n", $lines);
    }
}
