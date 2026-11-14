<?php
declare(strict_types=1);
function activatePayment(int $paymentId,string $reference,string $provider): void {
    atomic(function()use($paymentId,$reference,$provider){
        $p=locked('payments',$paymentId); if($p['status']==='paid') {if($p['provider_payment_id']!==$reference) fail('Payment already settled with a different reference.');return;}
        if($p['status']!=='pending') fail('Payment is not pending.');
        $m=locked('memberships',(int)$p['membership_id']); if($m['status']!=='pending') fail('Membership is not awaiting payment.');
        $start=date('Y-m-d'); $end=(int)$m['months']===0?$start:expiry($start,(int)$m['months']);
        q('UPDATE payments SET status=?, provider=?, provider_payment_id=?, paid_at=? WHERE id=?',['paid',$provider,$reference,now(),$paymentId]);
        q('UPDATE memberships SET status=?,start_date=?,expires_at=? WHERE id=?',['active',$start,$end,$m['id']]);
        audit('payment_verified','payments',$paymentId,['provider'=>$provider]);
    });
}
function createQr(int $paymentId): void {
    global $config;
    if(demo()) fail('Live QR generation is disabled in demo mode.');
    if(empty($config['razorpay_key_id'])||empty($config['razorpay_key_secret'])) fail('Configure your Razorpay merchant API keys before generating QR codes.');
    // Claim pending payment before remote call; an uncertain response must be reconciled rather than retried blindly.
    $p=atomic(function()use($paymentId){$p=locked('payments',$paymentId);locationAccess((int)$p['location_id']);if($p['status']!=='pending'||$p['qr_id']) fail('A QR code already exists or payment is not pending.'); q('UPDATE payments SET status=? WHERE id=?',['qr_creating',$paymentId]);return $p;});
    $body=json_encode(['type'=>'upi_qr','name'=>'Wash Free #'.$paymentId,'usage'=>'single_use','fixed_amount'=>true,'payment_amount'=>(int)$p['amount_paise'],'description'=>'Bike wash membership #'.$p['membership_id'],'notes'=>['wash_free_payment_id'=>(string)$paymentId]],JSON_THROW_ON_ERROR);
    $ch=curl_init('https://api.razorpay.com/v1/payments/qr_codes');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_USERPWD=>$config['razorpay_key_id'].':'.$config['razorpay_key_secret'],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25]);
    $response=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $result=is_string($response)?json_decode($response,true):null;
    if($code<200||$code>=300||empty($result['id'])||empty($result['image_url'])) { audit('qr_creation_uncertain','payments',$paymentId);fail('QR creation was not confirmed. Check the merchant dashboard and reconcile this payment before another attempt.'); }
    atomic(function()use($paymentId,$result){$p=locked('payments',$paymentId);q('UPDATE payments SET qr_id=?,qr_url=?,status=? WHERE id=?',[$result['id'],$result['image_url'],'pending',$paymentId]);audit('qr_created','payments',$paymentId);});
}
function createWash(int $bikeId,int $membershipId,int $locationId,int $washerId): int {
    locationAccess($locationId);
    return atomic(function()use($bikeId,$membershipId,$locationId,$washerId){
        if(!scalar('SELECT COUNT(*) FROM locations WHERE id=? AND active=1',[$locationId])) fail('Location must be active.');
        $hour=(int)date('G'); if(!demo()&&($hour<7||$hour>=23)) fail('Wash intake is available from 7:00 AM to 11:00 PM.');
        locked('bikes',$bikeId); $m=locked('memberships',$membershipId);
        if($m['status']!=='active'||$m['expires_at']<date('Y-m-d')||$m['remaining']-$m['reserved']<1) fail('Package expired, unpaid, or has no available washes.');
        if(!scalar('SELECT COUNT(*) FROM membership_bikes WHERE membership_id=? AND bike_id=?',[$membershipId,$bikeId])) fail('This bike is not authorised for that membership.');
        if(scalar("SELECT COUNT(*) FROM washes WHERE bike_id=? AND status IN ('queued','washing','quality')",[$bikeId])) fail('This bike already has an active wash.');
        if(!scalar("SELECT COUNT(*) FROM users u JOIN user_locations ul ON ul.user_id=u.id WHERE u.id=? AND ul.location_id=? AND u.active=1 AND u.role IN ('captain','washer')",[$washerId,$locationId])) fail('Choose an active Wash Captain or Wash Specialist assigned to this location.');
        q('UPDATE memberships SET reserved=reserved+1 WHERE id=?',[$membershipId]);
        $id=insert('washes',['bike_id'=>$bikeId,'membership_id'=>$membershipId,'location_id'=>$locationId,'washer_id'=>$washerId,'status'=>'queued','note'=>'','created_at'=>now()]);
        audit('wash_reserved','washes',$id); return $id;
    });
}
function transitionWash(int $id,string $next,string $note=''):void {
    $u=requireUser();
    atomic(function()use($id,$next,$note,$u){
        // Lock membership before job, consistently with intake, preventing cross-operation deadlocks.
        $snapshot=row('SELECT membership_id FROM washes WHERE id=?',[$id]); if(!$snapshot) fail('Wash not found.');
        $m=locked('memberships',(int)$snapshot['membership_id']);$w=locked('washes',$id);locationAccess((int)$w['location_id']);
        $transitions=['queued'=>['washing','cancelled'],'washing'=>['quality','cancelled'],'quality'=>['completed','washing','cancelled']];
        if(!in_array($next,$transitions[$w['status']]??[],true)) fail('This wash has already changed. Refresh and try again.');
        if(in_array($next,['completed'],true)||($w['status']==='quality'&&$next==='washing')) {
            if(!in_array($u['role'],['admin','supervisor'],true)) fail('A Quality Supervisor must approve or request a rewash.');
            if($next==='washing'&&trim($note)==='') fail('A reason is required for rewash.');
        } elseif(!in_array($u['role'],['admin','partner','supervisor'],true)&&(int)$w['washer_id']!==(int)$u['id']) fail('Only the assigned washer can update this job.');
        if((int)$m['reserved']<1) fail('Reserved balance mismatch. Contact Super Admin.');
        if($next==='completed') {
            if((int)$m['remaining']<1) fail('Insufficient wash balance.');
            $used=(int)$m['total_washes']-(int)$m['remaining'];
            // Cumulative rounding guarantees the final credited wash allocates the full package price exactly.
            $earned=(int)round(($used+1)*(int)$m['amount_paise']/(int)$m['total_washes'])-(int)round($used*(int)$m['amount_paise']/(int)$m['total_washes']);
            q('UPDATE memberships SET remaining=remaining-1,reserved=reserved-1 WHERE id=?',[$m['id']]);
            q('UPDATE washes SET status=?,checker_id=?,earned_paise=?,completed_at=?,note=? WHERE id=?',[$next,$u['id'],$earned,now(),$note,$id]);
        } else {
            if($next==='cancelled') q('UPDATE memberships SET reserved=reserved-1 WHERE id=?',[$m['id']]);
            q('UPDATE washes SET status=?,note=? WHERE id=?',[$next,$note,$id]);
        }
        audit('wash_'.$next,'washes',$id,['note'=>$note]);
    });
}
function readImport(string $path,string $extension): array {
    if(filesize($path)>5*1024*1024) fail('Import limit is 5 MB.');
    $rows=[];
    if($extension==='csv') {
        $f=fopen($path,'rb');while(($r=fgetcsv($f,0,',','"',''))!==false) { $rows[]=$r;if(count($rows)>1001) fail('Maximum 1,000 records per import.'); }fclose($f);
    } elseif($extension==='xlsx') {
        if(!class_exists('ZipArchive')||!function_exists('simplexml_load_string')) fail('Enable PHP ZipArchive and SimpleXML for Excel imports, or save the sheet as CSV.');
        $zip=new ZipArchive();if($zip->open($path)!==true)fail('Invalid Excel file.');
        $safe=function(string $name)use($zip):string {$stat=$zip->statName($name);if(!$stat||$stat['size']>8*1024*1024)fail('Missing or oversized Excel worksheet.');$s=$zip->getFromName($name);if(!is_string($s)||str_contains(strtoupper($s),'<!DOCTYPE')||str_contains(strtoupper($s),'<!ENTITY'))fail('Invalid Excel XML.');return $s;};
        $strings=[];if($zip->locateName('xl/sharedStrings.xml')!==false){$xml=simplexml_load_string($safe('xl/sharedStrings.xml'),SimpleXMLElement::class,LIBXML_NONET);if(!$xml)fail('Invalid strings.');foreach($xml->si as $si){$parts=$si->xpath('.//*[local-name()="t"]');$strings[]=implode('',array_map(fn($n)=>(string)$n,$parts));}}
        $xml=simplexml_load_string($safe('xl/worksheets/sheet1.xml'),SimpleXMLElement::class,LIBXML_NONET);if(!$xml)fail('Invalid worksheet.');
        foreach($xml->sheetData->row as $r){$values=[];foreach($r->c as $c){$ref=(string)$c['r'];preg_match('/^([A-Z]+)/',$ref,$match);$col=0;foreach(str_split($match[1]??'A') as $l)$col=$col*26+ord($l)-64;$col--;if($col>30)fail('Too many import columns.');$type=(string)$c['t'];$value=$type==='s'?($strings[(int)$c->v]??''):($type==='inlineStr'?(string)$c->is->t:(string)$c->v);$values[$col]=$value;}if($values){$line=array_fill(0,max(array_keys($values))+1,'');foreach($values as $k=>$v)$line[$k]=$v;$rows[]=$line;}if(count($rows)>1001)fail('Maximum 1,000 rows.');}$zip->close();
    } else fail('Upload .csv or .xlsx only.');
    if(!$rows)fail('File is empty.');$header=array_map(fn($x)=>strtolower(trim(ltrim((string)$x,"\xEF\xBB\xBF"))),array_shift($rows));
    if($header!==['name','address','contact'])fail('Required columns, in order: name,address,contact');
    $clean=[];$seen=[];foreach($rows as $i=>$r){if(!array_filter($r,fn($v)=>trim((string)$v)!==''))continue;if(count($r)!==3)fail('Invalid columns at row '.($i+2));[$name,$address,$contact]=array_map('trim',$r);if($name===''||strlen($name)>120||$address===''||strlen($address)>2000||$contact===''||strlen($contact)>120)fail('Invalid fields at row '.($i+2));$key=strtolower($name.'|'.$address);if(isset($seen[$key])||scalar('SELECT COUNT(*) FROM locations WHERE name=? AND address=?',[$name,$address]))fail('Duplicate location at row '.($i+2));$seen[$key]=true;$clean[]=compact('name','address','contact');}
    if(!$clean)fail('No locations to import.');return $clean;
}
