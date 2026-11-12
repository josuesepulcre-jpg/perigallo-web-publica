<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/** Additional operations on canonical ticket_orders. No second inventory or gateway. */
trait ReservationOperations
{
    private ?bool $reservationSchema = null;

    private function reservationAudit(string $actor,string $action,int $id,array $context): void
    {
        $this->pdo->prepare('INSERT INTO ticket_admin_audit_logs (actor,action,entity_type,entity_id,context_json,created_at) VALUES (?,?,"ticket_order",?,?,NOW())')->execute([$actor,$action,$id,json_encode($context,JSON_THROW_ON_ERROR)]);
    }

    private function reservationSchemaAvailable(): bool
    {
        if ($this->reservationSchema !== null) return $this->reservationSchema;
        $q=$this->pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ticket_orders' AND column_name='reservation_revision'");
        return $this->reservationSchema=(int)$q->fetchColumn()===1;
    }

    private function reservationRequireSchema(): void
    {
        if (!$this->reservationSchemaAvailable()) throw new RuntimeException('La gestión de pagos parciales todavía no está disponible.',503);
    }

    private function reservationKey(array $data): string
    {
        $key=(string)($data['request_id']??'');
        if (!preg_match('/^[A-Za-z0-9_-]{12,80}$/',$key)) throw new InvalidArgumentException('Falta una referencia válida para guardar esta operación.');
        return $key;
    }

