<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/** Internal holds use the same order, event lock, inventory and Redsys flow as sales. */
trait InternalReservationsTrait
{
    private ?bool $internalReservationSchema = null;

    private function internalReservationSchemaAvailable(): bool
    {
        if ($this->internalReservationSchema === null) {
            $this->internalReservationSchema = (bool) $this->pdo->query("SHOW COLUMNS FROM ticket_orders LIKE 'internal_reservation_event_id'")->fetch();
        }
        return $this->internalReservationSchema;
    }

    private function requireInternalReservationSchema(): void
    {
        if (!$this->internalReservationSchemaAvailable()) throw new RuntimeException('Las reservas internas requieren aplicar la migración 032 antes de utilizar esta pantalla.');
    }

    public function adminInternalReservationEvents(): array
    {
        return $this->pdo->query('SELECT id,title,starts_at,ends_at,status,is_test FROM events WHERE status <> "archived" ORDER BY starts_at DESC,id DESC')->fetchAll();
    }

    private function internalReservationStateSql(): string
    {
        return 'CASE WHEN o.status IN ("cancelled","refunded") THEN "cancelled" WHEN o.status="manual_review" THEN "review" WHEN o.sales_channel="cash" AND EXISTS (SELECT 1 FROM payment_attempts irp WHERE irp.order_id=o.id AND irp.status="accepted") THEN "review" WHEN o.status="paid" OR o.order_status="confirmed" THEN "confirmed" WHEN o.order_status="expired" OR o.reservation_expires_at<=NOW() THEN "expired" WHEN o.order_status="draft" THEN "held" ELSE "payment_requested" END';
    }

    private function internalReservationInventory(int $eventId, bool $types = false): array
    {
        $event = $this->requireAdminEvent($eventId);
        $metrics = $this->eventMetrics($eventId);
        $out = ['id'=>$eventId,'title'=>$event['title'],'starts_at'=>$event['starts_at'],'ends_at'=>$event['ends_at'],'status'=>$event['status'],'is_test'=>(bool)$event['is_test'],'capacity'=>(int)$event['capacity'],'sold'=>$metrics['sold'],'reserved'=>$metrics['reserved'],'available'=>max(0,(int)$event['capacity']-$metrics['sold']-$metrics['reserved'])];
        if ($types) {
            $q=$this->pdo->prepare('SELECT * FROM ticket_types WHERE event_id=? AND status NOT IN ("draft","archived") ORDER BY sort_order,id');$q->execute([$eventId]);
            $out['ticket_types']=array_map(fn(array $row)=>$this->adminTicketTypeRow($row),$q->fetchAll());
        }
        return $out;
    }

    private function internalReservationSelect(): string
    {
        return 'SELECT o.id,o.public_token,o.redsys_order,o.first_name,o.last_name,o.name,o.email,o.phone,o.status,o.order_status,o.payment_status,o.sales_channel,o.cash_payment_notes AS notes,o.reservation_expires_at,o.reservation_payment_requested_at,o.internal_reservation_event_id AS event_id,o.created_at,o.updated_at,o.total_cents,
        (SELECT COALESCE(SUM(i.quantity),0) FROM ticket_order_items i WHERE i.order_id=o.id) AS quantity,
        '.$this->internalReservationStateSql().' AS reservation_state FROM ticket_orders o';
    }

    private function internalReservationRow(int $id): array
    {
        $q=$this->pdo->prepare($this->internalReservationSelect().' WHERE o.id=? AND o.internal_reservation_event_id IS NOT NULL');$q->execute([$id]);
        $row=$q->fetch();if(!$row)throw new RuntimeException('Reserva interna no encontrada.');
        foreach(['id','event_id','quantity','total_cents'] as $field)$row[$field]=(int)$row[$field];
        $row['payment_url']=$row['reservation_state']==='payment_requested'&&$row['sales_channel']==='manual_card'&&$row['total_cents']>0?$this->manualCardPaymentUrl($row['public_token']):null;
        $row['ticket_url']=$row['reservation_state']==='confirmed'?app_base_url().'/entradas/pedido/?token='.rawurlencode($row['public_token']):null;
        $q=$this->pdo->prepare('SELECT ticket_type_id,ticket_type_name,quantity,unit_price_cents FROM ticket_order_items WHERE order_id=? ORDER BY id');$q->execute([$id]);$row['items']=$q->fetchAll();
        return $row;
    }

    private function internalReservationResult(int $id): array
    {
        $row=$this->internalReservationRow($id);
        return ['reservation'=>$row,'event'=>$this->internalReservationInventory($row['event_id'],true)];
    }

