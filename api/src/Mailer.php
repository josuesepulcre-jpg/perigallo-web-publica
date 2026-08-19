<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use Throwable;

final class Mailer
{
    /** @return array{sent: bool, error: ?string} */
    public function sendLeadNotification(string $email, string $subject, string $body): array
    {
        try {
            $headers = [
                'From: ' . (env_value('MAIL_FROM_NAME', 'Perigallo') ?: 'Perigallo') . ' <' . (env_value('MAIL_FROM', 'entradas@perigallo.com') ?: 'entradas@perigallo.com') . '>',
                'Content-Type: text/plain; charset=UTF-8',
            ];
            $sent = mail($email, $subject, $body, implode("\r\n", $headers));
            return ['sent' => $sent, 'error' => $sent ? null : 'mail() devolvió false. Configura el SMTP transaccional en Plesk.'];
        } catch (Throwable $error) {
            return ['sent' => false, 'error' => 'Fallo técnico de envío registrado.'];
        }
    }

    public function sendAnalyticsReport(string $email, string $subject, string $body, string $htmlBody): bool
    {
        try {
            $boundary = 'perigallo-analytics-' . bin2hex(random_bytes(12));
            $headers = [
                'From: ' . (env_value('MAIL_FROM_NAME', 'Perigallo') ?: 'Perigallo') . ' <' . (env_value('MAIL_FROM', 'entradas@perigallo.com') ?: 'entradas@perigallo.com') . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];
            $message = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}\r\n\r\n"
                . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$htmlBody}\r\n\r\n"
                . "--{$boundary}--";
            return mail($email, $subject, $message, implode("\r\n", $headers));
        } catch (Throwable $error) {
            return false;
        }
    }

    public function queueOrderEmail(PDO $pdo, int $orderId, string $email, string $subject, string $body, ?string $htmlBody = null): string
    {
        return $this->queue($pdo, $orderId, $email, $subject, $body, $htmlBody ?? $this->basicOrderHtml($subject, $body));
    }

    /**
     * Entrega el PDF ya creado para el pedido. La clave persistente evita que
     * un webhook repetido programe un segundo correo de la misma versión.
     */
    public function sendTicketDocumentEmail(PDO $pdo, int $orderId, string $email, string $subject, string $body, string $htmlBody, array $document, string $idempotencyKey): string
    {
        $existing = $pdo->prepare('SELECT status FROM email_deliveries WHERE idempotency_key = ? LIMIT 1');
        $existing->execute([$idempotencyKey]);
        $previous = $existing->fetchColumn();
        if ($previous !== false) {
            return (string) $previous;
        }

        $insert = $pdo->prepare(
            'INSERT INTO email_deliveries (order_id, idempotency_key, recipient_email, subject, body, status, document_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "pending", ?, NOW(), NOW())'
        );
        try {
            $insert->execute([$orderId, $idempotencyKey, $email, $subject, $body, TicketDocumentService::DOCUMENT_VERSION]);
        } catch (\Throwable $error) {
            $existing->execute([$idempotencyKey]);
            $previous = $existing->fetchColumn();
            if ($previous !== false) {
                return (string) $previous;
            }
            throw $error;
        }
        $deliveryId = (int) $pdo->lastInsertId();

        try {
            $mixed = 'perigallo-mixed-' . bin2hex(random_bytes(12));
            $alternative = 'perigallo-alt-' . bin2hex(random_bytes(12));
            $headers = [
                'From: ' . (env_value('MAIL_FROM_NAME', 'Perigallo') ?: 'Perigallo') . ' <' . (env_value('MAIL_FROM', 'entradas@perigallo.com') ?: 'entradas@perigallo.com') . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
            ];
            $filename = $this->headerFilename((string) $document['filename']);
            $message = '--' . $mixed . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $alternative . "\"\r\n\r\n"
                . '--' . $alternative . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . $body . "\r\n\r\n"
                . '--' . $alternative . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n\r\n"
                . '--' . $alternative . "--\r\n"
                . '--' . $mixed . "\r\nContent-Type: application/pdf; name=\"" . $filename . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n"
                . chunk_split(base64_encode((string) $document['content']))
                . '--' . $mixed . '--';
            $sent = mail($email, $subject, $message, implode("\r\n", $headers));
            $status = $sent ? 'sent' : 'failed';
            $error = $sent ? null : 'mail() devolvió false. Configura el SMTP transaccional en Plesk.';
        } catch (Throwable $error) {
            $status = 'failed';
            $error = 'Fallo técnico de envío registrado.';
        }
        $update = $pdo->prepare('UPDATE email_deliveries SET status = ?, error_message = ?, sent_at = IF(? = "sent", NOW(), sent_at), updated_at = NOW() WHERE id = ?');
        $update->execute([$status, $error, $status, $deliveryId]);
        return $status;
    }

    public function queueOrderRecoveryEmail(PDO $pdo, int $orderId, string $email, string $name, string $link): string
    {
        $body = "Hola {$name},\n\nHemos recibido una solicitud para acceder a tus entradas. Puedes abrirlas desde este enlace seguro:\n{$link}\n\nSi no has solicitado este acceso, puedes ignorar este mensaje.\n\nEquipo Perigallo\n";
        return $this->queue(
            $pdo,
            $orderId,
            $email,
            'Accede de nuevo a tus entradas Perigallo',
            $body,
            $this->recoveryOrderHtml($name, $link)
        );
    }

    public function queueInvoiceEmail(PDO $pdo, int $orderId, string $email, string $name, string $invoiceNumber, string $link): string
    {
        $number = $invoiceNumber !== '' ? ' ' . $invoiceNumber : '';
        $body = "Hola {$name},\n\nTu factura{$number} ya está disponible. Puedes descargarla desde este enlace seguro:\n{$link}\n\nEste enlace está asociado a tu pedido. Si no has solicitado esta factura, ignora este mensaje.\n\nEquipo Perigallo\n";
        return $this->queue(
            $pdo,
            $orderId,
            $email,
            'Tu factura Perigallo está disponible',
            $body,
            $this->basicOrderHtml('Tu factura está disponible', $body, 'Descargar factura')
        );
    }

    private function queue(PDO $pdo, int $orderId, string $email, string $subject, string $body, ?string $htmlBody = null): string
    {
        $stmt = $pdo->prepare(
            'INSERT INTO email_deliveries (order_id, recipient_email, subject, body, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$orderId, $email, $subject, $body, 'pending']);

        try {
            $headers = [
                'From: ' . (env_value('MAIL_FROM_NAME', 'Perigallo') ?: 'Perigallo') . ' <' . (env_value('MAIL_FROM', 'entradas@perigallo.com') ?: 'entradas@perigallo.com') . '>',
                'MIME-Version: 1.0',
            ];
            $message = $body;
            if ($htmlBody !== null) {
                $boundary = 'perigallo-' . bin2hex(random_bytes(12));
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                $message = "--{$boundary}\r\n"
                    . "Content-Type: text/plain; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                    . $body . "\r\n\r\n"
                    . "--{$boundary}\r\n"
                    . "Content-Type: text/html; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                    . $htmlBody . "\r\n\r\n"
                    . "--{$boundary}--";
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            }
            $sent = mail($email, $subject, $message, implode("\r\n", $headers));
            $status = $sent ? 'sent' : 'failed';
            $error = $sent ? null : 'mail() devolvio false. Configurar SMTP transaccional en Plesk para produccion.';
        } catch (Throwable $e) {
            $status = 'failed';
            $error = 'Fallo de envio registrado.';
        }

        $update = $pdo->prepare(
            'UPDATE email_deliveries SET status = ?, error_message = ?, sent_at = IF(? = \'sent\', NOW(), sent_at), updated_at = NOW() WHERE id = LAST_INSERT_ID()'
        );
        $update->execute([$status, $error, $status]);
        return $status;
    }

    private function headerFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'entradas-perigallo.pdf';
        return trim($filename, '-') ?: 'entradas-perigallo.pdf';
    }

    private function basicOrderHtml(string $subject, string $body, string $buttonLabel = 'Descargar mis entradas'): string
    {
        preg_match('#https?://\S+#', $body, $linkMatch);
        $link = (string) ($linkMatch[0] ?? '');
        $copy = $link === '' ? $body : trim(str_replace($link, '', $body));
        $escapedBody = nl2br(htmlspecialchars($copy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $brandLogo = htmlspecialchars(app_base_url() . '/assets/images/perigallo-logo-original.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $button = $link === '' ? '' : '<p style="margin:26px 0 0"><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:inline-block;background:#cdb197;color:#173236;padding:15px 20px;font-size:12px;font-weight:bold;letter-spacing:1px;text-decoration:none;text-transform:uppercase">' . htmlspecialchars($buttonLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' →</a></p>';

        return '<!doctype html><html lang="es"><body style="margin:0;padding:0;background:#eef0ed">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef0ed"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#173236;color:#f5f0e5;font-family:Arial,sans-serif">'
            . '<tr><td style="padding:26px 34px 24px;border-bottom:1px solid #6f7668;text-align:center"><img src="' . $brandLogo . '" width="76" alt="Perigallo" style="display:inline-block;width:76px;height:auto;border:0"><div style="color:#d7c3a2;font-size:9px;letter-spacing:3px;margin-top:12px">FINCA LA LLAGUNA</div></td></tr>'
            . '<tr><td style="padding:34px"><div style="color:#cdb197;font-size:10px;letter-spacing:2.2px;text-transform:uppercase">Tus entradas Perigallo</div><h1 style="margin:14px 0 18px;color:#f5f0e5;font-family:Georgia,serif;font-size:34px;font-weight:normal;line-height:1.12">' . $safeSubject . '</h1><div style="color:#d7d4cb;font-size:16px;line-height:1.7">' . $escapedBody . '</div>' . $button . '</td></tr>'
            . '<tr><td style="padding:22px 34px;background:#102629;border-top:1px solid #43585a;color:#aeb7b3;font-size:11px;line-height:1.7;text-align:center">Perigallo · Finca La Llaguna<br>Si necesitas ayuda, responde a este correo.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private function recoveryOrderHtml(string $name, string $link): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $brandLogo = htmlspecialchars(app_base_url() . '/assets/images/perigallo-logo-original.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $greeting = $safeName === '' ? 'Hola,' : 'Hola ' . $safeName . ',';

        return '<!doctype html><html lang="es"><body style="margin:0;padding:0;background:#eef0ed">'
            . '<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden">Tu acceso seguro a las entradas de Perigallo.</span>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef0ed"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#173236;color:#f5f0e5;font-family:Arial,sans-serif">'
            . '<tr><td style="padding:26px 34px 24px;text-align:center;border-bottom:1px solid #6f7668"><img src="' . $brandLogo . '" width="84" alt="Perigallo" style="display:inline-block;width:84px;height:auto;border:0"><div style="margin-top:12px;color:#cdb197;font-size:9px;letter-spacing:3px">FINCA LA LLAGUNA</div></td></tr>'
            . '<tr><td style="padding:38px 34px 12px"><div style="color:#cdb197;font-size:10px;letter-spacing:2.3px;text-transform:uppercase">Tus entradas siguen aquí</div><h1 style="margin:14px 0 18px;color:#f5f0e5;font-family:Georgia,serif;font-size:37px;font-weight:normal;line-height:1.08">Accede de nuevo a tus entradas</h1><p style="margin:0;color:#d7d4cb;font-size:15px;line-height:1.7">' . $greeting . '<br>Hemos recibido una solicitud para acceder a tu pedido. Utiliza este enlace seguro para abrir y descargar tus entradas.</p></td></tr>'
            . '<tr><td style="padding:24px 34px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #7f725d;background:#203e42"><tr><td style="padding:20px 22px"><span style="display:block;color:#cdb197;font-size:10px;letter-spacing:1.9px;text-transform:uppercase;margin-bottom:7px">Acceso seguro</span><strong style="display:block;color:#f5f0e5;font-family:Georgia,serif;font-size:24px;font-weight:normal">Tus entradas Perigallo</strong></td><td align="right" style="padding:20px 22px;color:#d8bd96;font-size:20px">→</td></tr></table><div style="padding-top:24px;text-align:center"><a href="' . $safeLink . '" style="display:inline-block;background:#d8bd96;color:#173236;padding:17px 26px;font-size:12px;font-weight:bold;letter-spacing:1.4px;text-decoration:none;text-transform:uppercase">Abrir mis entradas&nbsp;&nbsp;→</a></div><p style="margin:16px 0 0;color:#aeb7b3;font-size:12px;line-height:1.6;text-align:center">Si no has solicitado este acceso, puedes ignorar este correo con tranquilidad.</p></td></tr>'
            . '<tr><td style="padding:22px 34px;background:#102629;border-top:1px solid #43585a;color:#aeb7b3;font-size:11px;line-height:1.7;text-align:center">Perigallo · Finca La Llaguna<br>Si necesitas ayuda, responde a este correo.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
