<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

final class HoldedFiscalPolicy
{
    public function documentType(array $order): string
    {
        $threshold = max(0, (int) (env_value('HOLDED_SIMPLIFIED_MAX_CENTS', '40000') ?? '40000'));
        return !empty($order['billing_requested']) || (int) $order['total_cents'] > $threshold ? 'invoice' : 'salesreceipt';
    }

    public function configurationIssues(string $documentType): array
    {
        $issues = [];
        foreach (['HOLDED_DEFAULT_TAX_ID', 'HOLDED_PAYMENT_METHOD_ID', 'HOLDED_TREASURY_ID'] as $key) {
            if (!env_value($key)) $issues[] = $key;
        }
        $taxRate = env_value('HOLDED_DEFAULT_TAX_RATE');
        if ($taxRate === null || $taxRate === '' || !is_numeric($taxRate) || (float) $taxRate < 0 || (float) $taxRate > 100) {
            $issues[] = 'HOLDED_DEFAULT_TAX_RATE inválido';
        }
        $series = $documentType === 'invoice' ? 'HOLDED_INVOICE_SERIES_ID' : 'HOLDED_SALES_RECEIPT_SERIES_ID';
        if (!env_value($series)) $issues[] = $series;
        return $issues;
    }

    public function amount(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
