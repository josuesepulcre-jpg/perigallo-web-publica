<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;

/** Compatibility facade: delivery is now executed by the persistent worker. */
final class TicketDeliveryService
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function sendOrder(PDO $pdo, array $order, array $event, int $quantity): array
    {
        (new TicketDeliveryQueue($pdo, $this->mailer))->enqueuePaidOrder((int) $order['id']);
        return ['email' => 'queued', 'whatsapp' => !empty($order['whatsapp_consent']) ? 'queued' : 'not_authorized'];
    }

    private function orderEmailHtml(array $order, array $event, int $quantity, string $link, bool $isTest, ?int $referenceTotal): string
    {
        $title = $this->escape((string) ($event['title'] ?? 'Tu experiencia Perigallo'));
        $name = $this->escape((string) ($order['name'] ?? ''));
        $subtitle = $this->escape((string) ($event['subtitle'] ?? ''));
        $location = $this->escape((string) ($event['location'] ?? ''));
        $date = $this->eventDate((string) ($event['starts_at'] ?? ''));
        $safeLink = $this->escape($link);
        $brandLogo = $this->escape(app_base_url() . '/brand/perigallo-lockup-white.png?v=20261108');
        $ticketLabel = $quantity === 1 ? '1 entrada confirmada' : $quantity . ' entradas confirmadas';
        $paidTotal = (int) ($order['total_cents'] ?? 0);
        $pricingBlock = $referenceTotal !== null && $referenceTotal > $paidTotal
            ? '<div style="margin-top:16px;padding-top:15px;border-top:1px solid #50554c;text-align:center"><span style="display:block;color:#b9b9b2;font-size:11px;letter-spacing:1.2px;text-transform:uppercase">Valor de la experiencia</span><del style="display:block;margin-top:4px;color:#d0d0c8;font-family:Arial,Helvetica,sans-serif;font-size:19px">' . $this->money($referenceTotal) . '</del><span style="display:block;margin-top:10px;color:#c9cec4;font-size:11px;letter-spacing:1.2px;text-transform:uppercase">Importe pagado</span><strong style="display:block;margin-top:4px;color:#f3f3ee;font-family:Arial,Helvetica,sans-serif;font-size:25px;font-weight:normal">' . $this->money($paidTotal) . '</strong></div>'
            : '<div style="margin-top:16px;padding-top:15px;border-top:1px solid #50554c;text-align:center"><span style="display:block;color:#c9cec4;font-size:11px;letter-spacing:1.2px;text-transform:uppercase">Importe pagado</span><strong style="display:block;margin-top:4px;color:#f3f3ee;font-family:Arial,Helvetica,sans-serif;font-size:25px;font-weight:normal">' . $this->money($paidTotal) . '</strong></div>';
        $image = $this->imageUrl((string) (($event['card_image_url'] ?? '') ?: ($event['image_url'] ?? '')));
        $preview = $isTest ? 'MODO DE PRUEBAS · No corresponde a una compra real.' : 'Tu compra se ha confirmado. Guarda este correo para tener el acceso siempre a mano.';
        $imageBlock = $image === '' ? '' : '<tr><td><img src="' . $image . '" width="600" alt="' . $title . '" style="display:block;width:100%;height:auto;border:0" /></td></tr>';
        $testBlock = $isTest ? '<tr><td style="padding:14px 34px;background:#3c5142;color:#f3f3ee;font-size:11px;font-weight:bold;letter-spacing:1.4px;text-align:center">MODO DE PRUEBAS · NO SE HA REALIZADO NINGÚN CARGO</td></tr>' : '';
        $dateBlock = $date === '' ? '' : '<td width="50%" style="padding:16px 0;border-top:1px solid #50554c;color:#d0d0c8;font-size:13px;line-height:1.55"><span style="display:block;color:#c9cec4;font-size:10px;letter-spacing:1.8px;text-transform:uppercase;margin-bottom:5px">Fecha</span>' . $this->escape($date) . '</td>';
        $locationBlock = $location === '' ? '' : '<td width="50%" style="padding:16px 0;border-top:1px solid #50554c;color:#d0d0c8;font-size:13px;line-height:1.55"><span style="display:block;color:#c9cec4;font-size:10px;letter-spacing:1.8px;text-transform:uppercase;margin-bottom:5px">Lugar</span>' . $location . '</td>';
        $details = $dateBlock || $locationBlock ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>' . $dateBlock . ($dateBlock && $locationBlock ? '<td width="28" style="border-top:1px solid #50554c"></td>' : '') . $locationBlock . '</tr></table>' : '';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;background:#f3f3ee">'
            . '<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden">' . $this->escape($preview) . '</span>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f3ee"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#141414;color:#f3f3ee;font-family:Arial,Helvetica,sans-serif">'
            . '<tr><td style="padding:26px 34px 24px;text-align:center;border-bottom:1px solid #50554c"><img src="' . $brandLogo . '" width="248" height="30" alt="Perigallo" style="display:inline-block;width:248px;max-width:100%;height:auto;border:0"><div style="margin-top:12px;color:#c9cec4;font-size:9px;letter-spacing:3px">EL GUSTO DE CELEBRAR</div></td></tr>'
            . $imageBlock . $testBlock
            . '<tr><td style="padding:36px 34px 12px"><div style="color:#c9cec4;font-size:10px;letter-spacing:2.3px;text-transform:uppercase">Tu experiencia está confirmada</div><h1 style="margin:14px 0 10px;color:#f3f3ee;font-family:Arial,Helvetica,sans-serif;font-size:37px;font-weight:normal;line-height:1.08">' . $title . '</h1>'
            . ($subtitle === '' ? '' : '<p style="margin:0;color:#c9cec4;font-family:Arial,Helvetica,sans-serif;font-size:19px;line-height:1.35">' . $subtitle . '</p>')
            . '<p style="margin:22px 0 0;color:#d0d0c8;font-size:15px;line-height:1.7">' . ($name === '' ? 'Hola,<br>' : 'Hola ' . $name . ',<br>') . $this->escape($preview) . '</p></td></tr>'
            . '<tr><td style="padding:24px 34px 0"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #50554c;background:#20201e"><tr><td style="padding:20px 22px"><span style="display:block;color:#c9cec4;font-size:10px;letter-spacing:1.9px;text-transform:uppercase;margin-bottom:7px">Tus entradas</span><strong style="display:block;color:#f3f3ee;font-family:Arial,Helvetica,sans-serif;font-size:27px;font-weight:normal">' . $this->escape($ticketLabel) . '</strong>' . $pricingBlock . '</td><td align="right" style="padding:20px 22px;color:#f3f3ee;font-size:20px">→</td></tr></table></td></tr>'
            . '<tr><td style="padding:24px 34px">' . $details . '<div style="padding-top:24px;text-align:center"><a href="' . $safeLink . '" style="display:inline-block;background:#f3f3ee;color:#141414;padding:17px 26px;font-size:12px;font-weight:bold;letter-spacing:1.4px;text-decoration:none;text-transform:uppercase">Descargar mis entradas&nbsp;&nbsp;→</a></div><p style="margin:16px 0 0;color:#b9b9b2;font-size:12px;line-height:1.6;text-align:center">El enlace abre tu pedido y permite descargar todas las entradas.</p></td></tr>'
            . '<tr><td style="padding:22px 34px;background:#20201e;border-top:1px solid #50554c;color:#b9b9b2;font-size:11px;line-height:1.7;text-align:center">Perigallo · Finca La Llaguna<br>Si necesitas ayuda, responde a este correo.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private function eventDate(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }
        $weekdays = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $weekdays[(int) date('w', $timestamp)] . ', ' . date('j', $timestamp) . ' de ' . $months[(int) date('n', $timestamp)] . ' de ' . date('Y', $timestamp) . ' · ' . date('H:i', $timestamp);
    }

    private function imageUrl(string $value): string
    {
        if (str_starts_with($value, '/')) {
            $value = app_base_url() . $value;
        }
        return filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $value) ? $this->escape($value) : '';
    }

    private function referenceTotal(PDO $pdo, int $orderId): ?int
    {
        $stmt = $pdo->prepare('SELECT SUM(reference_total_cents) FROM ticket_order_items WHERE order_id = ? AND show_reference_price = 1');
        $stmt->execute([$orderId]);
        $value = $stmt->fetchColumn();
        return $value === null ? null : (int) $value;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
