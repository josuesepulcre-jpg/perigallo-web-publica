<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/src/bootstrap.php';
use Perigallo\Ticketing\Redsys;

if (env_value('APP_ENV') !== 'staging' || env_value('DB_DATABASE') !== 'perigallo_unificacion_staging') exit(1);
$input=json_decode(stream_get_contents(STDIN),true);
$r=new Redsys();
$p=['DS_ORDER'=>$input['reference'],'DS_AMOUNT'=>(string)$input['amount'],'DS_CURRENCY'=>'978','DS_MERCHANTCODE'=>env_value('REDSYS_MERCHANT_CODE'),'DS_TERMINAL'=>$r->terminal(),'DS_TRANSACTIONTYPE'=>'0','DS_RESPONSE'=>'0000','DS_AUTHORISATIONCODE'=>'TEST00'];
$encoded=$r->encodeMerchantParameters($p);
echo json_encode(['Ds_SignatureVersion'=>'HMAC_SHA256_V1','Ds_MerchantParameters'=>$encoded,'Ds_Signature'=>$r->sign($encoded,$input['reference'])]);
