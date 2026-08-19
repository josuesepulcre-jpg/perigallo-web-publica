<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use RuntimeException;

/** Persistent, channel-independent ticket delivery queue. */
final class TicketDeliveryQueue
{
    public function __construct(private PDO $pdo, private Mailer $mailer)
    {
    }

    public function enqueuePaidOrder(int $orderId): void
    {
        $order = $this->order($orderId);
        if (!$order || !$this->isPaid($order)) {
            return;
        }
        $this->enqueue($orderId, 'email', self::key($orderId, 'email'), 'payment_confirmation');
        if (!empty($order['whatsapp_consent']) && !empty($order['whatsapp_phone_e164'])) {
            $this->enqueue($orderId, 'whatsapp', self::key($orderId, 'whatsapp'), 'payment_confirmation');
        } else {
            $this->enqueue($orderId, 'whatsapp', self::key($orderId, 'whatsapp'), 'payment_confirmation', 'not_authorized');
        }
    }

    public function requeue(int $orderId, string $channel, string $requestedBy): array
    {
        if (!in_array($channel, ['email', 'whatsapp'], true)) {
            throw new RuntimeException('Canal de entrega no válido.');
        }
        $order = $this->order($orderId);
        if (!$order || !$this->isPaid($order)) {
            throw new RuntimeException('Solo se pueden reenviar entradas de pedidos pagados.', 409);
        }
        if ($channel === 'whatsapp' && (empty($order['whatsapp_consent']) || empty($order['whatsapp_phone_e164']))) {
            throw new RuntimeException('Este pedido no autorizó la recepción de entradas por WhatsApp.', 409);
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_delivery_jobs WHERE order_id = ? AND channel = ? AND communication_type = "tickets"');
        $count->execute([$orderId, $channel]);
        $key = self::key($orderId, $channel) . ':retry-' . ((int) $count->fetchColumn() + 1);
        $this->enqueue($orderId, $channel, $key, 'manual_resend', 'queued', $requestedBy);
        return ['idempotency_key' => $key, 'status' => 'queued'];
    }

    /** @return array{processed:int,sent:int,retried:int,blocked:int,failed:int} */
    public function processDue(int $limit = 20): array
    {
        $summary = ['processed' => 0, 'sent' => 0, 'retried' => 0, 'blocked' => 0, 'failed' => 0];
        for ($index = 0; $index < max(1, min(100, $limit)); $index++) {
            $job = $this->claimNext();
            if ($job === null) {
                break;
            }
            $summary['processed']++;
            $outcome = $this->process($job);
            $summary[$outcome]++;
        }
        return $summary;
    }

    private function process(array $job): string
    {
        try {
            $order = $this->order((int) $job['order_id']);
            if (!$order || !$this->isPaid($order)) {
                $this->finish($job, 'failed', 'El pedido no está pagado.');
                return 'failed';
            }
            $event = $this->eventForOrder((int) $order['id']);
            if (!$event) {
                $this->finish($job, 'failed', 'No se encontró el evento del pedido.');
                return 'failed';
            }
            $quantity = $this->quantity((int) $order['id']);
            $document = (new TicketDocumentService())->ensureOrderDocument($this->pdo, (int) $order['id']);
            if ($job['channel'] === 'email') {
                $status = $this->mailer->sendTicketDocumentEmail(
                    $this->pdo,
                    (int) $order['id'],
                    (string) $order['email'],
                    'Tus entradas para ' . (string) $event['title'],
                    $this->emailText($order, $event, $quantity),
                    $this->emailHtml($order, $event, $quantity),
                    $document,
                    (string) $job['idempotency_key']
                );
                if ($status === 'sent') {
                    $this->finish($job, 'sent');
                    $this->refreshOrderDeliveryStatus((int) $order['id']);
                    return 'sent';
                }
                $this->retryOrFail($job, 'No se pudo enviar el correo de entradas.');
                $this->refreshOrderDeliveryStatus((int) $order['id']);
                return (int) $job['attempt_count'] >= (int) $job['max_attempts'] ? 'failed' : 'retried';
            }
            $result = (new WhatsAppDeliveryService())->sendTicketDocument($this->pdo, $order, $event, $quantity, $document, (string) $job['idempotency_key']);
            if (in_array($result['status'], ['sent', 'delivered', 'read'], true)) {
                $this->finish($job, 'sent');
                $this->refreshOrderDeliveryStatus((int) $order['id']);
                return 'sent';
            }
            if ($result['status'] === 'not_authorized') {
                $this->finish($job, 'not_authorized');
                $this->refreshOrderDeliveryStatus((int) $order['id']);
                return 'blocked';
            }
            if ($result['status'] === 'blocked') {
                $this->finish($job, 'blocked', $result['error'] ?: 'La plantilla de WhatsApp no está aprobada.', 30);
                $this->refreshOrderDeliveryStatus((int) $order['id']);
                return 'blocked';
            }
            $this->retryOrFail($job, $result['error'] ?: 'No se pudo enviar WhatsApp.');
            $this->refreshOrderDeliveryStatus((int) $order['id']);
            return (int) $job['attempt_count'] >= (int) $job['max_attempts'] ? 'failed' : 'retried';
        } catch (\Throwable $error) {
            $this->retryOrFail($job, 'No se pudo preparar la entrega de entradas.');
            return (int) $job['attempt_count'] >= (int) $job['max_attempts'] ? 'failed' : 'retried';
        }
    }

    private function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query('SELECT * FROM ticket_delivery_jobs WHERE status IN ("queued", "retry", "blocked") AND available_at <= NOW() ORDER BY available_at ASC, id ASC LIMIT 1 FOR UPDATE');
            $job = $statement->fetch();
            if (!$job) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare('UPDATE ticket_delivery_jobs SET status = "processing", attempt_count = attempt_count + 1, locked_at = NOW(), locked_by = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([gethostname() ?: 'delivery-worker', $job['id']]);
            $job['attempt_count'] = (int) $job['attempt_count'] + 1;
            $job['status'] = 'processing';
            $this->pdo->commit();
            return $job;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function enqueue(int $orderId, string $channel, string $key, string $requestedBy, string $status = 'queued', ?string $actor = null): void
    {
        $statement = $this->pdo->prepare('INSERT INTO ticket_delivery_jobs (order_id, channel, communication_type, document_version, idempotency_key, status, available_at, requested_by, created_at, updated_at) VALUES (?, ?, "tickets", ?, ?, ?, NOW(), ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()');
        $statement->execute([$orderId, $channel, TicketDocumentService::DOCUMENT_VERSION, $key, $status, $actor ?: $requestedBy]);
    }

    private function finish(array $job, string $status, ?string $error = null, int $delayMinutes = 0): void
    {
        // La fecha se calcula aquí para no depender de parámetros dentro de
        // INTERVAL, que MariaDB no admite de forma uniforme con PDO nativo.
        $availableAt = $delayMinutes > 0 ? date('Y-m-d H:i:s', time() + $delayMinutes * 60) : null;
        $statement = $this->pdo->prepare('UPDATE ticket_delivery_jobs SET status = ?, last_error = ?, available_at = COALESCE(?, available_at), completed_at = IF(? IN ("sent", "failed", "not_authorized"), NOW(), NULL), locked_at = NULL, locked_by = NULL, updated_at = NOW() WHERE id = ?');
        $statement->execute([$status, $error, $availableAt, $status, $job['id']]);
    }

    private function retryOrFail(array $job, string $error): void
    {
        $attempt = (int) $job['attempt_count'];
        if ($attempt >= (int) $job['max_attempts']) {
            $this->finish($job, 'failed', $error);
            return;
        }
        $this->finish($job, 'retry', $error, min(360, 2 ** min(8, $attempt)));
    }

    private function refreshOrderDeliveryStatus(int $orderId): void
    {
        $email = $this->pdo->prepare('SELECT status FROM email_deliveries WHERE order_id = ? AND idempotency_key IS NOT NULL ORDER BY id DESC LIMIT 1');
        $email->execute([$orderId]);
        $emailStatus = (string) ($email->fetchColumn() ?: 'pending');
        $whatsapp = $this->pdo->prepare('SELECT status FROM ticket_delivery_logs WHERE order_id = ? AND channel = "whatsapp" AND idempotency_key IS NOT NULL ORDER BY id DESC LIMIT 1');
        $whatsapp->execute([$orderId]);
        $whatsappStatus = (string) ($whatsapp->fetchColumn() ?: 'pending');
        $status = $emailStatus === 'sent' && in_array($whatsappStatus, ['sent', 'delivered', 'read', 'not_authorized'], true) ? 'sent' : ($emailStatus === 'sent' ? 'partially_sent' : ($emailStatus === 'failed' && $whatsappStatus === 'failed' ? 'failed' : 'generated'));
        $update = $this->pdo->prepare('UPDATE ticket_orders SET delivery_status = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$status, $orderId]);
    }

    private function order(int $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE id = ? LIMIT 1');
        $statement->execute([$orderId]);
        return $statement->fetch() ?: null;
    }

    private function eventForOrder(int $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT e.* FROM events e JOIN ticket_order_items oi ON oi.event_id = e.id WHERE oi.order_id = ? LIMIT 1');
        $statement->execute([$orderId]);
        return $statement->fetch() ?: null;
    }

    private function quantity(int $orderId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM ticket_order_items WHERE order_id = ?');
        $statement->execute([$orderId]);
        return (int) $statement->fetchColumn();
    }

    private function isPaid(array $order): bool
    {
        return ($order['status'] ?? '') === 'paid' || ($order['payment_status'] ?? '') === 'paid';
    }

    private static function key(int $orderId, string $channel): string
    {
        return 'order_' . $orderId . ':tickets:' . $channel . ':' . TicketDocumentService::DOCUMENT_VERSION;
    }

    private function emailText(array $order, array $event, int $quantity): string
    {
        return 'Hola ' . (string) $order['name'] . ",\n\nTu compra para " . (string) $event['title'] . " está confirmada. Adjuntamos el PDF con " . $quantity . " entrada(s) y sus códigos QR de acceso. Guárdalo y preséntalo al llegar.\n\nEquipo Perigallo\n";
    }

    private function emailHtml(array $order, array $event, int $quantity): string
    {
        $name = htmlspecialchars((string) $order['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string) $event['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html lang="es"><body style="margin:0;padding:32px;background:#eef0ed;font-family:Arial,sans-serif;color:#173236"><main style="max-width:600px;margin:auto;padding:36px;background:#173236;color:#f5f0e5"><p style="letter-spacing:2px;color:#d2b596;font-size:11px">PERIGALLO · ENTRADAS DIGITALES</p><h1 style="font-family:Georgia,serif;font-weight:normal">Tu compra está confirmada</h1><p>Hola ' . $name . ',</p><p>Adjuntamos el PDF con tus ' . $quantity . ' entrada(s) para <strong>' . $title . '</strong>. Cada entrada incluye su código QR de acceso.</p><p>Guarda este documento y preséntalo al llegar.</p></main></body></html>';
    }
}
