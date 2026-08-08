<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cola fiscal asíncrona. Está deliberadamente separada del callback de Redsys:
 * un pago aprobado no espera a Holded y el navegador nunca puede encolarlo.
 */
final class HoldedSyncService
{
    private HoldedFiscalPolicy $policy;

    public function __construct(
        private PDO $pdo,
        private HoldedClient $client,
        ?HoldedFiscalPolicy $policy = null
    ) {
        $this->policy = $policy ?? new HoldedFiscalPolicy();
    }

    public function queuePaidProductionOrder(int $orderId, string $paymentEnvironment, bool $isTest): void
    {
        if ($isTest || $paymentEnvironment !== 'production') {
            return;
        }
        $this->pdo->prepare(
            'UPDATE ticket_orders
             SET holded_status = IF(holded_status = "synced", holded_status, "pending"),
                 holded_next_attempt_at = IF(holded_status = "synced", holded_next_attempt_at, NOW()),
                 holded_last_error = IF(holded_status = "synced", holded_last_error, NULL),
                 updated_at = NOW()
             WHERE id = ? AND is_test = 0 AND environment = "production" AND status = "paid"'
        )->execute([$orderId]);
        $this->log($orderId, 'queue', 'planned', 0, null, null, null, null);
    }

    public function retry(int $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT id, status, is_test, environment FROM ticket_orders WHERE id = ? LIMIT 1');
        $statement->execute([$orderId]);
        $order = $statement->fetch();
        if (!$order || $order['status'] !== 'paid' || !empty($order['is_test']) || $order['environment'] !== 'production') {
            throw new RuntimeException('Solo se pueden reintentar pedidos reales cobrados en producción.');
        }
        $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "pending", holded_next_attempt_at = NOW(), holded_last_error = NULL, updated_at = NOW() WHERE id = ? AND holded_status <> "synced"')->execute([$orderId]);
        $this->log($orderId, 'manual_retry', 'planned', 0, null, null, null, null);
        return $this->orderStatus($orderId);
    }

    public function due(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->pdo->query(
            'SELECT id FROM ticket_orders
             WHERE is_test = 0 AND environment = "production" AND status = "paid"
               AND holded_status IN ("pending", "error")
               AND (holded_next_attempt_at IS NULL OR holded_next_attempt_at <= NOW())
             ORDER BY COALESCE(holded_next_attempt_at, created_at) ASC
             LIMIT ' . $limit
        )->fetchAll();
        $result = ['processed' => 0, 'synced' => 0, 'pending' => 0, 'requires_review' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            $result['processed']++;
            $status = $this->syncOrder((int) $row['id']);
            if (isset($result[$status])) $result[$status]++;
        }
        $this->markStaleProcessingForReview();
        return $result;
    }

