<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;
use PDO;
use PDOStatement;
use RuntimeException;
function env_value(string $key, ?string $default=null): ?string {return $default;}
function mail(string $to,string $subject,string $message,string $headers): bool {
    $GLOBALS['synthetic_mail_calls']++;
    $outcome=$GLOBALS['synthetic_mail_outcome'];
    if ($outcome==='unknown') throw new RuntimeException('Synthetic transport interruption');
    return $outcome;
}
final class TicketDeliveryDiagnostics {public static function log(array $value):void {} public static function error(\Throwable $error,string $where,array $context=[]):array {return [];}}
final class MemoryDeliveryPDO extends PDO {
    public function __construct(public array $row=[]) {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {return new MemoryDeliveryStatement($this,$query);}
}
final class MemoryDeliveryStatement extends PDOStatement {
    private mixed $value=false;private int $affected=0;
    public function __construct(private MemoryDeliveryPDO $db,private string $sql) {}
    public function execute(?array $params=null):bool {
        $this->affected=0;$r=&$this->db->row;
        if(str_starts_with($this->sql,'SELECT status'))$this->value=$r['status']??false;
        elseif(str_starts_with($this->sql,'SELECT id'))$this->value=$r['id']??false;
        elseif(str_starts_with($this->sql,'INSERT INTO email_deliveries')){$r=['id'=>1,'status'=>'pending','error'=>null];$this->affected=1;}
        elseif(str_contains($this->sql,'AND error_message = "mail_not_accepted"')){if(($r['status']??'')==='failed'&&($r['error']??'')==='mail_not_accepted'){$r['status']='pending';$r['error']=null;$this->affected=1;}}
        elseif(str_starts_with($this->sql,'UPDATE email_deliveries SET status = ?')){$r['status']=$params[0];$r['error']=$params[1];$this->affected=1;}
        else throw new RuntimeException('Unexpected synthetic SQL');
        return true;
    }
    public function fetchColumn(int $column=0):mixed {return $this->value;}
    public function rowCount():int {return $this->affected;}
}
require __DIR__.'/../api/src/TicketDocumentService.php';
require __DIR__.'/../api/src/Mailer.php';
function expect(bool $value,string $label):void {if(!$value)throw new RuntimeException($label);}
function send(MemoryDeliveryPDO $db):string {return (new Mailer())->sendTicketDocumentEmail($db,1,'qa@example.invalid','Synthetic test','Synthetic body','<p>Synthetic</p>',['filename'=>'synthetic.pdf','content'=>'%PDF-synthetic'],'qa-idempotency-key');}
$GLOBALS['synthetic_mail_calls']=0;$GLOBALS['synthetic_mail_outcome']=true;
foreach(['pending','sent'] as $status){$db=new MemoryDeliveryPDO(['id'=>1,'status'=>$status]);expect(send($db)===$status,'Existing outcome must be retained');expect($GLOBALS['synthetic_mail_calls']===0,'Existing outcome must not send');}
$db=new MemoryDeliveryPDO(['id'=>1,'status'=>'failed','error'=>'requires_review:unknown']);expect(send($db)==='pending','Ambiguous failure must be held');expect($GLOBALS['synthetic_mail_calls']===0,'Ambiguous failure must not send');
$db=new MemoryDeliveryPDO(['id'=>1,'status'=>'failed','error'=>'mail_not_accepted']);expect(send($db)==='sent','Definite rejection may be retried');expect($GLOBALS['synthetic_mail_calls']===1,'Exactly one synthetic retry');expect(send($db)==='sent'&&$GLOBALS['synthetic_mail_calls']===1,'Repeated accepted retry must be idempotent');
$GLOBALS['synthetic_mail_outcome']='unknown';$db=new MemoryDeliveryPDO();expect(send($db)==='pending','Unknown acceptance must require review');expect($db->row['error']==='requires_review:mail_outcome_unknown','Unknown acceptance reason');$count=$GLOBALS['synthetic_mail_calls'];expect(send($db)==='pending'&&$GLOBALS['synthetic_mail_calls']===$count,'Unknown acceptance must never be retried automatically');
fwrite(STDOUT,"Ticket mail idempotency: synthetic namespace transport only; 6 scenarios passed; 0 real messages.\n");
