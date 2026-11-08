<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
use Perigallo\Ticketing\Database;use Perigallo\Ticketing\Ticketing;use Perigallo\Ticketing\Redsys;use Perigallo\Ticketing\Mailer;use Perigallo\Ticketing\PublicEventCache;
$cfg=json_decode(file_get_contents(dirname(__DIR__).'/src/season-catalog.json'),true,512,JSON_THROW_ON_ERROR);
$content=json_decode(file_get_contents(dirname(__DIR__).'/src/season-event-content.json'),true,512,JSON_THROW_ON_ERROR);
if(($cfg['max_capacity']??0)!==100)throw new RuntimeException('Revisar aforo aprobado');
$apply=in_array('--apply',$argv,true);$pdo=Database::pdo();$ticketing=new Ticketing($pdo,new Redsys(),new Mailer());
foreach($cfg['plans'] as $plan=>$spec){
 $eventData=$content[$plan]+['capacity'=>100,'is_test'=>false,'location'=>'Finca La Llaguna','locality'=>'Crevillent','province'=>'Alicante','sale_starts_at'=>'2026-11-08 00:00:00','sale_ends_at'=>$content[$plan]['starts_at']];
 $q=$pdo->prepare('SELECT id,is_test,capacity FROM events WHERE slug=? LIMIT 1');$q->execute([$spec['slug']]);$existing=$q->fetch();
 if(!$apply){echo $plan.': '.($existing?'actualizar':'crear').', máximo 100 personas, sin actuación de Navidad'.PHP_EOL;continue;}
 if($existing&&!empty($existing['is_test']))throw new RuntimeException('No se convierte un evento de prueba en real.');
 $event=$existing?$ticketing->adminGetEvent((int)$existing['id']):$ticketing->adminCreateEvent($eventData+['slug'=>$spec['slug']]);
 $id=(int)$event['id'];$ticketing->adminUpdateEvent($id,$eventData);
 $event=$ticketing->adminGetEvent($id);$types=$event['ticket_types']??[];
 foreach(['adult','child'] as $kind){if($spec[$kind]===null)continue;$name=$kind==='child'?'Menú infantil':($plan==='hora'?'Entrada general · Hora Cero':'Menú de adultos');$found=null;
  foreach($types as $type){if(($kind==='child')===(bool)preg_match('/infantil/iu',$type['name'])){$found=$type;break;}}
  $price=$spec[$kind];$base=(int)round($price/1.10);if($base+(int)round($base*.10)!==$price)throw new RuntimeException('Desglose de precio no exacto');
  $data=['name'=>$name,'description'=>$kind==='child'?'Menú infantil · Agua y refrescos incluidos.':$content[$plan]['ticket_description'],'price_cents'=>$base,'tax_rate'=>10,'fee_cents'=>0,'capacity'=>100,'manual_reserve_capacity'=>0,'max_per_order'=>10,'active'=>true,'visible'=>true,'status'=>'on_sale','sort_order'=>$kind==='child'?20:10,'sale_starts_at'=>'2026-11-08 00:00:00','sale_ends_at'=>$eventData['starts_at']];
  if($found)$ticketing->adminUpdateTicketType($id,(int)$found['id'],$data);else $ticketing->adminCreateTicketType($id,$data);
 }
 $ticketing->adminSetEventPublication($id,true);echo $plan.': publicado, id '.$id.', máximo total 100'.PHP_EOL;
}