    private function reservationHash(array $data): string { return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)); }

    private function reservationVersion(array $order,array $data): void
    {
        if (!array_key_exists('expected_revision',$data) || (int)$data['expected_revision']!==(int)$order['reservation_revision']) throw new RuntimeException('La reserva ha cambiado. Recarga su ficha antes de guardar.',409);
    }

    private function reservationTransaction(callable $operation): mixed
    {
        for($try=0;;$try++) {
            $this->pdo->beginTransaction();
            try { $result=$operation();$this->pdo->commit();$this->reservationRevenueCache=null;return $result; }
            catch(\Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack();if($e instanceof \PDOException && in_array((int)($e->errorInfo[1]??0),[1205,1213],true) && $try<2){usleep(10000);continue;}throw $e; }
        }
    }

    private function reservationLock(int $id): array
    {
        $o=$this->lockedOrder($id);
        if(($o['sales_channel']??'')!=='reservation')throw new RuntimeException('Esta operación corresponde a las reservas con pagos parciales.',409);
        return $o;
    }

    private function reservationActive(array $o): bool
    {
        return !in_array($o['status'],['cancelled','refunded','expired'],true) && ($o['status']==='paid'||empty($o['reservation_expires_at'])||strtotime($o['reservation_expires_at'])>time());
    }

    private function reservationFinance(array $o,array $payments): array
    {
        $received=0;$refunded=0;$methods=[];$review=0;
        foreach($payments as $p){$amount=(int)$p['amount_cents'];$refund=$p['kind']==='refund';if($refund)$refunded+=$amount;else $received+=$amount;$methods[$p['method']]=($methods[$p['method']]??0)+($refund?-$amount:$amount);if(isset($p['review_amount_cents']))$review+=(int)$p['review_amount_cents'];elseif(!empty($p['review_required']))$review+=$amount;}
        $net=$received-$refunded;$total=(int)$o['total_cents'];
        $economic=$net>=$total?'paid':($refunded>0?($net===0?'refunded':'partially_refunded'):($net>0?'partial':'pending'));
        $occupancy=in_array($o['status'],['cancelled','refunded'],true)?'cancelled':(!$this->reservationActive($o)?'expired':($o['order_status']==='confirmed'?'confirmed':'pending'));
        return ['is_reservation'=>true,'received_cents'=>$received,'refunded_cents'=>$refunded,'paid_cents'=>$net,'outstanding_cents'=>max(0,$total-$net),'credit_cents'=>max(0,$net-$total),'review_cents'=>$review,'payments_by_method'=>$methods,'economic_status'=>$economic,'occupancy_status'=>$occupancy,'reservation_revision'=>(int)$o['reservation_revision']];
    }

    private function reservationEnrichOrders(array $orders): array
    {
        $ids=array_values(array_map(fn($o)=>(int)$o['id'],array_filter($orders,fn($o)=>($o['sales_channel']??'')==='reservation')));
        if(!$ids||!$this->reservationSchemaAvailable())return $orders;
        $q=$this->pdo->prepare('SELECT order_id,kind,method,SUM(amount_cents) amount_cents,SUM(CASE WHEN review_required=1 THEN amount_cents ELSE 0 END) review_amount_cents FROM ticket_reservation_payments WHERE order_id IN ('.implode(',',array_fill(0,count($ids),'?')).') GROUP BY order_id,kind,method');$q->execute($ids);$by=[];foreach($q->fetchAll() as $p)$by[$p['order_id']][]=$p;
        // Existing list queries deliberately select a bounded set of order fields.
        $q=$this->pdo->prepare('SELECT id,reservation_revision FROM ticket_orders WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$revisions=array_column($q->fetchAll(),'reservation_revision','id');
        foreach($orders as &$o)if(($o['sales_channel']??'')==='reservation'){$o['reservation_revision']=$revisions[$o['id']]??0;$o+=$this->reservationFinance($o,$by[$o['id']]??[]);}unset($o);return $orders;
    }

    private function reservationPayments(int $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM ticket_reservation_payments WHERE order_id=? ORDER BY paid_at,id');$q->execute([$id]);return $q->fetchAll();
    }

    private function reservationHolds(int $id): int
    {
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM ticket_reservation_links WHERE order_id=? AND status="processing"');$q->execute([$id]);return (int)$q->fetchColumn();
    }

    private function reservationCancelPendingLinks(int $id,string $reason): void
    {
        $this->pdo->prepare('UPDATE ticket_reservation_links SET status="cancelled",cancellation_reason=?,updated_at=NOW() WHERE order_id=? AND status="pending"')->execute([$reason,$id]);
    }

    private function reservationContact(array $data): array
    {
        $first=clean_string((string)($data['first_name']??''),120);$last=clean_string((string)($data['last_name']??''),160);$phone=clean_string((string)($data['phone']??''),60);$email=mb_strtolower(clean_string((string)($data['email']??''),190));
        if(!$first||!$last||!$phone)throw new InvalidArgumentException('Indica nombre, apellidos y teléfono del cliente.');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Revisa el correo electrónico.');
        return [$first,$last,trim($first.' '.$last),$email,$phone];
    }

    private function reservationPrice(array $type,array $data,bool $canEditPrice,?int $oldPrice=null): array
    {
        $base=(int)$type['price_cents'];$taxRate=(float)($type['tax_rate']??0);$fee=(int)($type['fee_cents']??0);$tax=(int)round($base*$taxRate/100);$reference=$base+$tax+$fee;
        $price=$oldPrice??$reference;
        if(array_key_exists('unit_price_euros',$data)){$requested=$this->cashDiscountCents($data['unit_price_euros']);if($requested!==$price&&!$canEditPrice)throw new RuntimeException('Solo la cuenta propietaria puede ajustar el precio.',403);$price=$requested;}
        if($price<$fee||$price>100000000)throw new InvalidArgumentException('El precio por persona no es válido.');
        if($price!==$reference){$base=(int)round(($price-$fee)*100/(100+$taxRate));$tax=$price-$fee-$base;}
        return [$price,$base,$tax,$taxRate,$fee,$reference];
    }

    public function adminCreateReservation(array $data,string $actor,bool $canEditPrice): array
    {
        $this->reservationRequireSchema();$key=$this->reservationKey($data);$contact=$this->reservationContact($data);$qty=filter_var($data['quantity']??0,FILTER_VALIDATE_INT);$eventId=(int)($data['event_id']??0);$typeId=(int)($data['ticket_type_id']??0);
        if(!$qty||$qty<1||$qty>5000||$eventId<1||$typeId<1)throw new InvalidArgumentException('Selecciona evento, tarifa y un número de personas entre 1 y 5000.');
        $occupancy=(string)($data['occupancy_status']??'confirmed');if(!in_array($occupancy,['confirmed','pending'],true))throw new InvalidArgumentException('Estado inicial de reserva no válido.');
        $expiry=trim((string)($data['reservation_expires_at']??''));$expiry=$expiry!==''?$this->cashReservationExpiry($expiry):($occupancy==='pending'?$this->cashReservationExpiry(''):null);
        $notes=clean_string((string)($data['notes']??''),1000);$mode=($data['inventory_mode']??'standard')==='manual_reserve'?'manual_reserve':'standard';$hash=$this->reservationHash([$contact,$qty,$eventId,$typeId,$data['unit_price_euros']??null,$occupancy,$data['reservation_expires_at']??'',$notes,$mode]);
        $id=$this->reservationTransaction(function()use($data,$actor,$canEditPrice,$key,$contact,$qty,$eventId,$typeId,$occupancy,$expiry,$notes,$mode,$hash){
            $this->pdo->prepare('SELECT id FROM events WHERE id=? FOR UPDATE')->execute([$eventId]);
            $q=$this->pdo->prepare('SELECT id,reservation_request_hash FROM ticket_orders WHERE reservation_request_id=?');$q->execute([$key]);if($old=$q->fetch()){if($old['reservation_request_hash']!==$hash)throw new RuntimeException('La referencia de guardado ya corresponde a otra reserva.',409);return (int)$old['id'];}
            $event=$this->requireAdminEvent($eventId);if(in_array($event['status'],['draft','archived','cancelled'],true))throw new InvalidArgumentException('Selecciona un evento disponible para reservas.');
            $this->lockEventPlaces($eventId,[['quantity'=>$qty]]);$type=$this->lockCashTicketType($typeId,$eventId);if(!$type)throw new InvalidArgumentException('La tarifa no está disponible.');
            $available=$mode==='manual_reserve'?$this->manualReserveAvailableForType($type):$this->standardAvailableForType($type);if($qty>$available)throw new RuntimeException('No hay suficientes plazas en el cupo seleccionado.',409);
            [$price,$base,$tax,$rate,$fee,$reference]=$this->reservationPrice($type,$data,$canEditPrice);$total=$price*$qty;if($total>2000000000)throw new InvalidArgumentException('El importe de la reserva es demasiado alto.');
            $q=$this->pdo->prepare('INSERT INTO ticket_orders (public_token,redsys_order,first_name,last_name,name,email,phone,subtotal_cents,total_cents,currency,status,order_status,payment_status,delivery_status,sales_channel,inventory_mode,cash_payment_status,cash_payment_notes,reservation_expires_at,reservation_request_id,reservation_request_hash,reservation_revision,is_test,environment,holded_status,holded_excluded,holded_exclusion_reason,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,"pending",?,"pending","pending","reservation",?,"not_applicable",?,?,?,?,1,?, ?,"not_required",1,"reservation_unpaid",NOW(),NOW())');
            $q->execute([public_token(),$this->nextRedsysOrder(),...$contact,$total,$total,env_value('REDSYS_CURRENCY','978'),$occupancy==='confirmed'?'confirmed':'pending_payment',$mode,$notes?:null,$expiry,$key,$hash,!empty($event['is_test'])?1:0,!empty($event['is_test'])?'sandbox':'production']);$id=(int)$this->pdo->lastInsertId();
            $this->insertOrderItem($id,$eventId,$typeId,$type['name'],$qty,$price,$base,$tax,$rate,$fee,$reference,$total,null);
            $this->reservationAudit($actor,'reservation_created',$id,['event_id'=>$eventId,'quantity'=>$qty,'total_cents'=>$total,'occupancy_status'=>$occupancy,'request_id'=>$key]);
            if($total===0)$this->reservationSync($id);
            return $id;
        });return $this->adminReservationDetail($id);
    }

    public function adminReservationDetail(int $id): array
    {
        $this->reservationRequireSchema();$o=$this->adminOrderById($id);if(($o['sales_channel']??'')!=='reservation')throw new RuntimeException('Reserva no encontrada.',404);
        $q=$this->pdo->prepare('SELECT i.*,e.starts_at AS event_starts_at FROM ticket_order_items i JOIN events e ON e.id=i.event_id WHERE i.order_id=? ORDER BY i.id');$q->execute([$id]);$items=$q->fetchAll();
        $q=$this->pdo->prepare('SELECT l.*, (SELECT status FROM email_deliveries WHERE idempotency_key=CONCAT("reservation-link:",l.id)) AS email_status FROM ticket_reservation_links l WHERE order_id=? ORDER BY id DESC');$q->execute([$id]);$links=$q->fetchAll();foreach($links as &$l){$l['url']=app_base_url().'/entradas/pago-reserva/?token='.rawurlencode($l['public_token']);if($l['status']==='pending'&&strtotime($l['expires_at'])<=time())$l['status']='expired';}unset($l);
        $q=$this->pdo->prepare('SELECT action,actor,context_json,created_at FROM ticket_admin_audit_logs WHERE entity_type="ticket_order" AND entity_id=? ORDER BY id DESC LIMIT 50');$q->execute([$id]);
        return ['order'=>$o,'items'=>$items,'payments'=>$this->reservationPayments($id),'links'=>$links,'history'=>$q->fetchAll(),'processing_cents'=>$this->reservationHolds($id)];
    }

    private function reservationSync(int $id): void
    {
        $o=$this->reservationLock($id);$f=$this->reservationFinance($o,$this->reservationPayments($id));
        $terminal=in_array($o['status'],['cancelled','refunded','expired'],true);$paid=$f['outstanding_cents']===0;
        $status=$terminal?$o['status']:($paid?'paid':'pending');$paymentStatus=$f['paid_cents']>=(int)$o['total_cents']?'paid':($f['economic_status']==='refunded'?'refunded':'pending');
        $allCash=$f['paid_cents']>0&&($f['payments_by_method']['cash']??0)===$f['paid_cents'];$excluded=!$paid||$terminal||$allCash||(int)$o['total_cents']===0;
        $this->pdo->prepare('UPDATE ticket_orders SET status=?,payment_status=?,reservation_expires_at=IF(? AND NOT ?,NULL,reservation_expires_at),paid_at=IF(? AND NOT ?,COALESCE(paid_at,NOW()),paid_at),reservation_revision=reservation_revision+1,delivery_status=IF(? AND NOT ?,"generated",delivery_status),holded_excluded=?,holded_exclusion_reason=?,holded_status=IF(? AND holded_status<>"synced","pending",holded_status),updated_at=NOW() WHERE id=?')->execute([$status,$paymentStatus,$paid?1:0,$terminal?1:0,$paid?1:0,$terminal?1:0,$paid?1:0,$terminal?1:0,$excluded?1:0,$excluded?($allCash?'cash_sale':'reservation_unpaid'):null,!$excluded?1:0,$id]);
        if(!empty($o['holded_document_id'])&&$f['refunded_cents']>0)$this->pdo->prepare('UPDATE ticket_orders SET holded_excluded=0,holded_status="requires_review",holded_last_error="Reserva con devolución registrada; revisar la rectificación fiscal antes de sincronizar." WHERE id=?')->execute([$id]);
        if(!$terminal&&$paid){$this->generateTicketsOnce($id,'issued',true);$this->pdo->prepare('UPDATE tickets t JOIN ticket_order_items i ON i.id=t.order_item_id SET t.status="issued",t.updated_at=NOW() WHERE i.order_id=? AND t.status="blocked"')->execute([$id]);}
        elseif(!$terminal)$this->pdo->prepare('UPDATE tickets t JOIN ticket_order_items i ON i.id=t.order_item_id SET t.status="blocked",t.updated_at=NOW() WHERE i.order_id=? AND t.status="issued"')->execute([$id]);
    }

    public function adminReservationPayment(int $id,array $data,string $actor,bool $refund=false): array
    {
        $this->reservationRequireSchema();$key=$this->reservationKey($data);$amount=$this->cashDiscountCents($data['amount_euros']??'');if($amount<=0)throw new InvalidArgumentException('Indica un importe mayor que cero.');
        $method=(string)($data['method']??'');if(!in_array($method,['cash','card_present','bank_transfer','other'],true))throw new InvalidArgumentException('Selecciona efectivo, tarjeta presencial, transferencia u otro método manual.');
        if($refund&&($data['confirmed']??false)!==true)throw new InvalidArgumentException('Confirma que la devolución ya se ha realizado fuera de la aplicación.');
        $date=trim((string)($data['paid_at']??''));$when=$date!==''?$this->nullableDate($date):now_mysql();if(!$when||strtotime($when)>time()+300)throw new InvalidArgumentException('La fecha del pago no es válida o está en el futuro.');
        $reference=clean_string((string)($data['reference']??''),190);$notes=clean_string((string)($data['notes']??''),1000);$kind=$refund?'refund':'receipt';$hash=$this->reservationHash([$kind,$method,$amount,$date,$reference,$notes]);
        $this->reservationTransaction(function()use($id,$data,$actor,$key,$amount,$method,$when,$reference,$notes,$kind,$hash,$refund){
            $o=$this->reservationLock($id);$q=$this->pdo->prepare('SELECT request_hash FROM ticket_reservation_payments WHERE order_id=? AND idempotency_key=?');$q->execute([$id,$key]);if($prior=$q->fetch()){if($prior['request_hash']!==$hash)throw new RuntimeException('Esta referencia ya se utilizó para otro pago.',409);return;}
            $this->reservationVersion($o,$data);$f=$this->reservationFinance($o,$this->reservationPayments($id));
            if($refund){if($amount>$f['paid_cents'])throw new InvalidArgumentException('La devolución supera el dinero recibido pendiente de devolver.');if($this->reservationHolds($id)>0)throw new RuntimeException('Hay un pago online en curso. Espera su confirmación antes de devolver.',409);}
            else {if(!$this->reservationActive($o))throw new RuntimeException('La reserva está cancelada o caducada. Revisa su estado antes de cobrar.',409);if($amount>$f['outstanding_cents']-$this->reservationHolds($id))throw new RuntimeException('El importe supera el saldo disponible; puede haber un pago online en curso.',409);}
            $this->pdo->prepare('INSERT INTO ticket_reservation_payments (order_id,kind,method,amount_cents,paid_at,reference,notes,actor,idempotency_key,request_hash,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')->execute([$id,$kind,$method,$amount,$when,$reference?:null,$notes?:null,$actor,$key,$hash]);
            $this->reservationCancelPendingLinks($id,'Saldo actualizado por una operación manual');$this->reservationSync($id);$this->reservationAudit($actor,$refund?'reservation_refund_recorded':'reservation_payment_recorded',$id,['amount_cents'=>$amount,'method'=>$method,'request_id'=>$key]);
        });return $this->adminReservationDetail($id);
    }

    public function adminReservationLink(int $id,array $data,string $actor): array
    {
        $this->reservationRequireSchema();$this->redsys->assertConfigured();$key=$this->reservationKey($data);$amount=$this->cashDiscountCents($data['amount_euros']??'');if($amount<=0)throw new InvalidArgumentException('Indica un importe mayor que cero.');
        $expires=trim((string)($data['expires_at']??''));$when=$this->cashReservationExpiry($expires);$hash=$this->reservationHash([$amount,$expires]);
        $this->reservationTransaction(function()use($id,$data,$actor,$key,$amount,$when,$hash){
            $o=$this->reservationLock($id);$q=$this->pdo->prepare('SELECT request_hash FROM ticket_reservation_links WHERE order_id=? AND idempotency_key=?');$q->execute([$id,$key]);if($prior=$q->fetch()){if($prior['request_hash']!==$hash)throw new RuntimeException('Esta referencia ya corresponde a otro enlace.',409);return;}
            $this->reservationVersion($o,$data);if(!$this->reservationActive($o))throw new RuntimeException('La reserva no admite nuevos enlaces de pago.',409);
            $f=$this->reservationFinance($o,$this->reservationPayments($id));$q=$this->pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM ticket_reservation_links WHERE order_id=? AND (status="processing" OR (status="pending" AND expires_at>NOW()))');$q->execute([$id]);if($amount>(int)$f['outstanding_cents']-(int)$q->fetchColumn())throw new RuntimeException('El importe supera el saldo libre de otros enlaces. Cancela primero los enlaces pendientes que quieras sustituir.',409);
            $expiry=$when;if(!empty($o['reservation_expires_at'])&&$o['reservation_expires_at']<$expiry)$expiry=$o['reservation_expires_at'];
            $this->pdo->prepare('INSERT INTO ticket_reservation_links (order_id,public_token,amount_cents,currency,expires_at,reservation_expiry_snapshot,idempotency_key,request_hash,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([$id,public_token(32),$amount,$o['currency'],$expiry,$o['reservation_expires_at'],$key,$hash,$actor]);
            $this->pdo->prepare('UPDATE ticket_orders SET reservation_revision=reservation_revision+1,updated_at=NOW() WHERE id=?')->execute([$id]);$this->reservationAudit($actor,'reservation_payment_link_created',$id,['amount_cents'=>$amount,'request_id'=>$key]);
        });
        $q=$this->pdo->prepare('SELECT id FROM ticket_reservation_links WHERE order_id=? AND idempotency_key=?');$q->execute([$id,$key]);
        $linkId=(int)$q->fetchColumn();
        $detail=$this->adminReservationDetail($id);
        $sendEmail=array_key_exists('send_email',$data)
            ? filter_var($data['send_email'],FILTER_VALIDATE_BOOLEAN)
            : (bool)filter_var($detail['order']['email']??'',FILTER_VALIDATE_EMAIL);
        if(!$sendEmail)return $detail+['reservation_email'=>['status'=>'skipped','recipient'=>'']];
        // El enlace ya está guardado aunque el correo no esté disponible.
        if(!filter_var($detail['order']['email']??'',FILTER_VALIDATE_EMAIL))return $detail+['reservation_email'=>['status'=>'skipped','recipient'=>'']];
        return $this->adminEmailReservationLink($id,$linkId,$actor);
    }

    public function adminEmailReservationLink(int $id,int $linkId,string $actor): array
    {
        $job=$this->reservationTransaction(function()use($id,$linkId,$actor){
            $o=$this->reservationLock($id);
            $q=$this->pdo->prepare('SELECT * FROM ticket_reservation_links WHERE id=? AND order_id=?');$q->execute([$linkId,$id]);$link=$q->fetch();
            if(!$link)throw new RuntimeException('Enlace no encontrado.',404);
            $key='reservation-link:'.$linkId;
            $q=$this->pdo->prepare('SELECT * FROM email_deliveries WHERE idempotency_key=?');$q->execute([$key]);$prior=$q->fetch();
            if($prior&&in_array($prior['status'],['sent','pending'],true))return ['status'=>$prior['status'],'recipient'=>$prior['recipient_email']];
            if(!$this->reservationActive($o)||$link['status']!=='pending'||strtotime($link['expires_at'])<=time())throw new RuntimeException('El enlace ya no está disponible para enviar.',409);
            if(!filter_var($o['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Añade un correo válido en Editar reserva.');
            $finance=$this->reservationFinance($o,$this->reservationPayments($id));
            if($finance['outstanding_cents']<(int)$link['amount_cents'])throw new RuntimeException('El saldo ha cambiado. Genera un enlace actualizado.',409);
            $q=$this->pdo->prepare('SELECT e.title,e.starts_at,e.location,SUM(i.quantity) AS quantity FROM ticket_order_items i JOIN events e ON e.id=i.event_id WHERE i.order_id=? GROUP BY e.id,e.title,e.starts_at,e.location');$q->execute([$id]);$event=$q->fetch();
            $message=ReservationEmail::message($o,$finance,$event,$link);
            if($prior){$emailId=(int)$prior['id'];$this->pdo->prepare('UPDATE email_deliveries SET recipient_email=?,subject=?,body=?,status="pending",error_message=NULL,updated_at=NOW() WHERE id=?')->execute([$o['email'],$message['subject'],$message['body'],$emailId]);}
            else{$this->pdo->prepare('INSERT INTO email_deliveries(order_id,idempotency_key,recipient_email,subject,body,status,created_at,updated_at) VALUES(?,?,?,?,?,"pending",NOW(),NOW())')->execute([$id,$key,$o['email'],$message['subject'],$message['body']]);$emailId=(int)$this->pdo->lastInsertId();}
            $this->reservationAudit($actor,'reservation_email_requested',$id,['link_id'=>$linkId,'email_id'=>$emailId]);
            return ['id'=>$emailId,'message'=>$message,'recipient'=>$o['email']];
        });
        if(isset($job['id'])){
            try{$sent=$this->mailer->sendReservationMessage($job['recipient'],$job['message']);}catch(\Throwable $e){$sent=false;}
            $job['status']=$sent?'sent':'failed';
            $this->pdo->prepare('UPDATE email_deliveries SET status=?,error_message=?,sent_at=IF(?="sent",NOW(),NULL),updated_at=NOW() WHERE id=?')->execute([$job['status'],$sent?null:'mail_not_accepted',$job['status'],$job['id']]);
        }
        return $this->adminReservationDetail($id)+['reservation_email'=>['status'=>$job['status'],'recipient'=>$job['recipient']]];
    }

    public function adminCancelReservationLink(int $id,int $linkId,array $data,string $actor,bool $canReconcile=false): array
    {
        $this->reservationTransaction(function()use($id,$linkId,$data,$actor,$canReconcile){
            $o=$this->reservationLock($id);$q=$this->pdo->prepare('SELECT * FROM ticket_reservation_links WHERE id=? AND order_id=? FOR UPDATE');$q->execute([$linkId,$id]);$link=$q->fetch();if(!$link)throw new RuntimeException('Enlace no encontrado.',404);if($link['status']==='cancelled')return;$this->reservationVersion($o,$data);
            if($link['status']==='processing'){
                $reason=clean_string((string)($data['reason']??''),1000);
                if(!$canReconcile||($data['confirmed_no_charge']??false)!==true||!$reason)throw new RuntimeException('El pago está en curso. La cuenta propietaria debe comprobar en Redsys que no hubo cargo antes de cerrar el intento.',409);
                $this->pdo->prepare('UPDATE payment_attempts SET status="manual_review",updated_at=NOW() WHERE id=? AND status="created"')->execute([$link['attempt_id']]);
                $this->reservationAudit($actor,'reservation_payment_attempt_reconciled',$id,['link_id'=>$linkId,'reason'=>$reason,'external_no_charge_confirmed'=>true]);
            }elseif(!in_array($link['status'],['pending','failed'],true))throw new RuntimeException('Este enlace ya no se puede cancelar.',409);
            $this->pdo->prepare('UPDATE ticket_reservation_links SET status="cancelled",cancellation_reason="Cancelado por administración",updated_at=NOW() WHERE id=?')->execute([$linkId]);
            if($link['status']==='processing')$this->reservationRestoreExpiry($id,$link);$this->reservationSync($id);$this->reservationAudit($actor,'reservation_payment_link_cancelled',$id,['link_id'=>$linkId]);
        });return $this->adminReservationDetail($id);
    }

    public function reservationPublicLink(string $token): array
    {
        $q=$this->pdo->prepare('SELECT l.*,o.name,o.sales_channel,o.status order_state,o.reservation_expires_at,o.redsys_order,(SELECT e.title FROM ticket_order_items i JOIN events e ON e.id=i.event_id WHERE i.order_id=o.id ORDER BY i.id LIMIT 1) event_title FROM ticket_reservation_links l JOIN ticket_orders o ON o.id=l.order_id WHERE l.public_token=?');$q->execute([$token]);$l=$q->fetch();if(!$l)throw new RuntimeException('Enlace no encontrado.',404);
        $status=$l['status'];if($status==='pending'&&(strtotime($l['expires_at'])<=time()||in_array($l['order_state'],['cancelled','refunded','expired'],true)))$status='expired';
        return ['reference'=>$l['redsys_order'],'event_title'=>$l['event_title'],'amount_cents'=>(int)$l['amount_cents'],'status'=>$status,'expires_at'=>$l['expires_at']];
    }

    public function startReservationLink(string $token): array
    {
        $this->reservationRequireSchema();$this->redsys->assertConfigured();$q=$this->pdo->prepare('SELECT order_id FROM ticket_reservation_links WHERE public_token=?');$q->execute([$token]);$id=(int)$q->fetchColumn();if(!$id)throw new RuntimeException('Enlace no encontrado.',404);
        return $this->reservationTransaction(function()use($token,$id){$o=$this->reservationLock($id);$q=$this->pdo->prepare('SELECT * FROM ticket_reservation_links WHERE public_token=? FOR UPDATE');$q->execute([$token]);$l=$q->fetch();if(!$this->reservationActive($o)||!in_array($l['status'],['pending','processing'],true)||($l['status']==='pending'&&strtotime($l['expires_at'])<=time()))throw new RuntimeException('Este enlace ya no admite pagos.',409);
            $f=$this->reservationFinance($o,$this->reservationPayments($id));if((int)$l['amount_cents']>$f['outstanding_cents'])throw new RuntimeException('El saldo ha cambiado. Solicita un enlace actualizado.',409);
            if($l['status']==='pending'&&(int)$l['amount_cents']>$f['outstanding_cents']-$this->reservationHolds($id))throw new RuntimeException('Hay otro pago en curso. Espera su confirmación.',409);
            if($l['attempt_id']){$q=$this->pdo->prepare('SELECT redsys_order FROM payment_attempts WHERE id=?');$q->execute([$l['attempt_id']]);$number=(string)$q->fetchColumn();}
            else {$number=$this->nextRedsysOrder();$this->pdo->prepare('INSERT INTO payment_attempts (order_id,redsys_order,environment,amount_cents,currency,signature_version,payment_method,status,reservation_link_id,created_at,updated_at) VALUES (?,?,?,?,?,?,"card","created",?,NOW(),NOW())')->execute([$id,$number,env_value('REDSYS_ENV','test'),$l['amount_cents'],$o['currency'],env_value('REDSYS_SIGNATURE_VERSION','HMAC_SHA256_V1'),$l['id']]);$attempt=(int)$this->pdo->lastInsertId();$this->pdo->prepare('UPDATE ticket_reservation_links SET attempt_id=?,status="processing",updated_at=NOW() WHERE id=?')->execute([$attempt,$l['id']]);}
            $this->pdo->prepare('UPDATE ticket_orders SET status="payment_processing",order_status="confirmed",reservation_expires_at=NULL,updated_at=NOW() WHERE id=?')->execute([$id]);
            $q=$this->pdo->prepare('SELECT e.* FROM events e JOIN ticket_order_items i ON i.event_id=e.id WHERE i.order_id=? LIMIT 1');$q->execute([$id]);return $this->redsysForm($number,(int)$l['amount_cents'],$q->fetch(),$o['public_token'],'card','/entradas/pago-reserva/?token='.rawurlencode($token));
        });
    }

    private function reservationQueuePaymentEmail(array $order,int $attemptId,int $amount): void
    {
        if(!filter_var($order['email']??'',FILTER_VALIDATE_EMAIL))return;
        $q=$this->pdo->prepare('SELECT e.title,e.starts_at,e.location,SUM(i.quantity) AS quantity FROM ticket_order_items i JOIN events e ON e.id=i.event_id WHERE i.order_id=? GROUP BY e.id,e.title,e.starts_at,e.location');$q->execute([$order['id']]);$event=$q->fetch();
        $finance=$this->reservationFinance($order,$this->reservationPayments((int)$order['id']));
        $message=ReservationEmail::payment($order,$finance,$event,$amount);
        $this->pdo->prepare('INSERT INTO email_deliveries(order_id,idempotency_key,recipient_email,subject,body,status,error_message,created_at,updated_at) VALUES(?,?,?,?,?,"pending","reservation_receipt_ready",NOW(),NOW())')->execute([$order['id'],'reservation-payment:'.$attemptId,$order['email'],$message['subject'],$message['body']]);
    }

    /** Runs after commit. Only a definite transport failure is eligible for retry. */
    private function reservationSendPaymentEmail(int $attemptId): void
    {
        try {
            $q=$this->pdo->prepare('SELECT * FROM email_deliveries WHERE idempotency_key=?');$q->execute(['reservation-payment:'.$attemptId]);$job=$q->fetch();if(!$job)return;
            $claim=$this->pdo->prepare('UPDATE email_deliveries SET status="pending",error_message="reservation_receipt_sending",updated_at=NOW() WHERE id=? AND ((status="pending" AND error_message="reservation_receipt_ready") OR (status="failed" AND error_message="mail_not_accepted"))');$claim->execute([$job['id']]);if($claim->rowCount()!==1)return;
            try{$sent=$this->mailer->sendReservationMessage($job['recipient_email'],['subject'=>$job['subject'],'heading'=>'Hemos recibido tu pago','body'=>$job['body'],'url'=>'']);}catch(\Throwable $e){$sent=false;}
            $this->pdo->prepare('UPDATE email_deliveries SET status=?,error_message=?,sent_at=IF(?="sent",NOW(),NULL),updated_at=NOW() WHERE id=?')->execute([$sent?'sent':'failed',$sent?null:'mail_not_accepted',$sent?'sent':'failed',$job['id']]);
        } catch(\Throwable $e) {
            // A receipt failure must never undo an already confirmed payment.
            error_log('Reservation payment email requires review for attempt '.$attemptId);
        }
    }

    private function reservationRestoreExpiry(int $id,array $link): void
    {
        if($this->reservationHolds($id)===0 && !empty($link['reservation_expiry_snapshot'])) {
            $o=$this->reservationLock($id);$f=$this->reservationFinance($o,$this->reservationPayments($id));
            if($f['outstanding_cents']>0)$this->pdo->prepare('UPDATE ticket_orders SET reservation_expires_at=? WHERE id=?')->execute([$link['reservation_expiry_snapshot'],$id]);
        }
    }

    /** Runs only after the shared callback verified signature, merchant, amount and environment. */
    private function reservationRedsysNotification(array $attempt,array $o,array $params,string $hash,string $response,bool $accepted): array
    {
        $id=(int)$o['id'];$q=$this->pdo->prepare('SELECT * FROM ticket_reservation_links WHERE id=? AND order_id=? FOR UPDATE');$q->execute([$attempt['reservation_link_id'],$id]);$link=$q->fetch();if(!$link||(int)$link['attempt_id']!==(int)$attempt['id']||(int)$link['amount_cents']!==(int)$attempt['amount_cents'])throw new RuntimeException('La referencia del pago parcial no coincide.');
        // An accepted notification is terminal: a later denial cannot undo money received.
        if($attempt['status']==='accepted'||($attempt['status']==='denied'&&!$accepted)){$this->pdo->commit();if($attempt['status']==='accepted')$this->reservationSendPaymentEmail((int)$attempt['id']);return ['ok'=>true,'accepted'=>$attempt['status']==='accepted','duplicate'=>true,'order'=>$attempt['redsys_order']];}
        $this->pdo->prepare('UPDATE payment_attempts SET response_code=?,authorisation_code=?,status=?,notification_hash=?,notification_received_at=NOW(),raw_response=?,updated_at=NOW() WHERE id=?')->execute([$response,$params['DS_AUTHORISATIONCODE']??'',$accepted?'accepted':'denied',$hash,json_encode(array_intersect_key($params,array_flip(['DS_ORDER','DS_AMOUNT','DS_CURRENCY','DS_MERCHANTCODE','DS_TERMINAL','DS_TRANSACTIONTYPE','DS_RESPONSE','DS_AUTHORISATIONCODE'])),JSON_UNESCAPED_UNICODE),$attempt['id']]);
        if($accepted){$f=$this->reservationFinance($o,$this->reservationPayments($id));$review=!$this->reservationActive($o)||(int)$attempt['amount_cents']>$f['outstanding_cents']||$link['status']!=='processing'||$attempt['status']==='manual_review';$this->pdo->prepare('INSERT INTO ticket_reservation_payments (order_id,kind,method,amount_cents,paid_at,reference,actor,idempotency_key,request_hash,payment_attempt_id,review_required,created_at) VALUES (?,"receipt","card_online",?,NOW(),?,"redsys",?,?,?,?,NOW())')->execute([$id,$attempt['amount_cents'],$params['DS_AUTHORISATIONCODE']??$attempt['redsys_order'],'redsys-'.$attempt['id'],$hash,$attempt['id'],$review?1:0]);$this->pdo->prepare('UPDATE ticket_reservation_links SET status="paid",updated_at=NOW() WHERE id=?')->execute([$link['id']]);if($link['status']==='processing')$this->reservationRestoreExpiry($id,$link);if($review&&!$this->reservationActive($o))$this->pdo->prepare('UPDATE ticket_orders SET status="expired" WHERE id=? AND status NOT IN ("cancelled","refunded")')->execute([$id]);$this->reservationSync($id);$this->reservationAudit('redsys',$review?'reservation_online_payment_review':'reservation_online_payment_confirmed',$id,['attempt_id'=>$attempt['id'],'amount_cents'=>$attempt['amount_cents'],'review_required'=>$review]);if(!$review)$this->reservationQueuePaymentEmail($o,(int)$attempt['id'],(int)$attempt['amount_cents']);}
        else {$this->pdo->prepare('UPDATE ticket_reservation_links SET status="failed",updated_at=NOW() WHERE id=? AND status IN ("pending","processing")')->execute([$link['id']]);if($link['status']==='processing')$this->reservationRestoreExpiry($id,$link);$this->reservationSync($id);}
        $this->pdo->commit();$this->reservationRevenueCache=null;if($accepted)$this->reservationSendPaymentEmail((int)$attempt['id']);return ['ok'=>true,'accepted'=>$accepted,'order'=>$attempt['redsys_order']];
    }
    public function adminUpdateReservation(int $id,array $data,string $actor,bool $canEditPrice): array
    {
        $contact=$this->reservationContact($data);$qty=filter_var($data['quantity']??0,FILTER_VALIDATE_INT);if(!$qty||$qty<1||$qty>5000)throw new InvalidArgumentException('Indica entre 1 y 5000 personas.');
        $q=$this->pdo->prepare('SELECT event_id FROM ticket_order_items WHERE order_id=? ORDER BY id LIMIT 1');$q->execute([$id]);$eventId=(int)$q->fetchColumn();if(!$eventId)throw new RuntimeException('Reserva no encontrada.',404);
        $this->reservationTransaction(function()use($id,$data,$actor,$canEditPrice,$contact,$qty,$eventId){
            $this->pdo->prepare('SELECT id FROM events WHERE id=? FOR UPDATE')->execute([$eventId]);$o=$this->reservationLock($id);$this->reservationVersion($o,$data);
            $q=$this->pdo->prepare('SELECT i.*,e.starts_at AS event_starts_at FROM ticket_order_items i JOIN events e ON e.id=i.event_id WHERE i.order_id=? ORDER BY i.id FOR UPDATE');$q->execute([$id]);$items=$q->fetchAll();if(count($items)!==1)throw new RuntimeException('Esta reserva requiere conservar su desglose de tarifas.',409);$item=$items[0];
            $typeId=(int)($data['ticket_type_id']??$item['ticket_type_id']);$type=$this->lockCashTicketType($typeId,$eventId);if(!$type)throw new InvalidArgumentException('La tarifa no está disponible.');
            [$price,$base,$tax,$rate,$fee,$reference]=$this->reservationPrice($type,$data,$canEditPrice,$typeId===(int)$item['ticket_type_id']?(int)$item['unit_price_cents']:null);
            $total=$price*$qty;if($total>2000000000)throw new InvalidArgumentException('El importe de la reserva es demasiado alto.');
            $f=$this->reservationFinance($o,$this->reservationPayments($id));if($total<$f['paid_cents'])throw new RuntimeException('El nuevo total es inferior a lo cobrado. Registra primero la devolución ya realizada.',409);
            $occupancy=(string)($data['occupancy_status']??'confirmed');if(!in_array($occupancy,['confirmed','pending'],true))throw new InvalidArgumentException('Estado de ocupación no válido.');
            $expiry=trim((string)($data['reservation_expires_at']??''));$expiry=$expiry!==''?$this->cashReservationExpiry($expiry):($occupancy==='pending'?$this->cashReservationExpiry(''):null);
            $financialChange=$qty!==(int)$item['quantity']||$price!==(int)$item['unit_price_cents']||$typeId!==(int)$item['ticket_type_id'];
            if($this->reservationHolds($id)>0&&($financialChange||$expiry!==$o['reservation_expires_at']||$occupancy!==$o['order_status']))throw new RuntimeException('Hay un pago online en curso. Espera su confirmación antes de modificar plazas, importe o vigencia.',409);
            if($financialChange){$q=$this->pdo->prepare('SELECT COUNT(*) FROM tickets t JOIN ticket_order_items i ON i.id=t.order_item_id WHERE i.order_id=? AND t.entry_count>0');$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Ya se ha utilizado una entrada. Conserva las plazas y el importe de esta reserva.',409);if(!empty($o['holded_document_id']))throw new RuntimeException('La reserva ya tiene un documento fiscal. Debe gestionarse su rectificación antes de cambiar el importe.',409);}
            $wasActive=$this->reservationActive($o);$oldQty=$wasActive?(int)$item['quantity']:0;
            $this->lockEventPlaces($eventId,[['quantity'=>max(0,$qty-$oldQty)]]);
            $available=($o['inventory_mode']??'standard')==='manual_reserve'?$this->manualReserveAvailableForType($type):$this->standardAvailableForType($type);
            if($qty>$available+($typeId===(int)$item['ticket_type_id']?$oldQty:0))throw new RuntimeException('No hay suficientes plazas para el cambio solicitado.',409);
            if($typeId!==(int)$item['ticket_type_id'])$this->pdo->prepare('UPDATE tickets SET status="cancelled",updated_at=NOW() WHERE order_item_id=? AND status NOT IN ("cancelled","refunded")')->execute([$item['id']]);
            else { $q=$this->pdo->prepare('SELECT id FROM tickets WHERE order_item_id=? AND status NOT IN ("cancelled","refunded") ORDER BY id');$q->execute([$item['id']]);$ids=array_column($q->fetchAll(),'id');foreach(array_slice($ids,$qty) as $ticketId)$this->pdo->prepare('UPDATE tickets SET status="cancelled",updated_at=NOW() WHERE id=?')->execute([$ticketId]); }
            $this->pdo->prepare('UPDATE ticket_order_items SET ticket_type_id=?,ticket_type_name=?,quantity=?,unit_price_cents=?,unit_base_cents=?,unit_tax_cents=?,tax_rate=?,unit_fee_cents=?,reference_unit_price_cents=?,reference_total_cents=?,total_cents=? WHERE id=?')->execute([$typeId,$type['name'],$qty,$price,$base,$tax,$rate,$fee,$reference,$reference*$qty,$total,$item['id']]);
            $notes=clean_string((string)($data['notes']??''),1000);$this->pdo->prepare('UPDATE ticket_orders SET first_name=?,last_name=?,name=?,email=?,phone=?,subtotal_cents=?,total_cents=?,cash_payment_notes=?,status="pending",order_status=?,reservation_expires_at=?,updated_at=NOW() WHERE id=?')->execute([...$contact,$total,$total,$notes?:null,$occupancy==='confirmed'?'confirmed':'pending_payment',$expiry,$id]);
            $this->reservationCancelPendingLinks($id,'La reserva ha sido modificada');$this->reservationSync($id);$this->reservationAudit($actor,'reservation_updated',$id,['previous_quantity'=>$item['quantity'],'quantity'=>$qty,'previous_total_cents'=>$o['total_cents'],'total_cents'=>$total,'occupancy_status'=>$occupancy]);
        });return $this->adminReservationDetail($id);
    }

    public function adminCancelReservation(int $id,array $data,string $actor): array
    {
        $reason=clean_string((string)($data['reason']??''),1000);if(!$reason)throw new InvalidArgumentException('Indica el motivo de cancelación.');
        $this->reservationTransaction(function()use($id,$data,$actor,$reason){$o=$this->reservationLock($id);if($o['status']==='cancelled')return;$this->reservationVersion($o,$data);if($this->reservationHolds($id)>0)throw new RuntimeException('Hay un pago online en curso. Espera su confirmación antes de liberar las plazas.',409);
            $this->pdo->prepare('UPDATE ticket_orders SET status="cancelled",order_status="cancelled",reservation_revision=reservation_revision+1,updated_at=NOW() WHERE id=?')->execute([$id]);$this->pdo->prepare('UPDATE tickets t JOIN ticket_order_items i ON i.id=t.order_item_id SET t.status="cancelled",t.updated_at=NOW() WHERE i.order_id=?')->execute([$id]);$this->reservationCancelPendingLinks($id,'Reserva cancelada');$this->reservationAudit($actor,'reservation_cancelled',$id,['reason'=>$reason,'payments_preserved'=>true]);
        });return $this->adminReservationDetail($id);
    }

    private ?array $reservationRevenueCache = null;
    private function reservationRevenue(int $eventId): int
    {
        if(!$this->reservationSchemaAvailable())return 0;
        if($this->reservationRevenueCache===null){$q=$this->pdo->query('SELECT i.event_id,SUM(CASE WHEN p.kind="refund" THEN -CAST(p.amount_cents AS SIGNED) ELSE p.amount_cents END) amount FROM ticket_reservation_payments p JOIN ticket_orders o ON o.id=p.order_id JOIN (SELECT DISTINCT order_id,event_id FROM ticket_order_items) i ON i.order_id=o.id WHERE o.is_test=0 GROUP BY i.event_id');$this->reservationRevenueCache=array_column($q->fetchAll(),'amount','event_id');}
        return (int)($this->reservationRevenueCache[$eventId]??0);
    }

    private function reservationEventOrders(int $eventId): array
    {
        if(!$this->reservationSchemaAvailable())return [];
        $q=$this->pdo->prepare('SELECT o.*,(SELECT SUM(i.quantity) FROM ticket_order_items i WHERE i.order_id=o.id) ticket_quantity FROM ticket_orders o WHERE o.sales_channel="reservation" AND o.is_test=0 AND EXISTS (SELECT 1 FROM ticket_order_items i WHERE i.order_id=o.id AND i.event_id=?) ORDER BY o.created_at,o.id');$q->execute([$eventId]);return $this->reservationEnrichOrders($q->fetchAll());
    }

    private function reservationPaymentReport(int $eventId,array $legacy,array $totals): array
    {
        $labels=['cash'=>'Efectivo','card_present'=>'Tarjeta presencial','card_online'=>'Tarjeta online','bank_transfer'=>'Transferencia','other'=>'Otros'];
        foreach($this->reservationEventOrders($eventId) as $o){if((int)$o['received_cents']===0)continue;$parts=[];foreach($o['payments_by_method'] as $method=>$amount){if(!$amount)continue;$parts[]=($labels[$method]??$method).' '.number_format($amount/100,2,',','.').' €';$key=match($method){'cash'=>'cash_cents','card_present','card_online'=>'card_cents','bank_transfer'=>'bank_transfer_cents',default=>'other_cents'};$totals[$key]=($totals[$key]??0)+(int)$amount;if(in_array($method,['card_present','card_online'],true))$totals[$method.'_cents']=($totals[$method.'_cents']??0)+(int)$amount;}
            $legacy[]=['order_id'=>$o['id'],'customer_name'=>$o['name'],'order_reference'=>$o['redsys_order'],'ticket_quantity'=>$o['ticket_quantity'],'total_cents'=>$o['paid_cents'],'reservation_total_cents'=>$o['total_cents'],'outstanding_cents'=>$o['outstanding_cents'],'payment_method'=>count($parts)>1?'mixed':(array_key_first($o['payments_by_method'])??'other'),'notes'=>implode(' · ',$parts).' · Pendiente '.number_format($o['outstanding_cents']/100,2,',','.').' €'.($o['status']==='cancelled'?' · Reserva cancelada':'')];$totals['total_cents']+=(int)$o['paid_cents'];
        }return ['orders'=>$legacy,'totals'=>$totals];
    }

}
