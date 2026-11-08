<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;
use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Public seasonal reservations backed by the existing event/order/payment system. */
final class SeasonReservations
{
    public function __construct(private PDO $pdo, private Ticketing $ticketing) {}
    private function config(): array { return json_decode(file_get_contents(__DIR__.'/season-catalog.json'),true,512,JSON_THROW_ON_ERROR); }
    private function event(string $plan): array {
        $entry=$this->config()['plans'][$plan]??null;
        if(!$entry)throw new InvalidArgumentException('Elige una experiencia disponible.');
        $q=$this->pdo->prepare('SELECT * FROM events WHERE slug=? AND status="published" AND visible=1 AND is_test=0 LIMIT 1');$q->execute([$entry['slug']]);$event=$q->fetch();
        if(!$event||strtotime($event['starts_at'])<=time())throw new RuntimeException('Esta experiencia no admite reservas en este momento.');
        $q=$this->pdo->prepare('SELECT * FROM ticket_types WHERE event_id=? AND active=1 AND visible=1 AND status="on_sale" ORDER BY sort_order,id');$q->execute([$event['id']]);$types=$q->fetchAll();
        $adult=null;$child=null;
        foreach($types as $type){if(preg_match('/infantil/iu',$type['name']))$child=$type;else if(!$adult)$adult=$type;}
        if(!$adult||($entry['child']!==null&&!$child))throw new RuntimeException('Las entradas todavía no están disponibles.');
        return [$event,$adult,$child];
    }
    private function gross(array $type): int {return (int)$type['price_cents']+(int)round($type['price_cents']*$type['tax_rate']/100)+(int)$type['fee_cents'];}
    public function catalog(): array {
        $out=[];
        foreach($this->config()['plans'] as $plan=>$cfg){[$e,$a,$c]=$this->event($plan);$out[$plan]=['title'=>$e['title'],'date'=>substr($e['starts_at'],0,10),'price'=>$this->gross($a),'child'=>$c?$this->gross($c):null];}
        return ['plans'=>$out];
    }
    public function create(array $data): array {
        if(($_SERVER['CONTENT_LENGTH']??0)>12000)throw new InvalidArgumentException('La solicitud es demasiado grande.');
        $origin=rtrim((string)($_SERVER['HTTP_ORIGIN']??''),'/');
        if($origin!==''&&!hash_equals(app_base_url(),$origin))throw new RuntimeException('Origen de solicitud no permitido.');
        foreach(['first_name','last_name','phone','email'] as $key)if(!is_string($data[$key]??null)||trim($data[$key])==='')throw new InvalidArgumentException('Completa nombre, apellidos, teléfono y correo electrónico.');
        $first=clean_string($data['first_name'],120);$last=clean_string($data['last_name'],160);$email=mb_strtolower(trim($data['email']));$phone=clean_string($data['phone'],30);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new InvalidArgumentException('Introduce un correo electrónico válido.');
        $digits=preg_replace('/\D/','',$phone);if(strlen($digits)<9||strlen($digits)>15)throw new InvalidArgumentException('Introduce un teléfono válido.');
        if(empty($data['privacy'])||empty($data['terms'])||empty($data['adult_buyer']))throw new InvalidArgumentException('Acepta la privacidad, las condiciones y confirma que eres mayor de edad.');
        foreach(['adults','children'] as $key)if(!is_int($data[$key]??null)||$data[$key]<($key==='adults'?1:0)||$data[$key]>10)throw new InvalidArgumentException('Puedes reservar hasta 10 plazas en una solicitud. Para grupos mayores, contacta con nosotros.');
        if($data['adults']+$data['children']>10)throw new InvalidArgumentException('Puedes reservar hasta 10 plazas en una solicitud.');
        if(!in_array($data['mode']??'', ['reserve','pay'],true))throw new InvalidArgumentException('Selecciona cómo quieres reservar.');
        $operation=(string)($data['operation']??'');if(!preg_match('/^[A-Za-z0-9_-]{20,100}$/',$operation))throw new InvalidArgumentException('Recarga la página para volver a intentarlo.');
        [$event,$adult,$child]=$this->event((string)($data['plan']??''));
        if($data['children']&&!$child)throw new InvalidArgumentException('Esta experiencia tiene una única modalidad de entrada.');
        $wa=preg_replace('/[\s().-]+/','',$phone);if(str_starts_with($wa,'00'))$wa='+'.substr($wa,2);if(preg_match('/^[6-9][0-9]{8}$/',$wa))$wa='+34'.$wa;
        $consent=($data['whatsapp_consent']??false)===true;if($consent&&!preg_match('/^\+[1-9][0-9]{7,14}$/',$wa))throw new InvalidArgumentException('Para WhatsApp, añade el prefijo internacional al teléfono.');
        $notes=clean_string((string)($data['notes']??''),800);
        $fingerprint=hash('sha256',json_encode([$event['id'],$first,$last,$email,$phone,$data['adults'],$data['children'],$data['mode'],$consent,$notes]));
        $marker='[season-op:'.$operation.'][request:'.$fingerprint.']';$lock='season:'.hash('sha256',$operation);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if(!$q->fetchColumn())throw new RuntimeException('Tu reserva se está procesando. Espera unos segundos.');
        try{
            $q=$this->pdo->prepare('SELECT id,cash_payment_notes,public_token FROM ticket_orders WHERE cash_payment_notes LIKE ? ORDER BY id LIMIT 1');$q->execute(['[season-op:'.$operation.']%']);$existing=$q->fetch();
            if($existing){if(!str_starts_with((string)$existing['cash_payment_notes'],$marker))throw new InvalidArgumentException('La solicitud ya existe con otros datos. Recarga la página.');return ['booking'=>$this->booking($existing['public_token'])];}
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM ticket_orders WHERE ip_address=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR) AND cash_payment_notes LIKE "[season-op:%"');$q->execute([client_ip()]);if((int)$q->fetchColumn()>=12)throw new RuntimeException('Se han recibido varias solicitudes. Contacta con nosotros para continuar.');
            $items=[['ticket_type_id'=>(int)$adult['id'],'quantity'=>$data['adults']]];if($data['children'])$items[]=['ticket_type_id'=>(int)$child['id'],'quantity'=>$data['children']];
            $result=$this->ticketing->adminCreateCashOrder(['event_id'=>$event['id'],'first_name'=>$first,'last_name'=>$last,'email'=>$email,'phone'=>$phone,'items'=>$items,'payment_flow'=>'manual_card','cash_payment_status'=>'reserved','cash_payment_notes'=>$marker.' '.$notes,'reservation_expires_at'=>(new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s')],'Web · Experiencias diciembre');
            $id=(int)$result['order']['id'];
            $q=$this->pdo->prepare('UPDATE ticket_orders SET whatsapp_phone_input=?,whatsapp_phone_e164=?,whatsapp_country_code=?,whatsapp_consent=?,whatsapp_consent_at=IF(?=1,NOW(),NULL),whatsapp_consent_source=?,whatsapp_consent_version=? WHERE id=? AND payment_status="pending"');
            $q->execute([$phone,$consent?$wa:null,str_starts_with($wa,'+34')?'ES':'ZZ',$consent?1:0,$consent?1:0,$consent?'seasonal_manual_business':null,$consent?'manual_tickets_v1':null,$id]);
            return ['booking'=>$this->booking($result['order']['public_token'])];
        } finally {$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }
    public function booking(string $token): array {
        $q=$this->pdo->prepare('SELECT o.*,e.slug,e.title,e.starts_at FROM ticket_orders o JOIN ticket_order_items i ON i.order_id=o.id JOIN events e ON e.id=i.event_id WHERE o.public_token=? AND o.cash_payment_notes LIKE "[season-op:%" ORDER BY i.id LIMIT 1');$q->execute([$token]);$row=$q->fetch();if(!$row)throw new RuntimeException('Reserva no encontrada.');
        $plan=null;foreach($this->config()['plans'] as $key=>$cfg)if($cfg['slug']===$row['slug'])$plan=$key;if(!$plan)throw new RuntimeException('Reserva no encontrada.');
        $o=$this->ticketing->getOrderByToken($token);if(!$o)throw new RuntimeException('Reserva no encontrada.');
        $q=$this->pdo->prepare('SELECT ticket_type_name,quantity FROM ticket_order_items WHERE order_id=?');$q->execute([$row['id']]);$adults=0;$children=0;foreach($q->fetchAll() as $item){if(preg_match('/infantil/iu',$item['ticket_type_name']))$children+=(int)$item['quantity'];else $adults+=(int)$item['quantity'];}
        return ['token'=>$token,'title'=>$row['title'],'date'=>substr($row['starts_at'],0,10),'plan'=>$plan,'mode'=>'reserve','name'=>$row['name'],'phone'=>$row['phone'],'email'=>$row['email'],'notes'=>trim(preg_replace('/^\[season-op:[^]]+\]\[request:[^]]+\]/','',(string)$row['cash_payment_notes'])),'adults'=>$adults,'children'=>$children,'total'=>(int)$row['total_cents'],'reference'=>$o['reference'],'payment_status'=>$o['payment_status'],'state'=>$o['payment_status']==='paid'?'paid':(!empty($o['can_pay_by_card'])?'pending_payment':$o['status']),'expires'=>$row['reservation_expires_at'],'tickets'=>$o['tickets']??[]];
    }
    public function payment(string $token): array {$this->booking($token);return ['payment'=>$this->ticketing->startManualCardPayment($token)];}
}
