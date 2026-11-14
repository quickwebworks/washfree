<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';require ROOT.'/app/services.php';
if(PHP_SAPI!=='cli'||!demo())exit("Demo seeding is available only in CLI demo mode.\n");
if(scalar('SELECT COUNT(*) FROM locations'))exit("Locations already exist. Demo seeding skipped.\n");
$admin=row("SELECT * FROM users WHERE role='admin' ORDER BY id LIMIT 1");if(!$admin)exit("Run bin/install.php first.\n");
$_SESSION=['uid'=>$admin['id']];
atomic(function()use($admin){
    $locations=[];foreach([['DEMO · Green Avenue','Fictitious demonstration address · Ludhiana'],['DEMO · Model Town','Fictitious demonstration address · Ludhiana'],['DEMO · City Centre','Fictitious demonstration address · Chandigarh']] as $r){$locations[]=insert('locations',['name'=>$r[0],'address'=>$r[1],'contact'=>'DEMO contact','active'=>1,'checklist'=>json_encode(array_fill_keys(array_keys(CHECKLIST),true)),'created_at'=>now()]);}
    $captain=insert('users',['name'=>'DEMO Wash Captain','email'=>'captain@example.test','password'=>$admin['password'],'role'=>'captain','active'=>1]);
    $helper=insert('users',['name'=>'DEMO Wash Assistant','email'=>'helper@example.test','password'=>$admin['password'],'role'=>'helper','active'=>1]);
    $checker=insert('users',['name'=>'DEMO Quality Supervisor','email'=>'checker@example.test','password'=>$admin['password'],'role'=>'supervisor','active'=>1]);
    $partner=insert('users',['name'=>'DEMO Location Partner','email'=>'partner@example.test','password'=>$admin['password'],'role'=>'partner','active'=>1]);
    foreach([$captain,$helper,$checker,$partner] as $uid)insert('user_locations',['user_id'=>$uid,'location_id'=>$locations[0]]);
    foreach([[$captain,'captain'],[$helper,'helper']] as [$uid,$position])insert('shifts',['location_id'=>$locations[0],'user_id'=>$uid,'work_date'=>date('Y-m-d'),'shift'=>'morning','position'=>$position,'attendance'=>'present','handover'=>'Demonstration roster']);
    $c=insert('customers',['name'=>'DEMO Rider','mobile'=>'9000000000','address'=>'Fictitious customer for demonstration only','kind'=>'individual','location_id'=>$locations[0],'created_at'=>now()]);
    $bike=insert('bikes',['customer_id'=>$c,'registration'=>'PB10DE1234','model_id'=>1]);
    insert('users',['name'=>'DEMO Rider','email'=>'rider@example.test','password'=>$admin['password'],'role'=>'customer','active'=>1,'customer_id'=>$c]);
    $m=insert('memberships',['customer_id'=>$c,'location_id'=>$locations[0],'package_id'=>2,'package_name'=>'Wash Plus · 12 + 2','amount_paise'=>72000,'total_washes'=>14,'remaining'=>14,'reserved'=>0,'months'=>12,'corporate'=>0,'status'=>'pending','created_at'=>now()]);
    insert('membership_bikes',['membership_id'=>$m,'bike_id'=>$bike]);
    insert('payments',['membership_id'=>$m,'location_id'=>$locations[0],'amount_paise'=>72000,'status'=>'pending','provider'=>'demo','created_at'=>now()]);
});
$payment=(int)scalar('SELECT id FROM payments ORDER BY id DESC LIMIT 1');activatePayment($payment,'DEMO-SEED-'.$payment,'demo');
$wash=createWash(1,1,1,2);transitionWash($wash,'washing');transitionWash($wash,'quality');transitionWash($wash,'completed');
insert('expenses',['location_id'=>1,'category'=>'Consumables','amount_paise'=>2500,'spent_on'=>date('Y-m-d'),'note'=>'DEMO expense','user_id'=>$admin['id']]);
echo "Demonstration records created. Staff/customer @example.test logins use your demo administrator password. No real payment or location data.\n";
