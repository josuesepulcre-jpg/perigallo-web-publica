<?php
require dirname(__DIR__).'/api/src/TicketDocumentService.php';
class DesignStatement extends PDOStatement {
    public array $parameters=[];
    public function execute(?array $params=null): bool { $this->parameters=$params; return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
        if($this->parameters[2]!=='v4b-invitacion-20261108')throw new RuntimeException('Old cached design selected');
        return ['id'=>4,'filename'=>'approved.pdf','content'=>'%PDF-test','content_type'=>'application/pdf','content_sha256'=>hash('sha256','%PDF-test')];
    }
}
class DesignPDO extends PDO {
    public array $queries=[];
    public DesignStatement $statement;
    public function __construct() { $this->statement=new DesignStatement(); }
    public function prepare(string $query,array $options=[]): PDOStatement|false { $this->queries[]=$query;return $this->statement; }
}
function check(bool $condition): void { if (!$condition) throw new RuntimeException('Approved document cache regression'); }
$db=new DesignPDO();$service=new \Perigallo\Ticketing\TicketDocumentService();
$document=$service->ensureOrderDocument($db,42);
check($document['filename']==='approved.pdf');
check(count($db->queries)===1);
check($db->statement->parameters===[42,'tickets','v4b-invitacion-20261108']);
check(\Perigallo\Ticketing\TicketDocumentService::DOCUMENT_VERSION==='v2-attendee');
echo "PASS: approved design cache selected, current PDF reused, delivery deduplication version unchanged.\n";
