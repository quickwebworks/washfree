<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require ROOT.'/app/services.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
if(demo()||empty($config['razorpay_webhook_secret'])){http_response_code(503);exit('Payment webhook is not configured.');}
$raw=file_get_contents('php://input');
if(strlen($raw)>262144){http_response_code(413);exit;}
$signature=$_SERVER['HTTP_X_RAZORPAY_SIGNATURE']??'';
if(!hash_equals(hash_hmac('sha256',$raw,$config['razorpay_webhook_secret']),$signature)){http_response_code(401);exit('Invalid signature.');}
try{
    $event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(($event['event']??'')!=='qr_code.credited'){http_response_code(200);exit('Ignored');}
    $qr=$event['payload']['qr_code']['entity']??[];$payment=$event['payload']['payment']['entity']??[];
    $p=row('SELECT * FROM payments WHERE qr_id=?',[$qr['id']??'']);
    if(!$p){http_response_code(409);exit('QR not registered. Reconcile first and replay event.');}
    if(($payment['status']??'')!=='captured'||($payment['currency']??'')!=='INR'||($payment['method']??'')!=='upi'||(int)($payment['amount']??0)!==(int)$p['amount_paise']||empty($payment['id'])){http_response_code(422);exit('Payment details do not match.');}
    unset($_SESSION['uid']);
    activatePayment((int)$p['id'],(string)$payment['id'],'razorpay');
    echo 'OK';
}catch(Throwable $e){error_log('Wash Free webhook: '.$e->getMessage());http_response_code(500);echo 'Unable to process payment.';}
