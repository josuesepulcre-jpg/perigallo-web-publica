<?php
declare(strict_types=1);
namespace Perigallo\Ticketing {
    function mail($to,$subject,$body,$headers): bool {
        $GLOBALS['reservation_test_messages'][]=compact('to','subject','body','headers');
        return $GLOBALS['reservation_test_mail_success']??true;
    }
}
namespace {
require dirname(__DIR__).'/api/src/bootstrap.php';
use Perigallo\Ticketing\{Database,Ticketing,Redsys,Mailer};
if(env_value('DB_DATABASE')!=='perigallo_reservation_qa'||!in_array(env_value('DB_HOST'),['127.0.0.1','localhost'],true)||env_value('APP_ENV')==='production')throw new RuntimeException('Isolated QA database required');
putenv('REDSYS_ENV=test');putenv('PAYMENT_ENVIRONMENT=sandbox');putenv('REDSYS_MERCHANT_CODE=999008881');putenv('REDSYS_TERMINAL=1');putenv('REDSYS_TEST_SECRET_KEY='.base64_encode(str_repeat('q',24)));
$p=Database::pdo();$t=new Ticketing($p,new Redsys(),new Mailer());
function verify($condition,$label){if(!$condition)throw new RuntimeException($label);}
function keyId(){return bin2hex(random_bytes(16));}
$e=$t->adminCreateEvent(['title'=>'Cena & local','capacity'=>40]);$id=(int)$e['id'];
$p->prepare('UPDATE events SET status="published",visible=1,starts_at="2026-12-25 14:00:00",location="Finca La Llaguna" WHERE id=?')->execute([$id]);
$type=$t->adminCreateTicketType($id,['name'=>'Adulto','price_cents'=>6500,'capacity'=>40,'tax_rate'=>0]);
$p->prepare('UPDATE ticket_types SET status="on_sale",visible=1 WHERE id=?')->execute([$type['id']]);
$r=$t->adminCreateReservation(['request_id'=>keyId(),'event_id'=>$id,'ticket_type_id'=>$type['id'],'first_name'=>'Prueba','last_name'=>'Correo','phone'=>'600000000','email'=>'qa@example.invalid','quantity'=>8],'qa',true);
$order=(int)$r['order']['id'];
$r=$t->adminReservationPayment($order,['request_id'=>keyId(),'expected_revision'=>$r['order']['reservation_revision'],'amount_euros'=>'120','method'=>'cash'],'qa');
$body=['request_id'=>keyId(),'expected_revision'=>$r['order']['reservation_revision'],'amount_euros'=>'200'];
$r=$t->adminReservationLink($order,$body,'qa');$link=(int)$r['links'][0]['id'];
verify($r['reservation_email']['status']==='sent','automatic send');
verify(count($GLOBALS['reservation_test_messages'])===1,'one message');
$m=$GLOBALS['reservation_test_messages'][0];
foreach(['520,00 €','120,00 €','400,00 €','Importe de este enlace: 200,00 €','25/12/2026 14:00','Finca La Llaguna','Cena &amp; local','Realizar este pago','/brand/perigallo-lockup-white.png'] as $text)verify(str_contains($m['body'],$text),'missing '.$text);
verify($m['to']==='qa@example.invalid','saved recipient');
$t->adminReservationLink($order,$body,'qa');$t->adminEmailReservationLink($order,$link,'qa');
verify(count($GLOBALS['reservation_test_messages'])===1,'retries do not resend accepted email');
// Failed transport preserves the link and allows an explicit retry.
$GLOBALS['reservation_test_mail_success']=false;
$r=$t->adminReservationLink($order,['request_id'=>keyId(),'expected_revision'=>$r['order']['reservation_revision'],'amount_euros'=>'100'],'qa');$failed=(int)$r['links'][0]['id'];
verify($r['reservation_email']['status']==='failed','failure is visible');
$GLOBALS['reservation_test_mail_success']=true;
$r=$t->adminEmailReservationLink($order,$failed,'qa');verify($r['reservation_email']['status']==='sent','retry succeeds');
verify(count($r['links'])===2,'retry creates no extra link');
verify($r['order']['email_delivery_status']===null,'reservation email is not ticket delivery');
// An uncertain in-flight delivery is never resent.
$p->prepare('UPDATE email_deliveries SET status="pending" WHERE idempotency_key=?')->execute(['reservation-link:'.$failed]);
$count=count($GLOBALS['reservation_test_messages']);$t->adminEmailReservationLink($order,$failed,'qa');verify(count($GLOBALS['reservation_test_messages'])===$count,'pending is not resent');
// A failed message must not be retried for an expired or cancelled link.
$p->prepare('UPDATE email_deliveries SET status="failed" WHERE idempotency_key=?')->execute(['reservation-link:'.$failed]);
$p->prepare('UPDATE ticket_reservation_links SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=?')->execute([$failed]);
try{$t->adminEmailReservationLink($order,$failed,'qa');throw new RuntimeException('expired link sent');}catch(RuntimeException $e){verify($e->getCode()===409,'expired link rejected');}
verify(count($GLOBALS['reservation_test_messages'])===$count,'expired message not sent');
// Missing address must not create a payment link.
$p->prepare('UPDATE ticket_orders SET email="" WHERE id=?')->execute([$order]);
try{$t->adminReservationLink($order,['request_id'=>keyId(),'expected_revision'=>$r['order']['reservation_revision'],'amount_euros'=>'10'],'qa');throw new RuntimeException('missing email accepted');}catch(InvalidArgumentException $expected){}
verify(count($t->adminReservationDetail($order)['links'])===2,'missing email creates no link');
// Real signed callback: 720 EUR reservation, 180 EUR received, 540 EUR remaining.
$r=$t->adminCreateReservation(['request_id'=>keyId(),'event_id'=>$id,'ticket_type_id'=>$type['id'],'first_name'=>'Prueba','last_name'=>'Anticipo','phone'=>'600000000','email'=>'qa@example.invalid','quantity'=>6,'unit_price_euros'=>'120'],'qa',true);
$receiptOrder=(int)$r['order']['id'];
$r=$t->adminReservationLink($receiptOrder,['request_id'=>keyId(),'expected_revision'=>$r['order']['reservation_revision'],'amount_euros'=>'180'],'qa');
$form=$t->startReservationLink($r['links'][0]['public_token']);$gateway=new Redsys();$params=$gateway->decodeMerchantParameters($form['fields']['Ds_MerchantParameters']);
verify($params['DS_MERCHANT_AMOUNT']==='18000','gateway requests partial 180 only');
function notifyReceipt($response='0000',$bad=false){global $gateway,$params,$t;$payload=['DS_ORDER'=>$params['DS_MERCHANT_ORDER'],'DS_AMOUNT'=>'18000','DS_CURRENCY'=>'978','DS_MERCHANTCODE'=>'999008881','DS_TERMINAL'=>'001','DS_TRANSACTIONTYPE'=>'0','DS_RESPONSE'=>$response,'DS_AUTHORISATIONCODE'=>'QA-NO-REAL-CHARGE'];$encoded=$gateway->encodeMerchantParameters($payload);return $t->processRedsysNotification(['Ds_MerchantParameters'=>$encoded,'Ds_SignatureVersion'=>'HMAC_SHA256_V1','Ds_Signature'=>$bad?'invalid':$gateway->sign($encoded,$payload['DS_ORDER'])]);}
$count=count($GLOBALS['reservation_test_messages']);
try{notifyReceipt('0000',true);throw new RuntimeException('invalid signature accepted');}catch(InvalidArgumentException|RuntimeException $e){verify($e->getMessage()!=='invalid signature accepted','invalid signature blocked');}
verify(count($GLOBALS['reservation_test_messages'])===$count,'invalid signature sends no receipt');
$GLOBALS['reservation_test_mail_success']=false;notifyReceipt();
$d=$t->adminReservationDetail($receiptOrder);verify($d['order']['paid_cents']===18000&&$d['order']['outstanding_cents']===54000,'transport failure preserves confirmed balance');
$first=$GLOBALS['reservation_test_messages'][$count];
foreach(['Pago confirmado ahora: 180,00 €','Pagado hasta ahora (neto de devoluciones): 180,00 €','Pendiente de pagar: 540,00 €','Total de la reserva: 720,00 €','Hemos recibido tu pago','25/12/2026 14:00'] as $text)verify(str_contains($first['body'],$text),'receipt missing '.$text);
verify(!str_contains($first['body'],'Realizar este pago'),'receipt has no obsolete payment button');
$GLOBALS['reservation_test_mail_success']=true;notifyReceipt();verify(count($GLOBALS['reservation_test_messages'])===$count+2,'definite failure retries on callback');
notifyReceipt();notifyReceipt('0190');verify(count($GLOBALS['reservation_test_messages'])===$count+2,'accepted callbacks deduplicated and late denial sends nothing');
verify($t->adminReservationDetail($receiptOrder)['order']['email_delivery_status']===null,'receipt not treated as QR delivery');
echo "PASS automatic reservation email, balance, date, partial link, escaped HTML, deduplication, failure/retry, pending and missing email\n";
}
