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
        $active = $this->pdo->prepare('SELECT idempotency_key, status, last_error FROM ticket_delivery_jobs WHERE order_id = ? AND channel = ? AND communication_type = "tickets" ORDER BY id DESC LIMIT 1');
        $active->execute([$orderId, $channel]);
        $previous = $active->fetch();
        if ($previous && str_starts_with((string) ($previous['last_error'] ?? ''), 'requires_review:')) {
            throw new RuntimeException('La entrega anterior requiere conciliación antes de reenviar.', 409);
        }
        if ($previous && in_array($previous['status'], ['queued', 'retry', 'blocked', 'processing'], true)) {
            return ['idempotency_key' => $previous['idempotency_key'], 'status' => $previous['status']];
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
        if ($this->pdo->inTransaction()) throw new RuntimeException('Worker cannot run inside a transaction.');
        if ((int) $this->pdo->query("SELECT GET_LOCK('perigallo-ticket-delivery', 0)")->fetchColumn() !== 1) {
            return ['processed' => 0, 'sent' => 0, 'retried' => 0, 'blocked' => 0, 'failed' => 0];
        }
        try {
            // No worker holding this DB lock remains alive. Stale I/O has an unknown outcome.
            $this->pdo->exec('UPDATE ticket_delivery_jobs SET status = "failed", last_error = "requires_review:interrupted_processing", locked_at = NULL, locked_by = NULL, completed_at = NOW(), updated_at = NOW() WHERE status = "processing"');
            $this->pdo->exec('UPDATE ticket_delivery_jobs SET status = "failed", last_error = "retry_limit_reached", completed_at = NOW(), updated_at = NOW() WHERE status IN ("queued", "retry", "blocked") AND attempt_count >= max_attempts');
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
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('perigallo-ticket-delivery')");
        }
    }

    /** SELECTs only. Does not claim jobs, generate documents, mutate QR or call providers. */
    public function inspect(): array
    {
        $result = [
            'mode' => 'read_only',
            'queue' => $this->pdo->query('SELECT channel, status, COUNT(*) AS total FROM ticket_delivery_jobs GROUP BY channel, status')->fetchAll(),
            'due' => (int) $this->pdo->query('SELECT COUNT(*) FROM ticket_delivery_jobs WHERE status IN ("queued", "retry", "blocked") AND available_at <= NOW() AND attempt_count < max_attempts')->fetchColumn(),
            'interrupted' => (int) $this->pdo->query('SELECT COUNT(*) FROM ticket_delivery_jobs WHERE status = "processing"')->fetchColumn(),
            'emails' => $this->pdo->query('SELECT status, COUNT(*) AS total FROM email_deliveries GROUP BY status')->fetchAll(),
            'documents' => (int) $this->pdo->query('SELECT COUNT(*) FROM ticket_delivery_documents')->fetchColumn(),
            'mail_function' => function_exists('mail'), 'sendmail_path' => ini_get('sendmail_path'),
            'smtp_env_used' => false,
            'paid_orders_without_document' => (int) $this->pdo->query('SELECT COUNT(*) FROM ticket_orders o WHERE o.status = "paid" AND o.payment_status = "paid" AND NOT EXISTS (SELECT 1 FROM ticket_delivery_documents d WHERE d.order_id = o.id)')->fetchColumn(),
            'ticket_quantity_mismatches' => (int) $this->pdo->query('SELECT COUNT(*) FROM ticket_order_items i JOIN ticket_orders o ON o.id = i.order_id LEFT JOIN (SELECT order_item_id, COUNT(*) AS n FROM tickets GROUP BY order_item_id) t ON t.order_item_id = i.id WHERE o.status = "paid" AND COALESCE(t.n, 0) <> i.quantity')->fetchColumn(),
        ];
        return $result;
    }

    private function process(array $job): string
    {
        $stage = 'prepare';
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
            if (!$this->ticketsReady((int) $order['id'])) {
                $this->finish($job, 'failed', 'requires_review:ticket_quantity_or_qr_mismatch');
                return 'failed';
            }
            $document = (new TicketDocumentService())->ensureOrderDocument($this->pdo, (int) $order['id']);
            if (!str_starts_with($document['content'], '%PDF-') || !hash_equals($document['sha256'], hash('sha256', $document['content']))) {
                $this->finish($job, 'failed', 'requires_review:document_integrity');
                return 'failed';
            }
            $stage = 'transport';
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
                if ($status === 'pending') {
                    $this->finish($job, 'failed', 'requires_review:mail_outcome_unknown');
                    return 'failed';
                }
                $this->retryOrFail($job, 'mail_not_accepted');
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
                if ((int) $job['attempt_count'] >= (int) $job['max_attempts']) {
                    $this->finish($job, 'failed', 'retry_limit_reached:whatsapp_blocked');
                    return 'failed';
                }
                $this->finish($job, 'blocked', $result['error'] ?: 'La plantilla de WhatsApp no está aprobada.', 30);
                $this->refreshOrderDeliveryStatus((int) $order['id']);
                return 'blocked';
            }
            // A timeout or missing response may follow Meta accepting the message.
            $this->finish($job, 'failed', 'requires_review:whatsapp_outcome_unknown');
            $this->refreshOrderDeliveryStatus((int) $order['id']);
            return 'failed';
        } catch (\Throwable $error) {
            $diagnostic = TicketDeliveryDiagnostics::error($error, $stage, ['job_id' => (int) $job['id'], 'order_id' => (int) $job['order_id'], 'channel' => $job['channel'], 'attempt' => (int) $job['attempt_count']]);
            TicketDeliveryDiagnostics::log($diagnostic);
            if (str_starts_with($error->getMessage(), 'requires_review:')) {
                $this->finish($job, 'failed', $error->getMessage());
                return 'failed';
            }
            if ($stage === 'transport') {
                $this->finish($job, 'failed', 'requires_review:transport_outcome_unknown');
                return 'failed';
            }
            $this->retryOrFail($job, substr($diagnostic['error_type'] . ': ' . $diagnostic['message'], 0, 1000));
            return (int) $job['attempt_count'] >= (int) $job['max_attempts'] ? 'failed' : 'retried';
        }
    }

    private function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query('SELECT * FROM ticket_delivery_jobs WHERE status IN ("queued", "retry", "blocked") AND attempt_count < max_attempts AND available_at <= NOW() ORDER BY available_at ASC, id ASC LIMIT 1 FOR UPDATE');
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
        if ($error !== null) {
            TicketDeliveryDiagnostics::log([
                'event' => 'ticket_delivery_outcome', 'job_id' => (int) $job['id'],
                'order_id' => (int) $job['order_id'], 'channel' => $job['channel'],
                'attempt' => (int) $job['attempt_count'], 'status' => $status,
                'reason' => $error, 'next_attempt_at' => $availableAt, 'time' => date(DATE_ATOM),
            ]);
        }
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
        $order = $this->order($orderId);
        $defaultWhatsAppStatus = !empty($order['whatsapp_consent']) && !empty($order['whatsapp_phone_e164']) ? 'pending' : 'not_authorized';
        $whatsappStatus = (string) ($whatsapp->fetchColumn() ?: $defaultWhatsAppStatus);
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
        return ($order['status'] ?? '') === 'paid' && ($order['payment_status'] ?? '') === 'paid';
    }

    private function ticketsReady(int $orderId): bool
    {
        $statement = $this->pdo->prepare('SELECT i.quantity, COUNT(t.id) AS actual, SUM(CASE WHEN t.status IN ("issued", "used") AND t.qr_token_ciphertext IS NOT NULL AND t.qr_token_ciphertext <> "" THEN 1 ELSE 0 END) AS usable FROM ticket_order_items i LEFT JOIN tickets t ON t.order_item_id = i.id WHERE i.order_id = ? GROUP BY i.id, i.quantity');
        $statement->execute([$orderId]);
        $items = $statement->fetchAll();
        if (!$items) return false;
        foreach ($items as $item) {
            if ((int) $item['quantity'] < 1 || (int) $item['actual'] !== (int) $item['quantity'] || (int) $item['usable'] !== (int) $item['quantity']) return false;
        }
        return true;
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
