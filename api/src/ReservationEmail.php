<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;

/** Snapshot of a reservation balance, distinct from the final ticket email. */
final class ReservationEmail
{
    public static function message(array $order, array $finance, array $event, array $link): array
    {
        $money=static fn($c)=>number_format((int)$c/100,2,',','.').' €';
        $date=static fn($value)=>(new \DateTimeImmutable($value,new \DateTimeZone('Europe/Madrid')))->format('d/m/Y H:i').' (hora de Madrid)';
        $url=app_base_url().'/entradas/pago-reserva/?token='.rawurlencode($link['public_token']);
        $subject='Tu reserva en Perigallo · '.$event['title'];
        $body='Hola '.$order['name'].",\n\nHemos registrado tu reserva.\n\n"
            .'Evento: '.$event['title']."\nFecha: ".$date($event['starts_at'])."\nLugar: ".$event['location']
            ."\nReferencia: ".$order['redsys_order']."\nPersonas: ".$event['quantity']
            ."\n\nTotal de la reserva: ".$money($order['total_cents'])
            ."\nPagado hasta ahora (neto de devoluciones): ".$money($finance['paid_cents'])
            ."\nPendiente de pagar: ".$money($finance['outstanding_cents'])
            ."\n\nImporte de este enlace: ".$money($link['amount_cents'])
            ."\nEnlace válido hasta: ".$date($link['expires_at'])
            .(!empty($order['reservation_expires_at'])?"\nReserva vigente hasta: ".$date($order['reservation_expires_at']):'')
            ."\n\nPuedes realizar este pago aquí:\n".$url
            ."\n\nEl saldo refleja los movimientos registrados al enviar este correo. Este mensaje no acredita el pago ni sustituye las entradas con QR.\n\nGracias por celebrar con Perigallo.";
        return ['subject'=>$subject,'body'=>$body,'url'=>$url];
    }
    public static function payment(array $order, array $finance, array $event, int $amount): array
    {
        $money=static fn($c)=>number_format((int)$c/100,2,',','.').' €';
        $date=(new \DateTimeImmutable($event['starts_at'],new \DateTimeZone('Europe/Madrid')))->format('d/m/Y H:i');
        return ['subject'=>'Pago recibido · '.$event['title'],'heading'=>'Hemos recibido tu pago','url'=>'',
            'body'=>'Hola '.$order['name'].",\n\nHemos recibido tu pago de ".$money($amount)." para tu reserva.\n\n"
                .'Evento: '.$event['title']."\nFecha: ".$date." (hora de Madrid)\nLugar: ".$event['location']
                ."\nReferencia: ".$order['redsys_order']."\nPersonas: ".$event['quantity']
                ."\n\nTotal de la reserva: ".$money($order['total_cents'])
                ."\nPago confirmado ahora: ".$money($amount)
                ."\nPagado hasta ahora (neto de devoluciones): ".$money($finance['paid_cents'])
                ."\nPendiente de pagar: ".$money($finance['outstanding_cents'])
                .($finance['outstanding_cents']>0?"\n\nTu reserva sigue confirmada. Quedan ".$money($finance['outstanding_cents']).' pendientes.':"\n\nTu reserva está pagada por completo.")
                ."\n\nEl saldo refleja los movimientos registrados al confirmar este pago.\n\nGracias por celebrar con Perigallo."];
    }

}