    public function syncOrder(int $orderId): string
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->loadOrder($orderId, true);
            if (!$order || $order['status'] !== 'paid' || !empty($order['is_test']) || $order['environment'] !== 'production') {
                $this->pdo->rollBack();
                return 'pending';
            }
            if ($order['holded_status'] === 'synced') {
                $this->pdo->rollBack();
                return 'synced';
            }
            // A stale processing record is never retried blindly: the previous worker
            // could have created a document immediately before failing locally.
            if ($order['holded_status'] === 'processing') {
                $this->pdo->rollBack();
                return 'requires_review';
            }
            $documentType = $this->policy->documentType($order);
            if (!$this->client->enabled() || $this->client->dryRun()) {
                $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "pending", holded_document_type = ?, holded_last_error = "Preparado; integración desactivada o en dry-run.", holded_next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 DAY), updated_at = NOW() WHERE id = ?')->execute([$documentType, $orderId]);
                $this->pdo->commit();
                $this->log($orderId, 'dry_run', 'planned', (int) $order['holded_sync_attempts'], null, null, 'holded_not_active', null);
                return 'pending';
            }
            $issues = array_merge($this->client->configIssues(), $this->policy->configurationIssues($documentType));
            if ($issues) {
                $this->setReviewLocked($orderId, 'Configuración pendiente: ' . implode(', ', array_unique($issues)));
                $this->pdo->commit();
                $this->log($orderId, 'configuration', 'requires_review', (int) $order['holded_sync_attempts'], null, null, 'holded_not_configured', null);
                return 'requires_review';
            }
            $attempt = (int) $order['holded_sync_attempts'] + 1;
            $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "processing", holded_document_type = ?, holded_sync_attempts = ?, holded_last_attempt_at = NOW(), holded_next_attempt_at = NULL, holded_last_error = NULL, updated_at = NOW() WHERE id = ?')->execute([$documentType, $attempt, $orderId]);
            $this->pdo->commit();
            $this->log($orderId, 'issue_' . $documentType, 'started', $attempt, null, null, null, null);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        try {
            $order = $this->loadOrder($orderId, false);
            if (!$order) throw new RuntimeException('Pedido no encontrado.');
            $documentType = (string) $order['holded_document_type'];
            $document = $this->issueDocument($order, $documentType);
            $documentId = $this->externalId($document);
            if ($documentId === '') throw new HoldedException('Holded no devolvió el identificador del documento.', null, null, false, 'holded_invalid_response');
            if ($documentType === 'invoice' && filter_var(env_value('HOLDED_AUTO_APPROVE', 'false'), FILTER_VALIDATE_BOOLEAN)) {
                $this->client->approveInvoice($documentId);
            }
            $payment = $this->paymentPayload($order);
            $paymentResponse = $documentType === 'invoice'
                ? $this->client->recordInvoicePayment($documentId, $payment)
                : $this->client->recordSalesReceiptPayment($documentId, $payment);
            $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "synced", holded_document_id = ?, holded_document_number = ?, holded_payment_id = ?, holded_synced_at = NOW(), holded_last_error = NULL, updated_at = NOW() WHERE id = ?')->execute([$documentId, $this->documentNumber($document), $this->externalId($paymentResponse), $orderId]);
            $this->log($orderId, 'payment_' . $documentType, 'succeeded', (int) $order['holded_sync_attempts'], null, $documentId, null, null);
            return 'synced';
        } catch (HoldedException $error) {
            return $this->handleFailure($orderId, $error);
        } catch (\Throwable $error) {
            return $this->handleFailure($orderId, new HoldedException('Error interno al sincronizar con Holded.', null, null, true, 'holded_internal'));
        }
    }

    public function health(): array
    {
        $counts = $this->pdo->query('SELECT holded_status, COUNT(*) AS total FROM ticket_orders WHERE is_test = 0 GROUP BY holded_status')->fetchAll();
        return ['configuration' => $this->client->health(), 'orders' => $counts, 'legal_review_required' => true];
    }

    private function issueDocument(array $order, string $documentType): array
    {
        $items = $this->pdo->prepare('SELECT ticket_type_name, quantity, unit_price_cents FROM ticket_order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([(int) $order['id']]);
        $payloadItems = [];
        foreach ($items->fetchAll() as $item) {
            $payloadItems[] = [
                'name' => clean_string((string) $item['ticket_type_name'], 255),
                'type' => 'service',
                'units' => (int) $item['quantity'],
                'price' => $this->policy->amount((int) $item['unit_price_cents']),
                'taxes' => [(string) env_value('HOLDED_DEFAULT_TAX_ID')],
                'account_id' => (string) env_value('HOLDED_SALES_ACCOUNT_ID'),
            ];
        }
        $common = [
            'date' => (new DateTimeImmutable((string) $order['paid_at']))->format('Y-m-d'),
            'items' => $payloadItems,
            'payment_method_id' => (string) env_value('HOLDED_PAYMENT_METHOD_ID'),
            'notes' => 'Pedido Perigallo ' . (string) $order['redsys_order'],
        ];
        if ($documentType === 'invoice') {
            $contactId = $this->contactId($order);
            $common['contact_id'] = $contactId;
            $common['number_line_id'] = (string) env_value('HOLDED_INVOICE_SERIES_ID');
            return $this->client->createInvoice($common);
        }
        $common['number_line_id'] = (string) env_value('HOLDED_SALES_RECEIPT_SERIES_ID');
        return $this->client->createSalesReceipt($common);
    }

    private function contactId(array $order): string
    {
        $taxHash = $this->hash((string) $order['billing_tax_id']);
        $emailHash = $this->hash((string) ($order['billing_email'] ?: $order['email']));
        $statement = $this->pdo->prepare('SELECT holded_contact_id FROM holded_contacts WHERE tax_id_hash = ? OR email_hash = ? ORDER BY tax_id_hash = ? DESC LIMIT 1');
        $statement->execute([$taxHash, $emailHash, $taxHash]);
        $existing = $statement->fetchColumn();
        if ($existing) return (string) $existing;
        $response = $this->client->createContact([
            'name' => (string) $order['billing_name'],
            'vat_number' => (string) $order['billing_tax_id'],
            'email' => (string) ($order['billing_email'] ?: $order['email']),
            'bill_address' => (string) $order['billing_address'],
            'bill_postal_code' => (string) $order['billing_postal_code'],
            'bill_city' => (string) $order['billing_city'],
            'bill_province' => (string) $order['billing_province'],
            'bill_country' => (string) $order['billing_country'],
        ]);
        $id = $this->externalId($response);
        if ($id === '') throw new HoldedException('Holded no devolvió el contacto.', null, null, false, 'holded_invalid_response');
        $this->pdo->prepare('INSERT INTO holded_contacts (holded_contact_id, tax_id_hash, email_hash, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE holded_contact_id = VALUES(holded_contact_id), updated_at = NOW()')->execute([$id, $taxHash, $emailHash]);
        return $id;
    }

    private function paymentPayload(array $order): array
    {
        return [
            'amount' => $this->policy->amount((int) $order['total_cents']),
            'treasury_id' => (string) env_value('HOLDED_TREASURY_ID'),
            'date' => (new DateTimeImmutable((string) $order['paid_at']))->format('Y-m-d'),
            'description' => 'Pago Redsys ' . (string) $order['redsys_order'],
        ];
    }

    private function handleFailure(int $orderId, HoldedException $error): string
    {
        $order = $this->loadOrder($orderId, false);
        $attempt = (int) ($order['holded_sync_attempts'] ?? 1);
        $safe = $this->safeMessage($error);
        $requiresReview = !$error->retryable || in_array($error->httpStatus, [401, 403], true) || $attempt >= 5;
        if ($requiresReview) {
            $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "requires_review", holded_last_error = ?, holded_next_attempt_at = NULL, updated_at = NOW() WHERE id = ?')->execute([$safe, $orderId]);
            $this->log($orderId, 'sync', 'requires_review', $attempt, $error->httpStatus, null, $error->safeCode, $safe);
            return 'requires_review';
        }
        $delay = $error->retryAfterSeconds ?: [60, 300, 900, 3600, 21600][$attempt - 1];
        $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "error", holded_last_error = ?, holded_next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW() WHERE id = ?')->execute([$safe, $delay, $orderId]);
        $this->log($orderId, 'sync', 'retry_scheduled', $attempt, $error->httpStatus, null, $error->safeCode, $safe);
        return 'errors';
    }

    private function markStaleProcessingForReview(): void
    {
        $this->pdo->query('UPDATE ticket_orders SET holded_status = "requires_review", holded_last_error = "Sincronización interrumpida; revisar antes de reintentar.", updated_at = NOW() WHERE holded_status = "processing" AND holded_last_attempt_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    }

    private function setReviewLocked(int $orderId, string $message): void
    {
        $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "requires_review", holded_last_error = ?, holded_next_attempt_at = NULL, updated_at = NOW() WHERE id = ?')->execute([$message, $orderId]);
    }

    private function loadOrder(int $orderId, bool $forUpdate): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute([$orderId]);
        return $statement->fetch() ?: null;
    }

    private function orderStatus(int $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT id, holded_status, holded_document_type, holded_last_error, holded_next_attempt_at FROM ticket_orders WHERE id = ?');
        $statement->execute([$orderId]);
        return $statement->fetch() ?: [];
    }

    private function log(int $orderId, string $operation, string $status, int $attempt, ?int $httpStatus, ?string $externalId, ?string $errorCode, ?string $message): void
    {
        $this->pdo->prepare('INSERT INTO holded_sync_logs (order_id, operation, status, attempt, http_status, external_id, error_code, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$orderId, $operation, $status, $attempt, $httpStatus, $externalId, $errorCode, $message]);
    }

    private function externalId(array $response): string
    {
        foreach (['id', '_id', 'document_id', 'payment_id'] as $key) if (!empty($response[$key])) return (string) $response[$key];
        if (isset($response['data']) && is_array($response['data'])) return $this->externalId($response['data']);
        return '';
    }

    private function documentNumber(array $response): ?string
    {
        foreach (['number', 'document_number', 'doc_number'] as $key) if (!empty($response[$key])) return clean_string((string) $response[$key], 100);
        return null;
    }

    private function hash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }

    private function safeMessage(HoldedException $error): string
    {
        return mb_substr($error->safeCode . ($error->httpStatus ? ' (' . $error->httpStatus . ')' : ''), 0, 500);
    }
}
