<?php
declare(strict_types=1);
function handleAction(): void {
    csrf();$action=text('action',40);
    if($action==='login') {
        $email=strtolower(text('email',190));$key=hash('sha256',strtolower($email).'|'.($_SERVER['REMOTE_ADDR']??''));
        $a=row('SELECT * FROM login_attempts WHERE attempt_key=?',[$key]);if($a&&(int)$a['locked_until']>time())fail('Too many attempts. Try again in 15 minutes.');
        $u=row('SELECT * FROM users WHERE email=? AND active=1',[$email]);
        if(!$u||!password_verify(text('password',200),$u['password'])) {
            if($a)q('UPDATE login_attempts SET failures=?,locked_until=? WHERE attempt_key=?',[(int)$a['failures']+1,time()+900,$key]);else insert('login_attempts',['attempt_key'=>$key,'failures'=>1,'locked_until'=>time()+2]);
            fail('Invalid email or password.');
        }
        q('DELETE FROM login_attempts WHERE attempt_key=?',[$key]);session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));redirect('dashboard');
    }
    $u=requireUser();
    if($action==='logout'){$_SESSION=[];session_regenerate_id(true);redirect('login');}
    if($action==='password'){if(!password_verify(text('current_password',200),$u['password']))fail('Current password is incorrect.');$p=text('password',200);if(strlen($p)<12)fail('Use at least 12 characters.');q('UPDATE users SET password=? WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$u['id']]);session_regenerate_id(true);redirect('account','Password updated.');}
    if($action==='package_save') {
        allow(['admin']);$id=number('id',0);$data=['name'=>text('name',120),'price_paise'=>amount('price'),'washes'=>number('washes',1,10000),'months'=>number('months',0,120),'corporate'=>isset($_POST['corporate'])?1:0,'active'=>isset($_POST['active'])?1:0];
        if($data['price_paise']<1)fail('Price must be positive.');
        atomic(function()use($id,$data){if($id){locked('packages',$id);q('UPDATE packages SET name=?,price_paise=?,washes=?,months=?,corporate=?,active=? WHERE id=?',[...array_values($data),$id]);}else $id=insert('packages',$data);audit('package_saved','packages',$id);});redirect('packages','Package saved. Existing purchases keep their original benefits.');
    }
    if($action==='package_delete'){allow(['admin']);$id=number('id');atomic(function()use($id){locked('packages',$id);if(scalar('SELECT COUNT(*) FROM memberships WHERE package_id=?',[$id]))fail('This package has purchase history. Deactivate it instead.');q('DELETE FROM packages WHERE id=?',[$id]);audit('package_deleted','packages',$id);});redirect('packages','Unused package deleted.');}
    if($action==='location_save') {
        allow(['admin']);$id=number('id',0);$checks=[];foreach(CHECKLIST as $key=>$label)$checks[$key]=isset($_POST['check_'.$key]);$active=isset($_POST['active'])?1:0;if($active&&in_array(false,$checks,true))fail('Complete every opening checklist item before activation.');
        $data=['name'=>text('name',120),'address'=>text('address',2000),'contact'=>text('contact',120),'active'=>$active,'checklist'=>json_encode($checks,JSON_THROW_ON_ERROR)];
        atomic(function()use($id,$data){if($id){locked('locations',$id);q('UPDATE locations SET name=?,address=?,contact=?,active=?,checklist=? WHERE id=?',[...array_values($data),$id]);}else $id=insert('locations',$data+['created_at'=>now()]);audit('location_saved','locations',$id);});redirect('locations','Location saved.');
    }
    if($action==='import_preview') {allow(['admin']);$f=$_FILES['file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))fail('Upload failed.');$_SESSION['import']=readImport($f['tmp_name'],strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)));redirect('locations','Review the import before confirming.');}
    if($action==='import_apply'){allow(['admin']);$data=$_SESSION['import']??[];if(!$data)fail('No import preview available.');atomic(function()use($data){foreach($data as $r){if(scalar('SELECT COUNT(*) FROM locations WHERE name=? AND address=?',[$r['name'],$r['address']]))fail('A location already exists. Preview the file again.');$id=insert('locations',$r+['active'=>0,'checklist'=>'{}','created_at'=>now()]);audit('location_imported','locations',$id);}});unset($_SESSION['import']);redirect('locations','Locations imported as inactive. Complete each checklist to open.');}
    if($action==='import_cancel'){allow(['admin']);unset($_SESSION['import']);redirect('locations');}
    if($action==='user_save'){
        allow(['admin']);$id=number('id',0);$role=text('role',20);if(!isset(ROLES[$role]))fail('Invalid role.');$email=strtolower(text('email',190));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Invalid email.');
        $data=['name'=>text('name',120),'email'=>$email,'role'=>$role,'active'=>isset($_POST['active'])?1:0,'customer_id'=>$role==='customer'?number('customer_id'):null,'salary_paise'=>amount('salary')];
        if($data['customer_id']&&!row('SELECT id FROM customers WHERE id=?',[$data['customer_id']]))fail('Customer not found.');
        $password=text('password',200,false);if((!$id||$password!=='')&&strlen($password)<12)fail('Password must have at least 12 characters.');
        if($id===$u['id']&&($role!=='admin'||!$data['active']))fail('You cannot remove your own administrator access.');
        $locs=array_values(array_unique(array_map('intval',(array)($_POST['locations']??[]))));foreach($locs as $loc)locationAccess($loc);
        atomic(function()use($id,$data,$password,$locs){if($id){$old=locked('users',$id);if($old['role']==='admin'&&($data['role']!=='admin'||!$data['active'])&&(int)scalar("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")<2)fail('Keep at least one active Super Admin.');q('UPDATE users SET name=?,email=?,role=?,active=?,customer_id=?,salary_paise=? WHERE id=?',[...array_values($data),$id]);if($password!=='')q('UPDATE users SET password=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]);}else $id=insert('users',$data+['password'=>password_hash($password,PASSWORD_DEFAULT)]);q('DELETE FROM user_locations WHERE user_id=?',[$id]);foreach($locs as $loc)insert('user_locations',['user_id'=>$id,'location_id'=>$loc]);audit('user_saved','users',$id);});redirect('team','Login and location permissions saved.');
    }
    if($action==='customer_save'){
        allow(['admin','partner','supervisor','captain']);$id=number('id',0);$loc=number('location_id');locationAccess($loc);$mobile=text('mobile',20);if(!preg_match('/^\+?[0-9]{10,15}$/',$mobile))fail('Enter a 10–15 digit mobile number.');$kind=text('kind',20);if(!in_array($kind,['individual','corporate'],true))fail('Invalid account type.');
        $data=['name'=>text('name',120),'mobile'=>$mobile,'address'=>text('address',2000),'kind'=>$kind,'location_id'=>$loc];
        atomic(function()use($id,$data){if($id){$old=locked('customers',$id);locationAccess((int)$old['location_id']);q('UPDATE customers SET name=?,mobile=?,address=?,kind=?,location_id=? WHERE id=?',[...array_values($data),$id]);}else $id=insert('customers',$data+['created_at'=>now()]);audit('customer_saved','customers',$id);});redirect('customers','Customer saved. Add their bike below.');
    }
    if($action==='bike_add'){
        allow(['admin','partner','supervisor','captain']);$cid=number('customer_id');$c=row('SELECT * FROM customers WHERE id=?',[$cid]);if(!$c)fail('Customer not found.');locationAccess((int)$c['location_id']);$mid=number('model_id',0);if($mid&&!scalar('SELECT COUNT(*) FROM bike_models WHERE id=? AND active=1',[$mid]))fail('Model unavailable.');$id=insert('bikes',['customer_id'=>$cid,'registration'=>plate(text('registration',25)),'model_id'=>$mid?:null]);audit('bike_added','bikes',$id);redirect('customers','Bike added.');
    }
    if($action==='membership_buy'){
        allow(['admin','partner','supervisor','captain']);$cid=number('customer_id');$loc=number('location_id');locationAccess($loc);$p=row('SELECT * FROM packages WHERE id=? AND active=1',[number('package_id')]);$c=row('SELECT * FROM customers WHERE id=?',[$cid]);if(!$p||!$c)fail('Customer or package unavailable.');locationAccess((int)$c['location_id']);if(!scalar('SELECT COUNT(*) FROM locations WHERE id=? AND active=1',[$loc]))fail('Choose an active selling location.');
        $bikes=array_values(array_unique(array_map('intval',(array)($_POST['bikes']??[]))));if(!$bikes||(!$p['corporate']&&count($bikes)!==1))fail('Individual packages require one bike. Fleet packages require at least one registered bike.');foreach($bikes as $bid)if(!scalar('SELECT COUNT(*) FROM bikes WHERE id=? AND customer_id=?',[$bid,$cid]))fail('Choose bikes owned by this account.');
        atomic(function()use($cid,$loc,$p,$bikes){$id=insert('memberships',['customer_id'=>$cid,'location_id'=>$loc,'package_id'=>$p['id'],'package_name'=>$p['name'],'amount_paise'=>$p['price_paise'],'total_washes'=>$p['washes'],'remaining'=>$p['washes'],'reserved'=>0,'months'=>$p['months'],'corporate'=>$p['corporate'],'status'=>'pending','created_at'=>now()]);foreach($bikes as $bid)insert('membership_bikes',['membership_id'=>$id,'bike_id'=>$bid]);insert('payments',['membership_id'=>$id,'location_id'=>$loc,'amount_paise'=>$p['price_paise'],'status'=>'pending','provider'=>demo()?'demo':'razorpay','created_at'=>now()]);audit('membership_created','memberships',$id);});redirect('payments','Purchase saved. Payment verification is required before washing.');
    }
    if($action==='membership_link'){
        allow(['admin','partner']);$id=number('id');$bid=number('bike_id');atomic(function()use($id,$bid){$m=locked('memberships',$id);locationAccess((int)$m['location_id']);if(!$m['corporate']||!scalar('SELECT COUNT(*) FROM bikes WHERE id=? AND customer_id=?',[$bid,$m['customer_id']]))fail('Only bikes owned by this fleet account can be linked to its corporate package.');insert('membership_bikes',['membership_id'=>$id,'bike_id'=>$bid]);audit('fleet_bike_linked','memberships',$id,['bike_id'=>$bid]);});redirect('customers','Bike linked to corporate balance.');
    }
    if($action==='payment_demo'){allow(['admin','partner']);if(!demo())fail('Simulated payment is disabled in production.');$id=number('id');$p=row('SELECT * FROM payments WHERE id=?',[$id]);if(!$p)fail('Payment not found.');locationAccess((int)$p['location_id']);activatePayment($id,'DEMO-'.$id,'demo');redirect('payments','DEMO payment recorded. No money was received.');}
    if($action==='payment_qr'){allow(['admin','partner','captain']);createQr(number('id'));redirect('payments','QR created. Package will activate after a verified payment notification.');}
    if($action==='payment_reconcile'){
        allow(['admin']);global $config;$id=number('id');$qr=text('qr_id',120);if(!preg_match('/^qr_[A-Za-z0-9]+$/',$qr))fail('Invalid QR reference.');if(empty($config['razorpay_key_id'])||empty($config['razorpay_key_secret']))fail('Configure API keys.');
        $ch=curl_init('https://api.razorpay.com/v1/payments/qr_codes/'.rawurlencode($qr));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>$config['razorpay_key_id'].':'.$config['razorpay_key_secret'],CURLOPT_TIMEOUT=>20]);$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$remote=is_string($raw)?json_decode($raw,true):null;
        atomic(function()use($id,$qr,$remote,$code){$p=locked('payments',$id);if($p['status']!=='qr_creating'||$code!==200||($remote['notes']['wash_free_payment_id']??null)!==(string)$id||(int)($remote['payment_amount']??0)!==(int)$p['amount_paise']||empty($remote['image_url']))fail('QR could not be reconciled to this payment.');q('UPDATE payments SET qr_id=?,qr_url=?,status=? WHERE id=?',[$qr,$remote['image_url'],'pending',$id]);audit('qr_reconciled','payments',$id);});redirect('payments','QR reference restored. Replay the provider payment webhook if payment already arrived.');
    }
    if($action==='wash_create'){allow(['admin','partner','supervisor','captain']);createWash(number('bike_id'),number('membership_id'),number('location_id'),number('washer_id'));redirect('washes','Wash queued. One credit reserved.');}
    if($action==='wash_status'){allow(['admin','partner','supervisor','captain','washer']);transitionWash(number('id'),text('status',20),text('note',1000,false));redirect('washes','Wash updated.');}
    if($action==='expense_save'){allow(['admin','partner']);$loc=number('location_id');locationAccess($loc);$a=amount('amount');if($a<1)fail('Expense must be positive.');$id=insert('expenses',['location_id'=>$loc,'category'=>text('category',80),'amount_paise'=>$a,'spent_on'=>validDate(text('spent_on',10)),'note'=>text('note',1000,false),'user_id'=>$u['id']]);audit('expense_recorded','expenses',$id);redirect('finance','Expense saved.');}
    if($action==='shift_save'){
        allow(['admin','partner']);$loc=number('location_id');locationAccess($loc);$uid=number('user_id');$position=text('position',20);$shift=text('shift',20);$attendance=text('attendance',20);
        if(!in_array($position,['captain','helper'],true)||!in_array($shift,['morning','evening'],true)||!in_array($attendance,['scheduled','present','absent'],true))fail('Invalid shift details.');
        $staff=row('SELECT * FROM users WHERE id=? AND active=1',[$uid]);if(!$staff||!in_array($staff['role'],$position==='captain'?['captain','washer']:['helper'],true)||!scalar('SELECT COUNT(*) FROM user_locations WHERE user_id=? AND location_id=?',[$uid,$loc]))fail('Choose a suitable employee assigned to this location.');
        $data=['location_id'=>$loc,'user_id'=>$uid,'work_date'=>validDate(text('work_date',10)),'shift'=>$shift,'position'=>$position,'attendance'=>$attendance,'handover'=>text('handover',1000,false)];$id=number('id',0);
        atomic(function()use($id,$data){if($id){$old=locked('shifts',$id);locationAccess((int)$old['location_id']);q('UPDATE shifts SET location_id=?,user_id=?,work_date=?,shift=?,position=?,attendance=?,handover=? WHERE id=?',[...array_values($data),$id]);}else $id=insert('shifts',$data);audit('shift_saved','shifts',$id);});redirect('shifts','Shift saved.');
    }
    if($action==='model_save'){
        allow(['admin']);$id=number('id',0);$photo=text('photo_url',2000,false);$source=text('source_url',2000,false);foreach([$photo,$source] as $url)if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'))fail('Image and source URLs must use HTTPS.');$note=text('permission_note',1000,false);if($photo!==''&&$note==='')fail('Record permission or licence for the image.');$data=['company'=>text('company',100),'model'=>text('model',150),'photo_url'=>$photo,'source_url'=>$source,'permission_note'=>$note,'active'=>isset($_POST['active'])?1:0];
        atomic(function()use($id,$data){if($id){locked('bike_models',$id);q('UPDATE bike_models SET company=?,model=?,photo_url=?,source_url=?,permission_note=?,active=? WHERE id=?',[...array_values($data),$id]);}else $id=insert('bike_models',$data);audit('model_saved','bike_models',$id);});redirect('catalogue','Bike model saved.');
    }
    fail('Unknown action.');
}
