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
    private Mailer $mailer;

    public function __construct(
        private PDO $pdo,
        private HoldedClient $client,
        ?HoldedFiscalPolicy $policy = null,
        ?Mailer $mailer = null
    ) {
        $this->policy = $policy ?? new HoldedFiscalPolicy();
        $this->mailer = $mailer ?? new Mailer();
    }

    /**
     * Encola exclusivamente según el pedido persistido. El entorno de Redsys
     * ("real"/"test") no es el entorno interno del pedido ("production"/"sandbox")
     * y no debe decidir nunca si una venta se contabiliza.
     */
    public function queuePaidProductionOrder(int $orderId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ticket_orders
             SET holded_status = IF(holded_status = "synced", holded_status, "pending"),
                 holded_next_attempt_at = IF(holded_status = "synced", holded_next_attempt_at, NOW()),
                 holded_last_error = IF(holded_status = "synced", holded_last_error, NULL),
                 updated_at = NOW()
             WHERE id = ? AND is_test = 0 AND environment = "production"
               AND (status = "paid" OR payment_status = "paid")'
        );
        $statement->execute([$orderId]);
        if ($statement->rowCount() > 0) {
            $this->log($orderId, 'queue', 'planned', 0, null, null, null, null);
        }
    }

    /**
     * Reintenta de forma segura. Un processing/requires_review sin ID externo
     * puede haber creado ya un documento remoto, por lo que exige confirmación
     * explícita de que se ha revisado Holded antes de volver a emitirlo.
     */
    public function retry(int $orderId, bool $confirmNoExternalDocument = false): array
    {
        $statement = $this->pdo->prepare('SELECT id, status, is_test, environment, holded_status, holded_document_id, holded_payment_id, holded_last_error FROM ticket_orders WHERE id = ? LIMIT 1');
        $statement->execute([$orderId]);
        $order = $statement->fetch();
        if (!$order || !$this->isPaidOrder($order) || !empty($order['is_test']) || $order['environment'] !== 'production') {
            throw new RuntimeException('Solo se pueden reintentar pedidos reales cobrados en producción.');
        }
        if ($order['holded_status'] === 'synced') {
            return $this->orderStatus($orderId);
        }
        $ambiguousPayment = empty($order['holded_payment_id'])
            && preg_match('/^holded_(network|http \(5[0-9]{2}\))/', (string) ($order['holded_last_error'] ?? ''));
        $needsExternalReview = in_array($order['holded_status'], ['processing', 'requires_review'], true)
            && (empty($order['holded_document_id']) || $ambiguousPayment);
        if ($needsExternalReview && !$confirmNoExternalDocument) {
            throw new RuntimeException('Antes de reintentar este pedido revisa Holded por la referencia Redsys y confirma que no existe un documento externo.');
        }
        $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "pending", holded_next_attempt_at = NOW(), holded_last_error = NULL, updated_at = NOW() WHERE id = ? AND holded_status <> "synced"')->execute([$orderId]);
        $this->log($orderId, 'manual_retry', 'planned', 0, null, null, $needsExternalReview ? 'external_write_reviewed' : null, null);
        return $this->orderStatus($orderId);
    }

    /** @return array{eligible:int,requeued:int,order_ids:list<int>} */
    public function requeueRecoverableOrders(int $limit = 100, bool $apply = false): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->pdo->query(
            'SELECT id FROM ticket_orders
             WHERE is_test = 0 AND environment = "production"
               AND (status = "paid" OR payment_status = "paid")
               AND holded_status IN ("not_required", "pending", "error")
             ORDER BY id ASC LIMIT ' . $limit
        )->fetchAll();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($apply) {
            foreach ($ids as $orderId) {
                $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "pending", holded_next_attempt_at = NOW(), holded_last_error = NULL, updated_at = NOW() WHERE id = ? AND holded_status IN ("not_required", "pending", "error")')->execute([$orderId]);
                $this->log($orderId, 'requeue_recoverable', 'planned', 0, null, null, null, null);
            }
        }
        return ['eligible' => count($ids), 'requeued' => $apply ? count($ids) : 0, 'order_ids' => $ids];
    }

    public function due(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->pdo->query(
            'SELECT id FROM ticket_orders
             WHERE is_test = 0 AND environment = "production"
               AND (status = "paid" OR payment_status = "paid")
               AND holded_status IN ("pending", "error")
               AND (holded_next_attempt_at IS NULL OR holded_next_attempt_at <= NOW())
             ORDER BY COALESCE(holded_next_attempt_at, created_at) ASC
             LIMIT ' . $limit
        )->fetchAll();
        $result = ['processed' => 0, 'synced' => 0, 'pending' => 0, 'requires_review' => 0, 'errors' => 0, 'invoice_emails_sent' => 0, 'invoice_email_errors' => 0];
        foreach ($rows as $row) {
            $result['processed']++;
            $status = $this->syncOrder((int) $row['id']);
            if (isset($result[$status])) $result[$status]++;
        }
        $invoiceDelivery = $this->deliverDueInvoiceEmails($limit);
        $result['invoice_emails_sent'] = $invoiceDelivery['sent'];
        $result['invoice_email_errors'] = $invoiceDelivery['errors'];
        $this->markStaleProcessingForReview();
        return $result;
    }

    public function syncOrder(int $orderId): string
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->loadOrder($orderId, true);
            if (!$order || !$this->isPaidOrder($order) || !empty($order['is_test']) || $order['environment'] !== 'production') {
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
            $documentId = (string) ($order['holded_document_id'] ?? '');
            if ($documentId === '') {
                try {
                    $document = $this->issueDocument($order, $documentType);
                } catch (HoldedException $error) {
                    return $this->handleFailure($orderId, $error, 'document');
                }
                $documentId = $this->externalId($document);
                if ($documentId === '') throw new HoldedException('Holded no devolvió el identificador del documento.', null, null, false, 'holded_invalid_response');
                // Persistimos el ID antes de llamar a /payments: si el pago falla,
                // el siguiente worker reutiliza este documento y jamás emite otro.
                $this->rememberDocument($orderId, $documentType, $documentId, $this->documentNumber($document));
                $this->log($orderId, 'issue_' . $documentType, 'succeeded', (int) $order['holded_sync_attempts'], null, $documentId, null, null);
            }
            if ($documentType === 'invoice' && filter_var(env_value('HOLDED_AUTO_APPROVE', 'false'), FILTER_VALIDATE_BOOLEAN)) {
                $this->client->approveInvoice($documentId);
            }
            $paymentId = (string) ($order['holded_payment_id'] ?? '');
            if ($paymentId === '') {
                $payment = $this->paymentPayload($order);
                try {
                    $paymentResponse = $documentType === 'invoice'
                        ? $this->client->recordInvoicePayment($documentId, $payment)
                        : $this->client->recordSalesReceiptPayment($documentId, $payment);
                } catch (HoldedException $error) {
                    return $this->handleFailure($orderId, $error, 'payment');
                }
                $paymentId = $this->externalId($paymentResponse);
                if ($paymentId === '') throw new HoldedException('Holded no devolvió el identificador del pago.', null, null, false, 'holded_invalid_response');
                $this->rememberPayment($orderId, $paymentId);
            }
            $this->pdo->prepare(
                'UPDATE ticket_orders
                 SET holded_status = "synced",
                     holded_document_id = ?,
                     holded_document_number = ?,
                     holded_payment_id = ?,
                     holded_pdf_available = 0,
                     holded_invoice_delivery_status = IF(? = "invoice", "pending", "not_required"),
                     holded_invoice_delivery_attempts = 0,
                     holded_invoice_delivery_sent_at = NULL,
                     holded_invoice_delivery_next_attempt_at = IF(? = "invoice", NOW(), NULL),
                     holded_invoice_delivery_last_error = NULL,
                     holded_synced_at = NOW(),
                     holded_last_error = NULL,
                     updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $documentId,
                $this->documentNumberFromOrder($orderId),
                $paymentId,
                $documentType,
                $documentType,
                $orderId,
            ]);
            $this->log($orderId, 'payment_' . $documentType, 'succeeded', (int) $order['holded_sync_attempts'], null, $documentId, null, null);
            return 'synced';
        } catch (HoldedException $error) {
            return $this->handleFailure($orderId, $error, 'finalize');
        } catch (\Throwable $error) {
            // Conservamos el tipo técnico para diagnóstico, pero nunca el
            // mensaje original: puede contener datos del pedido o de Holded.
            $type = strtolower((new \ReflectionClass($error))->getShortName());
            $safeType = preg_replace('/[^a-z0-9_]+/', '_', $type) ?: 'unknown';
            return $this->handleFailure($orderId, new HoldedException('Error interno al sincronizar con Holded.', null, null, true, 'holded_internal_' . $safeType), 'finalize');
        }
    }

    public function health(): array
    {
        $byStatus = array_fill_keys(['not_required', 'pending', 'error', 'requires_review', 'processing', 'synced'], 0);
        try {
            $columns = $this->pdo->query('SHOW COLUMNS FROM ticket_orders')->fetchAll();
            $availableColumns = array_flip(array_map(static fn (array $column): string => (string) $column['Field'], $columns));
            $requiredColumns = [
                'is_test', 'environment', 'payment_status', 'holded_status', 'holded_document_type',
                'holded_document_id', 'holded_document_number', 'holded_payment_id',
                'holded_invoice_delivery_status', 'holded_invoice_delivery_attempts',
                'holded_invoice_delivery_sent_at', 'holded_invoice_delivery_next_attempt_at',
                'holded_invoice_delivery_last_error',
            ];
            $missingColumns = array_values(array_filter($requiredColumns, static fn (string $column): bool => !isset($availableColumns[$column])));
            $hasLogs = (bool) $this->pdo->query('SHOW TABLES LIKE "holded_sync_logs"')->fetchColumn();
            if ($missingColumns || !$hasLogs) {
                return [
                    'configuration' => $this->client->health(),
                    'orders' => $byStatus,
                    'recent_diagnostics' => [],
                    'schema_ready' => false,
                    'schema_error' => 'holded_schema_unavailable',
                    'missing_schema' => array_merge($missingColumns, $hasLogs ? [] : ['holded_sync_logs']),
                    'legal_review_required' => true,
                ];
            }
            $rows = $this->pdo->query('SELECT holded_status, COUNT(*) AS total FROM ticket_orders WHERE is_test = 0 AND environment = "production" GROUP BY holded_status')->fetchAll();
            foreach ($rows as $row) $byStatus[(string) $row['holded_status']] = (int) $row['total'];
            $recent = $this->pdo->query('SELECT order_id, operation, status, attempt, http_status, error_code, created_at FROM holded_sync_logs WHERE status IN ("requires_review", "failed", "retry_scheduled") ORDER BY id DESC LIMIT 10')->fetchAll();
            return ['configuration' => $this->client->health(), 'orders' => $byStatus, 'recent_diagnostics' => $recent, 'schema_ready' => true, 'legal_review_required' => true];
        } catch (\Throwable $error) {
            // El comprobador se puede ejecutar antes de migrar sin filtrar el
            // detalle del esquema ni detener la comprobación de configuración.
            return ['configuration' => $this->client->health(), 'orders' => $byStatus, 'recent_diagnostics' => [], 'schema_ready' => false, 'schema_error' => 'holded_schema_unavailable', 'legal_review_required' => true];
        }
    }

    /** @return array{sent:int,errors:int} */
    private function deliverDueInvoiceEmails(int $limit): array
    {
        if (!filter_var(env_value('HOLDED_AUTO_SEND_EMAIL', 'false'), FILTER_VALIDATE_BOOLEAN)
            || !filter_var(env_value('HOLDED_AUTO_APPROVE', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            return ['sent' => 0, 'errors' => 0];
        }
        $rows = $this->pdo->query(
            'SELECT id, public_token, billing_name, billing_email, email, holded_document_number, holded_invoice_delivery_attempts
             FROM ticket_orders
             WHERE is_test = 0 AND environment = "production"
               AND (status = "paid" OR payment_status = "paid")
               AND holded_status = "synced" AND holded_document_type = "invoice"
               AND holded_invoice_delivery_status IN ("pending", "failed")
               AND (holded_invoice_delivery_next_attempt_at IS NULL OR holded_invoice_delivery_next_attempt_at <= NOW())
             ORDER BY COALESCE(holded_invoice_delivery_next_attempt_at, holded_synced_at) ASC
             LIMIT ' . $limit
        )->fetchAll();

        $result = ['sent' => 0, 'errors' => 0];
        foreach ($rows as $order) {
            $orderId = (int) $order['id'];
            $sent = $this->pdo->prepare(
                'SELECT 1 FROM email_deliveries
                 WHERE order_id = ? AND subject = "Tu factura Perigallo está disponible" AND status = "sent"
                 LIMIT 1'
            );
            $sent->execute([$orderId]);
            if ($sent->fetchColumn()) {
                $this->markInvoiceEmailSent($orderId);
                continue;
            }

            $attempt = (int) $order['holded_invoice_delivery_attempts'] + 1;
            $email = (string) ($order['billing_email'] ?: $order['email']);
            $name = (string) ($order['billing_name'] ?: 'cliente');
            $link = app_base_url() . '/api/orders/' . rawurlencode((string) $order['public_token']) . '/invoice';
            $status = $this->mailer->queueInvoiceEmail($this->pdo, $orderId, $email, $name, (string) ($order['holded_document_number'] ?? ''), $link);
            if ($status === 'sent') {
                $this->markInvoiceEmailSent($orderId);
                $this->log($orderId, 'invoice_email', 'succeeded', $attempt, null, null, null, null);
                $result['sent']++;
                continue;
            }

            $nextAttempt = $attempt >= 5 ? null : 900;
            if ($nextAttempt === null) {
                $this->pdo->prepare(
                    'UPDATE ticket_orders
                     SET holded_invoice_delivery_status = "failed",
                         holded_invoice_delivery_attempts = ?,
                         holded_invoice_delivery_next_attempt_at = NULL,
                         holded_invoice_delivery_last_error = "invoice_email_failed",
                         updated_at = NOW()
                     WHERE id = ?'
                )->execute([$attempt, $orderId]);
            } else {
                $this->pdo->prepare(
                    'UPDATE ticket_orders
                     SET holded_invoice_delivery_status = "failed",
                         holded_invoice_delivery_attempts = ?,
                         holded_invoice_delivery_next_attempt_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                         holded_invoice_delivery_last_error = "invoice_email_failed",
                         updated_at = NOW()
                     WHERE id = ?'
                )->execute([$attempt, $orderId]);
            }
            $this->log($orderId, 'invoice_email', 'retry_scheduled', $attempt, null, null, 'invoice_email_failed', null);
            $result['errors']++;
        }
        return $result;
    }

    private function markInvoiceEmailSent(int $orderId): void
    {
        $this->pdo->prepare(
            'UPDATE ticket_orders
             SET holded_invoice_delivery_status = "sent",
                 holded_invoice_delivery_sent_at = NOW(),
                 holded_invoice_delivery_next_attempt_at = NULL,
                 holded_invoice_delivery_last_error = NULL,
                 updated_at = NOW()
             WHERE id = ?'
        )->execute([$orderId]);
    }

    private function issueDocument(array $order, string $documentType): array
    {
        $items = $this->pdo->prepare('SELECT ticket_type_name, quantity, unit_base_cents, unit_tax_cents, tax_rate, unit_fee_cents FROM ticket_order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([(int) $order['id']]);
        $payloadItems = [];
        foreach ($items->fetchAll() as $item) {
            $taxRate = (float) ($item['tax_rate'] ?? 0);
            $line = [
                'name' => clean_string((string) $item['ticket_type_name'], 255),
                'type' => 'service',
                'units' => (int) $item['quantity'],
                'price' => $this->policy->amount((int) $item['unit_base_cents']),
                'account_id' => (string) env_value('HOLDED_SALES_ACCOUNT_ID'),
            ];
            if ($taxRate > 0) {
                $expectedRate = (float) (env_value('HOLDED_DEFAULT_TAX_RATE', '0') ?? '0');
                if (abs($taxRate - $expectedRate) > 0.001) {
                    throw new HoldedException('El IVA del pedido no coincide con el impuesto configurado en Holded.', null, null, false, 'holded_tax_mapping');
                }
                $line['taxes'] = [(string) env_value('HOLDED_DEFAULT_TAX_ID')];
            }
            $payloadItems[] = $line;

            // Los gastos de gestión se cobran por separado y nunca se incluyen
            // silenciosamente en la base imponible de la entrada.
            if ((int) $item['unit_fee_cents'] > 0) {
                $payloadItems[] = [
                    'name' => 'Gastos de gestión · ' . clean_string((string) $item['ticket_type_name'], 220),
                    'type' => 'service',
                    'units' => (int) $item['quantity'],
                    'price' => $this->policy->amount((int) $item['unit_fee_cents']),
                    'account_id' => (string) env_value('HOLDED_SALES_ACCOUNT_ID'),
                ];
            }
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

    private function rememberDocument(int $orderId, string $documentType, string $documentId, ?string $documentNumber): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ticket_orders
             SET holded_document_type = ?, holded_document_id = ?, holded_document_number = ?, updated_at = NOW()
             WHERE id = ? AND holded_status = "processing"
               AND (holded_document_id IS NULL OR holded_document_id = "" OR holded_document_id = ?)'
        );
        $statement->execute([$documentType, $documentId, $documentNumber, $orderId, $documentId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('No se ha podido guardar de forma segura el documento de Holded.');
        }
    }

    private function rememberPayment(int $orderId, string $paymentId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ticket_orders
             SET holded_payment_id = ?, updated_at = NOW()
             WHERE id = ? AND holded_status = "processing"
               AND (holded_payment_id IS NULL OR holded_payment_id = "" OR holded_payment_id = ?)'
        );
        $statement->execute([$paymentId, $orderId, $paymentId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('No se ha podido guardar de forma segura el pago de Holded.');
        }
    }

    private function documentNumberFromOrder(int $orderId): ?string
    {
        $statement = $this->pdo->prepare('SELECT holded_document_number FROM ticket_orders WHERE id = ?');
        $statement->execute([$orderId]);
        $value = $statement->fetchColumn();
        return $value ? (string) $value : null;
    }

    private function handleFailure(int $orderId, HoldedException $error, string $stage = 'sync'): string
    {
        $order = $this->loadOrder($orderId, false);
        $attempt = (int) ($order['holded_sync_attempts'] ?? 1);
        $safe = $this->safeMessage($error);
        // Una respuesta ausente o 5xx durante una escritura puede haber llegado
        // a Holded aunque no tengamos su ID. No se reintenta a ciegas: se evita
        // duplicar tanto documentos como pagos. Con un ID ya persistido, el
        // siguiente reintento reutiliza el documento y es seguro.
        $ambiguousWrite = in_array($stage, ['document', 'payment'], true)
            && empty($order['holded_' . ($stage === 'document' ? 'document' : 'payment') . '_id'])
            && ($error->safeCode === 'holded_network' || ($error->httpStatus !== null && $error->httpStatus >= 500));
        $requiresReview = $ambiguousWrite || !$error->retryable || in_array($error->httpStatus, [401, 403], true) || $attempt >= 5;
        if ($requiresReview) {
            $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "requires_review", holded_last_error = ?, holded_next_attempt_at = NULL, updated_at = NOW() WHERE id = ?')->execute([$safe, $orderId]);
            $this->log($orderId, $stage, 'requires_review', $attempt, $error->httpStatus, null, $ambiguousWrite ? 'holded_ambiguous_write' : $error->safeCode, $safe);
            return 'requires_review';
        }
        $delay = $error->retryAfterSeconds ?: [60, 300, 900, 3600, 21600][$attempt - 1];
        $this->pdo->prepare('UPDATE ticket_orders SET holded_status = "error", holded_last_error = ?, holded_next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW() WHERE id = ?')->execute([$safe, $delay, $orderId]);
        $this->log($orderId, $stage, 'retry_scheduled', $attempt, $error->httpStatus, null, $error->safeCode, $safe);
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

    private function isPaidOrder(array $order): bool
    {
        return ($order['status'] ?? '') === 'paid' || ($order['payment_status'] ?? '') === 'paid';
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
