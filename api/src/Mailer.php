<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use Throwable;

final class Mailer
{
    public function queueOrderEmail(PDO $pdo, int $orderId, string $email, string $subject, string $body, ?string $htmlBody = null): void
    {
        $this->queue($pdo, $orderId, $email, $subject, $body, $htmlBody ?? $this->basicOrderHtml($subject, $body));
    }

    public function queueOrderRecoveryEmail(PDO $pdo, int $orderId, string $email, string $name, string $link): void
    {
        $this->queue(
            $pdo,
            $orderId,
            $email,
            'Accede de nuevo a tus entradas Perigallo',
            "Hola {$name},\n\nHemos recibido una solicitud para acceder a tus entradas. Puedes abrirlas desde este enlace seguro:\n{$link}\n\nSi no has solicitado este acceso, puedes ignorar este mensaje.\n\nEquipo Perigallo\n"
        );
    }

    private function queue(PDO $pdo, int $orderId, string $email, string $subject, string $body, ?string $htmlBody = null): void
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
    }

    private function basicOrderHtml(string $subject, string $body): string
    {
        preg_match('#https?://\S+#', $body, $linkMatch);
        $link = (string) ($linkMatch[0] ?? '');
        $copy = $link === '' ? $body : trim(str_replace($link, '', $body));
        $escapedBody = nl2br(htmlspecialchars($copy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $button = $link === '' ? '' : '<p style="margin:26px 0 0"><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:inline-block;background:#cdb197;color:#173236;padding:15px 20px;font-size:12px;font-weight:bold;letter-spacing:1px;text-decoration:none;text-transform:uppercase">Descargar mis entradas →</a></p>';

        return '<!doctype html><html lang="es"><body style="margin:0;padding:0;background:#eef0ed">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef0ed"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#173236;color:#f5f0e5;font-family:Arial,sans-serif">'
            . '<tr><td style="padding:30px 34px 22px;border-bottom:1px solid #7f725d;text-align:center"><div style="color:#cdb197;font-size:11px;letter-spacing:4px">PERIGALLO</div><div style="color:#d7c3a2;font-size:9px;letter-spacing:3px;margin-top:8px">FINCA LA LLAGUNA</div></td></tr>'
            . '<tr><td style="padding:34px"><h1 style="margin:0 0 20px;color:#f5f0e5;font-family:Georgia,serif;font-size:32px;font-weight:normal;line-height:1.12">' . $safeSubject . '</h1><div style="color:#d7d4cb;font-size:16px;line-height:1.7">' . $escapedBody . '</div>' . $button . '</td></tr>'
            . '<tr><td style="padding:20px 34px;background:#11282b;color:#b9beb9;font-size:12px;line-height:1.6">Este correo ha sido enviado por Perigallo.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
