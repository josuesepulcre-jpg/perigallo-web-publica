<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

/**
 * Cliente aislado de Holded API v2. Nunca registra cabeceras, payloads ni API keys.
 * La capa de sincronización impide cualquier petición de escritura mientras esté
 * desactivada o en dry-run.
 */
final class HoldedClient
{
    private const BASE_URL = 'https://api.holded.com/api/v2';

    public function enabled(): bool
    {
        return filter_var(env_value('HOLDED_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public function dryRun(): bool
    {
        return filter_var(env_value('HOLDED_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public function configIssues(): array
    {
        $required = [
            'HOLDED_API_KEY', 'HOLDED_PAYMENT_METHOD_ID', 'HOLDED_SALES_ACCOUNT_ID',
            'HOLDED_DEFAULT_TAX_ID', 'HOLDED_DEFAULT_TAX_RATE', 'HOLDED_TREASURY_ID', 'HOLDED_INVOICE_SERIES_ID',
            'HOLDED_SALES_RECEIPT_SERIES_ID',
        ];
        return array_values(array_filter($required, static fn (string $key): bool => !env_value($key)));
    }

    public function health(): array
    {
        return [
            'api_version' => 'v2',
            'enabled' => $this->enabled(),
            'dry_run' => $this->dryRun(),
            'configured' => count($this->configIssues()) === 0,
            'missing' => $this->configIssues(),
            'environment' => env_value('HOLDED_ENV', 'production'),
        ];
    }

    public function createContact(array $payload): array { return $this->request('POST', '/contacts', $payload); }
    public function createInvoice(array $payload): array { return $this->request('POST', '/invoices', $payload); }
    public function approveInvoice(string $id): array { return $this->request('POST', '/invoices/' . rawurlencode($id) . '/approve'); }
    public function recordInvoicePayment(string $id, array $payload): array { return $this->request('POST', '/invoices/' . rawurlencode($id) . '/payments', $payload); }
    public function invoicePdf(string $id): string
    {
        $pdf = $this->request('GET', '/invoices/' . rawurlencode($id) . '/pdf', null, true);
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new HoldedException('Holded no devolvió un PDF de factura válido.', null, null, true, 'holded_invalid_pdf');
        }
        return $pdf;
    }
    public function createSalesReceipt(array $payload): array { return $this->request('POST', '/sales-receipts', $payload); }
    public function recordSalesReceiptPayment(string $id, array $payload): array { return $this->request('POST', '/sales-receipts/' . rawurlencode($id) . '/payments', $payload); }
    public function createCreditNote(array $payload): array { return $this->request('POST', '/receipt-notes', $payload); }
    public function paymentMethods(): array { return $this->request('GET', '/payment-methods'); }
    public function salesReceipts(array $filters = []): array
    {
        $allowed = ['limit', 'cursor', 'contact_id', 'status', 'start_date', 'end_date', 'sort', 'approval_status'];
        $query = array_intersect_key($filters, array_flip($allowed));
        return $this->request('GET', '/sales-receipts' . ($query ? '?' . http_build_query($query) : ''));
    }
    public function invoices(array $filters = []): array
    {
        $allowed = ['limit', 'cursor', 'contact_id', 'status', 'start_date', 'end_date', 'sort', 'approval_status'];
        $query = array_intersect_key($filters, array_flip($allowed));
        return $this->request('GET', '/invoices' . ($query ? '?' . http_build_query($query) : ''));
    }
    public function taxes(): array { return $this->request('GET', '/taxes'); }
    public function numberingSeries(string $type): array { return $this->request('GET', '/numbering-series/' . rawurlencode($type)); }

    /** @return array<string, mixed>|string */
    private function request(string $method, string $path, ?array $payload = null, bool $binary = false): array|string
    {
        if (!$this->enabled() || $this->dryRun()) {
            throw new HoldedException('La integración Holded está desactivada o en modo seguro.', null, null, false, 'holded_not_active');
        }
        $apiKey = env_value('HOLDED_API_KEY');
        if (!$apiKey) {
            throw new HoldedException('Falta la configuración de Holded.', null, null, false, 'holded_not_configured');
        }
        if (!function_exists('curl_init')) {
            throw new HoldedException('La extensión cURL no está disponible.', null, null, false, 'curl_unavailable');
        }
        $curl = curl_init(self::BASE_URL . $path);
        $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => true,
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        if ($response === false || $errno !== 0) {
            throw new HoldedException('No se pudo conectar con Holded.', null, null, true, 'holded_network');
        }
        $headerText = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $decoded = $body === '' ? [] : json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $retryAfter = null;
            if (preg_match('/^retry-after:\s*(\d+)/mi', $headerText, $matches)) $retryAfter = (int) $matches[1];
            $retryable = $status === 429 || $status >= 500;
            $code = in_array($status, [401, 403], true) ? 'holded_authorization' : ($status === 429 ? 'holded_rate_limit' : 'holded_http');
            throw new HoldedException('Holded ha rechazado la operación (' . $status . ').', $status, $retryAfter, $retryable, $code);
        }
        if ($binary) {
            return $body;
        }
        return is_array($decoded) ? $decoded : [];
    }
}
