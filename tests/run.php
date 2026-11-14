<?php
declare(strict_types=1);
// Independent SQLite integration suite. Never uses config.php or production data.
$root=dirname(__DIR__);$dbPath=sys_get_temp_dir().'/washfree-test-'.bin2hex(random_bytes(6)).'.sqlite';$cfgPath=$dbPath.'.php';
file_put_contents($cfgPath,"<?php return ['mode'=>'demo','dsn'=>'sqlite:".$dbPath."','timezone'=>'Asia/Kolkata'];");
putenv('WASH_CONFIG='.$cfgPath);
register_shutdown_function(function()use($dbPath,$cfgPath){@unlink($dbPath);@unlink($cfgPath);});
$argv=['install.php','test@example.test','Test-suite-password-123'];$argc=3;
require $root.'/bin/install.php';require $root.'/app/services.php';
$_SESSION=['uid'=>1,'csrf'=>'test-token'];$checks=0;
function ok(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException('FAIL: '.$message);$checks++;echo "PASS: $message\n";}
function rejects(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){ok(true,$message);return;}ok(false,$message);}
function makeLocation(string $name):int{return insert('locations',['name'=>$name,'address'=>'Test address','contact'=>'Test contact','active'=>1,'checklist'=>'{}','created_at'=>now()]);}
function makeMember(int $customer,int $location,int $package,int $total,int $amount,array $bikes,bool $corporate=false):array{
    $id=insert('memberships',['customer_id'=>$customer,'location_id'=>$location,'package_id'=>$package,'package_name'=>'Test package snapshot','amount_paise'=>$amount,'total_washes'=>$total,'remaining'=>$total,'reserved'=>0,'months'=>12,'corporate'=>$corporate?1:0,'status'=>'pending','created_at'=>now()]);
    foreach($bikes as $bike)insert('membership_bikes',['membership_id'=>$id,'bike_id'=>$bike]);$payment=insert('payments',['membership_id'=>$id,'location_id'=>$location,'amount_paise'=>$amount,'status'=>'pending','provider'=>'demo','created_at'=>now()]);return [$id,$payment];
}
$l1=makeLocation('Test A');$l2=makeLocation('Test B');
$washer=insert('users',['name'=>'Washer','email'=>'washer@example.test','password'=>'not-a-login','role'=>'captain','active'=>1]);
$checker=insert('users',['name'=>'Checker','email'=>'checker@example.test','password'=>'not-a-login','role'=>'supervisor','active'=>1]);
$partner=insert('users',['name'=>'Partner','email'=>'partner@example.test','password'=>'not-a-login','role'=>'partner','active'=>1]);
foreach([$washer,$checker,$partner] as $uid)insert('user_locations',['user_id'=>$uid,'location_id'=>$l1]);
$c1=insert('customers',['name'=>'Test rider','mobile'=>'9999999999','address'=>'Test','kind'=>'corporate','location_id'=>$l1,'created_at'=>now()]);
$c2=insert('customers',['name'=>'Other rider','mobile'=>'9999999998','address'=>'Test','kind'=>'individual','location_id'=>$l2,'created_at'=>now()]);
$b1=insert('bikes',['customer_id'=>$c1,'registration'=>'PB10AB1234']);$b2=insert('bikes',['customer_id'=>$c1,'registration'=>'PB10AB1235']);$b3=insert('bikes',['customer_id'=>$c2,'registration'=>'PB10AB1236']);
[$m,$p]=makeMember($c1,$l1,2,14,72000,[$b1]);
rejects(fn()=>createWash($b1,$m,$l1,$washer),'Unpaid membership cannot wash');
activatePayment($p,'TEST-PAYMENT','demo');activatePayment($p,'TEST-PAYMENT','demo');
ok((int)scalar('SELECT remaining FROM memberships WHERE id=?',[$m])===14,'Repeated payment does not add credits');
rejects(fn()=>activatePayment($p,'DIFFERENT-REFERENCE','demo'),'Different payment cannot overwrite settled purchase');
$w=createWash($b1,$m,$l1,$washer);
ok((int)scalar('SELECT reserved FROM memberships WHERE id=?',[$m])===1,'Queued wash reserves one credit');
rejects(fn()=>createWash($b1,$m,$l1,$washer),'Duplicate active wash on same bike rejected');
rejects(fn()=>createWash($b2,$m,$l1,$washer),'Unlinked corporate bike rejected');
transitionWash($w,'cancelled');ok((int)scalar('SELECT reserved FROM memberships WHERE id=?',[$m])===0,'Cancellation releases reserved credit');
ok((int)scalar('SELECT remaining FROM memberships WHERE id=?',[$m])===14,'Cancellation does not consume a wash');
$w=createWash($b1,$m,$l1,$washer);transitionWash($w,'washing');transitionWash($w,'quality');
$_SESSION['uid']=$washer;rejects(fn()=>transitionWash($w,'completed'),'Washer cannot self-approve quality');
$_SESSION['uid']=$checker;rejects(fn()=>transitionWash($w,'washing'),'Rewash requires a reason');transitionWash($w,'washing','Rear wheel needs attention');transitionWash($w,'quality');transitionWash($w,'completed');
ok((int)scalar('SELECT remaining FROM memberships WHERE id=?',[$m])===13,'Approved completion consumes exactly one credit');
ok((int)scalar('SELECT reserved FROM memberships WHERE id=?',[$m])===0,'Completion clears reservation');
rejects(fn()=>transitionWash($w,'completed'),'Repeated completion rejected');
$_SESSION['uid']=1;
for($i=0;$i<13;$i++){$w=createWash($b1,$m,$l1,$washer);transitionWash($w,'washing');transitionWash($w,'quality');transitionWash($w,'completed');}
ok((int)scalar('SELECT SUM(earned_paise) FROM washes WHERE membership_id=?',[$m])===72000,'All 14 washes allocate exactly ₹720 without rounding loss');
rejects(fn()=>createWash($b1,$m,$l1,$washer),'Exhausted package rejected');
[$fleet,$fp]=makeMember($c1,$l1,3,1,6000,[$b1,$b2],true);activatePayment($fp,'TEST-FLEET','demo');
$w=createWash($b1,$fleet,$l1,$washer);rejects(fn()=>createWash($b2,$fleet,$l1,$washer),'Reserved fleet credit cannot be spent by another bike');transitionWash($w,'cancelled');
$w=createWash($b2,$fleet,$l1,$washer);transitionWash($w,'cancelled');ok(true,'Corporate linked bike can redeem shared balance');
q('UPDATE memberships SET expires_at=? WHERE id=?',['2000-01-01',$fleet]);rejects(fn()=>createWash($b1,$fleet,$l1,$washer),'Expired membership rejected');
$_SESSION['uid']=$partner;rejects(fn()=>locationAccess($l2),'Location Partner cannot access another shop');locationAccess($l1);ok(true,'Assigned location remains accessible');
[$condition,$args]=scope('location_id');ok((int)scalar('SELECT COUNT(*) FROM customers WHERE '.$condition,$args)===1,'Scoped customer query excludes another location');
$_SESSION['uid']=1;$before=(int)scalar('SELECT COUNT(*) FROM expenses');rejects(function()use($l1){atomic(function()use($l1){insert('expenses',['location_id'=>$l1,'category'=>'Rollback test','amount_paise'=>100,'spent_on'=>date('Y-m-d'),'note'=>'','user_id'=>1]);fail('Intentional rollback');});},'Transaction failure rolls back');ok((int)scalar('SELECT COUNT(*) FROM expenses')===$before,'Rollback leaves no partial expense');
ok(expiry('2024-02-29',12)==='2025-02-28','Leap day expiry clamps safely');ok(expiry('2026-01-31',1)==='2026-02-28','Month-end expiry clamps safely');ok(plate('pb-10 ab 1234')==='PB10AB1234','Bike number normalisation');rejects(fn()=>plate('<script>'),'Invalid bike number rejected');
$csv=$dbPath.'.csv';file_put_contents($csv,"name,address,contact\nNew shop,New address,9999999999\n");$import=readImport($csv,'csv');ok(count($import)===1&&$import[0]['name']==='New shop','CSV import preview validates fields');file_put_contents($csv,"name,address,contact\nTest A,Test address,9999999999\n");rejects(fn()=>readImport($csv,'csv'),'Duplicate import rejected');unlink($csv);

if(class_exists('ZipArchive')&&function_exists('simplexml_load_string')){
    $xlsx=$dbPath.'.xlsx';$zip=new ZipArchive();$zip->open($xlsx,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>address</t></is></c><c r="C1" t="inlineStr"><is><t>contact</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Excel shop</t></is></c><c r="B2" t="inlineStr"><is><t>Excel address</t></is></c><c r="C2" t="inlineStr"><is><t>9999999999</t></is></c></row></sheetData></worksheet>');$zip->close();
    $data=readImport($xlsx,'xlsx');ok(count($data)===1&&$data[0]['name']==='Excel shop','XLSX inline string import works');unlink($xlsx);
}
echo "\n$checks checks passed.\n";
