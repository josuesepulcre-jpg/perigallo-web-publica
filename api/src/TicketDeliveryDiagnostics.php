<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use Throwable;

final class TicketDeliveryDiagnostics
{
    public static function error(Throwable $error, string $stage, array $context = []): array
    {
        $message = $error->getMessage();
        foreach (getenv() as $name => $value) {
            if (preg_match('/PASSWORD|SECRET|TOKEN|KEY|USERNAME/', $name) && is_string($value) && $value !== '') {
                $message = str_replace($value, '[redacted]', $message);
            }
        }
        $message = preg_replace(['#https?://\S+#i', '/[\w.+%-]+@[\w.-]+\.[A-Za-z]{2,}/', '/[A-Za-z0-9_+\/-]{32,}/', '/[\r\n]+/'], '[redacted]', $message) ?? 'redacted';
        return $context + [
            'event' => 'ticket_delivery_error', 'stage' => $stage,
            'error_type' => get_class($error), 'code' => (string) $error->getCode(),
            'driver_code' => $error instanceof \PDOException ? ($error->errorInfo[1] ?? null) : null,
            'message' => substr($message, 0, 600), 'file' => basename($error->getFile()),
            'line' => $error->getLine(), 'time' => date(DATE_ATOM), 'php' => PHP_VERSION,
        ];
    }

    public static function log(array $record): void
    {
        $line = 'Perigallo ticket_delivery ' . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        error_log(rtrim($line)); // Keep Plesk's error notification.
        // The CLI has no error_log file configured. Keep evidence outside the web root,
        // even if the local MTA cannot deliver the cron notification.
        $private = dirname(__DIR__, 3) . '/private';
        if (is_dir($private) && is_writable($private)) {
            $previousMask = umask(0077);
            try {
                $stream = @fopen($private . '/ticket-delivery.log', 'ab');
            } finally {
                umask($previousMask);
            }
            if (is_resource($stream)) {
                if (flock($stream, LOCK_EX)) {
                    fwrite($stream, $line);
                    flock($stream, LOCK_UN);
                }
                fclose($stream);
            }
        }
    }
}
