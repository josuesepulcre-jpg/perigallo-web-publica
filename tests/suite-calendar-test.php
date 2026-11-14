<?php
declare(strict_types=1);
require __DIR__.'/../api/src/SuiteCalendar.php';
use Perigallo\Ticketing\SuiteCalendar;
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$path=tempnam(sys_get_temp_dir(),'suite-calendar-test-');
$reader=new SuiteCalendar($path);$now=1794610000;
$event=['id'=>'suite:wedding:123','source'=>'suite','title'=>'Boda','client_name'=>'Ejemplo','starts_at'=>'2027-01-02 14:00:00','status'=>'suite_pending','people'=>30,'suite_path'=>'/bodas/123','time_defined'=>true,'phone'=>'must-not-leak'];
$snapshot=['version'=>1,'complete'=>true,'generated_at'=>gmdate('c',$now),'events'=>[$event]];
try {
 file_put_contents($path,'invalid');check($reader->read($now)['sync']['status']==='unavailable','Invalid JSON denied');
 file_put_contents($path,json_encode($snapshot));$result=$reader->read($now);check(count($result['events'])===1,'Current event returned');check(!isset($result['events'][0]['phone']),'Whitelist removes extra personal data');
 check($reader->read($now+181)['sync']['status']==='stale','Stale snapshot labelled');check(count($reader->read($now+181)['events'])===1,'Stale data retained');
 foreach (['duplicate','partial','unsafe_path','invalid_date','invalid_status','future'] as $case) {
  $bad=$snapshot;
  if($case==='duplicate')$bad['events'][]=$event;
  if($case==='partial')$bad['complete']=false;
  if($case==='unsafe_path')$bad['events'][0]['suite_path']='//evil.example';
  if($case==='invalid_date')$bad['events'][0]['starts_at']='2027-02-31 14:00:00';
  if($case==='invalid_status')$bad['events'][0]['status']='cancelled';
  if($case==='future')$bad['generated_at']=gmdate('c',$now+400);
  file_put_contents($path,json_encode($bad));check($reader->read($now)['sync']['status']==='unavailable','Failed closed: '.$case);
 }
 echo "Suite calendar: whitelist, invalid/partial snapshots, stale data, dates and links passed.\n";
}finally{unlink($path);}
