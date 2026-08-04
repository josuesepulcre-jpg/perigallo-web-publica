<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use Throwable;

final class Mailer
{
    public function queueOrderEmail(PDO $pdo, int $orderId, string $email, string $subject, string $body): void
    {
        $this->queue($pdo, $orderId, $email, $subject, $body);
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

    private function queue(PDO $pdo, int $orderId, string $email, string $subject, string $body): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO email_deliveries (order_id, recipient_email, subject, body, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$orderId, $email, $subject, $body, 'pending']);

        try {
            $headers = [
                'From: ' . (env_value('MAIL_FROM_NAME', 'Perigallo') ?: 'Perigallo') . ' <' . (env_value('MAIL_FROM', 'entradas@perigallo.com') ?: 'entradas@perigallo.com') . '>',
                'Content-Type: text/plain; charset=UTF-8',
            ];
            $sent = mail($email, $subject, $body, implode("\r\n", $headers));
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
}