    public function adminInternalReservations(int $eventId, array $filters): array
    {
        $this->requireInternalReservationSchema();
        $page=max(1,(int)($filters['page']??1));$limit=20;
        $state=(string)($filters['state']??'all');
        if(!in_array($state,['all','held','payment_requested','confirmed','cancelled','expired','review'],true))throw new InvalidArgumentException('Estado de reserva no válido.');
        $where=' WHERE o.internal_reservation_event_id=?';$params=[$eventId];
        if($state!=='all'){$where.=' AND ('.$this->internalReservationStateSql().')=?';$params[]=$state;}
        $query=clean_string((string)($filters['q']??''),120);
        if($query!==''){$where.=' AND (o.name LIKE ? OR o.phone LIKE ? OR o.email LIKE ? OR o.redsys_order LIKE ?)';for($i=0;$i<4;$i++)$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';$where=str_replace(' LIKE ?',' LIKE ? ESCAPE \'!\'',$where);}
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM ticket_orders o'.$where);$q->execute($params);$total=(int)$q->fetchColumn();$page=min($page,max(1,(int)ceil($total/$limit)));
        $q=$this->pdo->prepare($this->internalReservationSelect().$where.' ORDER BY o.id DESC LIMIT '.$limit.' OFFSET '.(($page-1)*$limit));$q->execute($params);$rows=$q->fetchAll();
        foreach($rows as &$row){foreach(['id','event_id','quantity','total_cents'] as $field)$row[$field]=(int)$row[$field];}unset($row);
        return ['reservations'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit)),'event'=>$this->internalReservationInventory($eventId,!empty($filters['meta']))];
    }

    public function adminInternalReservation(int $id): array
    {
        $this->requireInternalReservationSchema();return $this->internalReservationResult($id);
    }

    public function adminCreateInternalReservation(int $eventId, array $data, string $operator): array
    {
        $this->requireInternalReservationSchema();
        $key=(string)($data['operation_key']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{20,80}$/D',$key))throw new InvalidArgumentException('Recarga el formulario de reserva y vuelve a intentarlo.');
        $email=trim((string)($data['email']??''));
        if($email!==''&&(strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('El correo electrónico no es válido.');
        $phone=preg_replace('/\D/','',(string)($data['phone']??''));
        if(strlen($phone)<9||strlen($phone)>15||!preg_match('/^[+0-9\s().-]+$/uD',(string)($data['phone']??'')))throw new InvalidArgumentException('Introduce un teléfono válido.');
        if(trim((string)($data['first_name']??''))==='')throw new InvalidArgumentException('Indica el nombre de la persona de contacto.');
        $event=$this->requireAdminEvent($eventId);
        if(in_array($event['status'],['cancelled','finished','archived'],true))throw new InvalidArgumentException('Este evento no admite nuevas reservas.');
        $eventEnd=new DateTimeImmutable($event['ends_at']?:$event['starts_at']);
        if($eventEnd->getTimestamp()<=time())throw new InvalidArgumentException('Este evento ya ha finalizado.');
        $items=$data['items']??null;
        if(!is_array($items)||count($items)<1||count($items)>30)throw new InvalidArgumentException('Selecciona al menos un tipo de entrada.');
        $seen=[];
        foreach($items as $item){
            if(!is_array($item)||!is_int($item['ticket_type_id']??null)||!is_int($item['quantity']??null)||$item['quantity']<1||$item['quantity']>1000||isset($seen[$item['ticket_type_id']]))throw new InvalidArgumentException('Revisa los tipos de entrada y las cantidades de la reserva.');
            $seen[$item['ticket_type_id']]=true;
        }
        $payload=['event_id'=>$eventId,'first_name'=>clean_string((string)($data['first_name']??''),120),'last_name'=>clean_string((string)($data['last_name']??''),160),'phone'=>clean_string((string)($data['phone']??''),60),'email'=>mb_strtolower($email),'items'=>$items,'payment_flow'=>'manual_card','inventory_mode'=>'cash','cash_payment_status'=>'reserved','cash_payment_notes'=>clean_string((string)($data['notes']??''),1000),'reservation_expires_at'=>trim((string)($data['reservation_expires_at']??''))?:$eventEnd->modify('+1 day')->format('Y-m-d H:i:s')];
        $hash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        // A retried save returns the same durable hold, rather than taking its seats twice.
        $lock='ir:'.substr(hash('sha256',$key),0,60);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('La reserva se está guardando. Vuelve a consultar en unos segundos.');
        try{
            $q=$this->pdo->prepare('SELECT id,reservation_request_hash FROM ticket_orders WHERE reservation_operation_key=?');$q->execute([$key]);$existing=$q->fetch();
            if($existing){if(!hash_equals($existing['reservation_request_hash'],$hash))throw new InvalidArgumentException('Esta solicitud ya se guardó con otros datos. Abre una nueva reserva.');return $this->internalReservationResult((int)$existing['id']);}
            $result=$this->adminCreateCashOrder($payload,$operator,['event_id'=>$eventId,'key'=>$key,'hash'=>$hash]);
            return $this->internalReservationResult((int)$result['order']['id']);
        }finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }

    public function adminRequestInternalReservationPayment(int $id,string $operator): array
    {
        $this->requireInternalReservationSchema();$this->pdo->beginTransaction();
        try{
            $order=$this->lockedOrder($id);$this->requireActiveInternalReservation($order);
            if($order['sales_channel']!=='manual_card'||$order['order_status']==='confirmed')throw new RuntimeException('Esta reserva ya está confirmada.');
            if((int)$order['total_cents']<=0)throw new RuntimeException('Esta reserva no tiene un importe que cobrar. Confírmala como entrada sin importe.');
            if($order['status']==='pending'){
                $this->pdo->prepare('UPDATE ticket_orders SET order_status="pending_payment",reservation_payment_requested_at=COALESCE(reservation_payment_requested_at,NOW()),updated_at=NOW() WHERE id=?')->execute([$id]);
                $this->auditAdminOperation($operator,'internal_reservation_payment_prepared',$id,[]);
            }
            $this->pdo->commit();return $this->internalReservationResult($id);
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function requireActiveInternalReservation(array $order): void
    {
        if(empty($order['internal_reservation_event_id']))throw new RuntimeException('Reserva interna no encontrada.');
        if($order['status']==='manual_review')throw new RuntimeException('Se ha recibido un cobro que requiere revisar el aforo. Consulta Pedidos y ventas.');
        if(in_array($order['status'],['cancelled','refunded'],true))throw new RuntimeException('La reserva ya está cancelada.');
        if($order['order_status']==='expired'||(!empty($order['reservation_expires_at'])&&strtotime($order['reservation_expires_at'])<=time()))throw new RuntimeException('La reserva ha caducado y ya no bloquea plazas. Crea una nueva reserva para comprobar el aforo.');
    }

    public function adminConfirmInternalReservation(int $id,array $data,string $operator): array
    {
        $this->requireInternalReservationSchema();$mode=(string)($data['mode']??'');
        if(!in_array($mode,['door','cash','free'],true))throw new InvalidArgumentException('Indica si se cobrará en puerta, ya se ha cobrado en efectivo o no tiene importe.');
        $this->pdo->beginTransaction();
        try{
            $order=$this->lockedOrder($id);$this->requireActiveInternalReservation($order);
            if($order['status']==='payment_processing')throw new RuntimeException('Hay un pago con tarjeta en curso. Espera su resultado antes de cambiar la forma de cobro.');
            if($order['status']==='paid'||$order['order_status']==='confirmed'){
                $expectedPaid=$mode==='cash'||$mode==='free';
                if(($order['status']==='paid')!==$expectedPaid)throw new RuntimeException('El estado ya cambió. Actualiza la ficha para registrar el cobro o consultar la confirmación.');
                $this->pdo->commit();return $this->internalReservationResult($id);
            }
            if($mode==='free'&&(int)$order['total_cents']!==0)throw new InvalidArgumentException('Esta reserva tiene un importe pendiente.');
            if($mode==='cash'&&(int)$order['total_cents']===0)throw new InvalidArgumentException('Utiliza la confirmación sin importe.');
            $paid=$mode!=='door';$notes=clean_string((string)($data['notes']??$order['cash_payment_notes']),1000);
            $this->pdo->prepare('UPDATE ticket_orders SET sales_channel="cash",status=?,order_status="confirmed",payment_status=?,cash_payment_status=?,cash_payment_notes=?,cash_payment_recorded_by=?,cash_payment_recorded_at=IF(?=1,NOW(),NULL),paid_at=IF(?=1,NOW(),NULL),reservation_expires_at=NULL,delivery_status="generated",holded_status="not_required",holded_excluded=1,holded_exclusion_reason="cash_sale",holded_next_attempt_at=NULL,holded_last_error=NULL,updated_at=NOW() WHERE id=?')->execute([$paid?'paid':'pending',$paid?'paid':'pending',$paid?'paid':'reserved',$notes?:null,$paid?$operator:null,$paid?1:0,$paid?1:0,$id]);
            $this->generateTicketsOnce($id,'issued');
            $this->auditAdminOperation($operator,'internal_reservation_confirmed',$id,['mode'=>$mode]);
            $this->pdo->commit();return $this->internalReservationResult($id);
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function adminEditInternalReservation(int $id,array $data,string $operator): array
    {
        $this->requireInternalReservationSchema();$this->internalReservationRow($id);
        $data['cash_payment_notes']=$data['notes']??'';
        $this->adminUpdateOrderContact($id,$data,$operator);
        return $this->internalReservationResult($id);
    }

    public function adminCancelInternalReservation(int $id,string $operator,string $reason): array
    {
        $this->requireInternalReservationSchema();$row=$this->internalReservationRow($id);
        if(in_array($row['reservation_state'],['confirmed','review'],true))throw new RuntimeException('Esta reserva ya está confirmada. Gestiona su cancelación desde Pedidos y ventas.');
        $this->changeOrderCommercialStatus($id,$operator,'cancelled',$reason,true);
        return $this->internalReservationResult($id);
    }
}
