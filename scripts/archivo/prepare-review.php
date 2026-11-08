<?php
require dirname(__DIR__,2).'/api/src/bootstrap.php';
use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\Ticketing;
use Perigallo\Ticketing\Mailer;
use Perigallo\Ticketing\Redsys;
if (env_value('DB_DATABASE')!=='perigallo_archivo_local') exit(1);
$p=Database::pdo();$p->exec("UPDATE events SET status='draft',is_test=1 WHERE is_test=1 OR slug LIKE 'revision-unificacion-%'");$p->exec("DELETE FROM experience_archive WHERE slug LIKE 'archivo-test-%'");
$found=$p->query("SELECT id FROM events WHERE slug='hora-cero-2026'")->fetchColumn();
if (!$found) {
 $data=json_decode(file_get_contents(dirname(__DIR__,3).'/archivo-hora-cero-fixture.json'),true);$data['is_test']=true;
 $t=new Ticketing($p,new Redsys(),new Mailer());$event=$t->adminCreateEvent($data);
 $t->adminCreateTicketType($event['id'],['name'=>'Entrada general · Hora Cero','price_cents'=>12000,'capacity'=>40,'status'=>'on_sale','visible'=>true,'sale_starts_at'=>'2026-01-01 00:00:00','sale_ends_at'=>'2026-12-30 23:00:00']);
 $found=$event['id'];
}
$p->prepare("UPDATE events SET status='published',visible=1,unlisted=0,link_only=0 WHERE id=?")->execute([$found]);
echo "Local review fixture ready; no customer data imported.\n";
