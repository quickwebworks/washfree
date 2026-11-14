<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(PHP_SAPI!=='cli') exit;
if($argc<3||!filter_var($argv[1],FILTER_VALIDATE_EMAIL)||strlen($argv[2])<12) exit("Usage: php bin/install.php admin@example.com 'password-at-least-12-characters'\n");
$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$id=$mysql?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
$tables=[
'locations'=>"id $id, name VARCHAR(120) NOT NULL, address TEXT NOT NULL, contact VARCHAR(120) NOT NULL, active INTEGER NOT NULL DEFAULT 0, checklist TEXT NOT NULL, created_at VARCHAR(19) NOT NULL",
'customers'=>"id $id, name VARCHAR(120) NOT NULL, mobile VARCHAR(20) NOT NULL, address TEXT NOT NULL, kind VARCHAR(20) NOT NULL, location_id INTEGER NOT NULL, created_at VARCHAR(19) NOT NULL, FOREIGN KEY(location_id) REFERENCES locations(id)",
'users'=>"id $id, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, active INTEGER NOT NULL DEFAULT 1, customer_id INTEGER NULL, salary_paise INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(customer_id) REFERENCES customers(id)",
'user_locations'=>'user_id INTEGER NOT NULL, location_id INTEGER NOT NULL, PRIMARY KEY(user_id,location_id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(location_id) REFERENCES locations(id)',
'packages'=>"id $id, name VARCHAR(120) NOT NULL, price_paise INTEGER NOT NULL, washes INTEGER NOT NULL, months INTEGER NOT NULL, corporate INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1",
'bike_models'=>"id $id, company VARCHAR(100) NOT NULL, model VARCHAR(150) NOT NULL, photo_url TEXT NOT NULL, source_url TEXT NOT NULL, permission_note TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, UNIQUE(company,model)",
'bikes'=>"id $id, customer_id INTEGER NOT NULL, registration VARCHAR(20) NOT NULL UNIQUE, model_id INTEGER NULL, FOREIGN KEY(customer_id) REFERENCES customers(id), FOREIGN KEY(model_id) REFERENCES bike_models(id)",
'memberships'=>"id $id, customer_id INTEGER NOT NULL, location_id INTEGER NOT NULL, package_id INTEGER NOT NULL, package_name VARCHAR(120) NOT NULL, amount_paise INTEGER NOT NULL, total_washes INTEGER NOT NULL, remaining INTEGER NOT NULL, reserved INTEGER NOT NULL DEFAULT 0, months INTEGER NOT NULL, corporate INTEGER NOT NULL, status VARCHAR(20) NOT NULL, start_date VARCHAR(10) NULL, expires_at VARCHAR(10) NULL, created_at VARCHAR(19) NOT NULL, FOREIGN KEY(customer_id) REFERENCES customers(id), FOREIGN KEY(location_id) REFERENCES locations(id), FOREIGN KEY(package_id) REFERENCES packages(id)",
'membership_bikes'=>'membership_id INTEGER NOT NULL, bike_id INTEGER NOT NULL, PRIMARY KEY(membership_id,bike_id), FOREIGN KEY(membership_id) REFERENCES memberships(id), FOREIGN KEY(bike_id) REFERENCES bikes(id)',
'payments'=>"id $id, membership_id INTEGER NOT NULL UNIQUE, location_id INTEGER NOT NULL, amount_paise INTEGER NOT NULL, status VARCHAR(20) NOT NULL, provider VARCHAR(20) NOT NULL, qr_id VARCHAR(120) NULL UNIQUE, qr_url TEXT NULL, provider_payment_id VARCHAR(120) NULL UNIQUE, paid_at VARCHAR(19) NULL, created_at VARCHAR(19) NOT NULL, FOREIGN KEY(membership_id) REFERENCES memberships(id), FOREIGN KEY(location_id) REFERENCES locations(id)",
'washes'=>"id $id, bike_id INTEGER NOT NULL, membership_id INTEGER NOT NULL, location_id INTEGER NOT NULL, washer_id INTEGER NOT NULL, checker_id INTEGER NULL, status VARCHAR(20) NOT NULL, earned_paise INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL, created_at VARCHAR(19) NOT NULL, completed_at VARCHAR(19) NULL, FOREIGN KEY(bike_id) REFERENCES bikes(id), FOREIGN KEY(membership_id) REFERENCES memberships(id), FOREIGN KEY(location_id) REFERENCES locations(id), FOREIGN KEY(washer_id) REFERENCES users(id), FOREIGN KEY(checker_id) REFERENCES users(id)",
'expenses'=>"id $id, location_id INTEGER NOT NULL, category VARCHAR(80) NOT NULL, amount_paise INTEGER NOT NULL, spent_on VARCHAR(10) NOT NULL, note TEXT NOT NULL, user_id INTEGER NOT NULL, FOREIGN KEY(location_id) REFERENCES locations(id), FOREIGN KEY(user_id) REFERENCES users(id)",
'shifts'=>"id $id, location_id INTEGER NOT NULL, user_id INTEGER NOT NULL, work_date VARCHAR(10) NOT NULL, shift VARCHAR(20) NOT NULL, position VARCHAR(20) NOT NULL, attendance VARCHAR(20) NOT NULL, handover TEXT NOT NULL, UNIQUE(location_id,work_date,shift,position), UNIQUE(user_id,work_date,shift), FOREIGN KEY(location_id) REFERENCES locations(id), FOREIGN KEY(user_id) REFERENCES users(id)",
'audit_logs'=>"id $id, user_id INTEGER NULL, action VARCHAR(80) NOT NULL, entity VARCHAR(40) NOT NULL, entity_id INTEGER NOT NULL, details TEXT NOT NULL, created_at VARCHAR(19) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)",
'login_attempts'=>"attempt_key VARCHAR(64) PRIMARY KEY, failures INTEGER NOT NULL, locked_until INTEGER NOT NULL",
];
foreach($tables as $name=>$body) $db->exec("CREATE TABLE IF NOT EXISTS $name ($body)".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
foreach(['washes'=>['location_id','created_at'],'memberships'=>['customer_id','status'],'expenses'=>['location_id','spent_on']] as $table=>$columns) {
    foreach($columns as $col) {try{$db->exec("CREATE INDEX idx_{$table}_{$col} ON $table ($col)");}catch(PDOException $e){ if(!str_contains($e->getMessage(),'already exists')&&!str_contains($e->getMessage(),'Duplicate key name')) throw $e; }}
}
if(!scalar('SELECT COUNT(*) FROM users')) {
    insert('users',['name'=>'Wash Free Admin','email'=>strtolower($argv[1]),'password'=>password_hash($argv[2],PASSWORD_DEFAULT),'role'=>'admin','active'=>1]);
    foreach([['Quick Wash',6000,1,0,0],['Wash Plus · 12 + 2',72000,14,12,0],['Fleet Freedom · 36 + 10',216000,46,36,1]] as $p) insert('packages',array_combine(['name','price_paise','washes','months','corporate'],$p));
    foreach([['Hero','Splendor Plus'],['Honda','Shine'],['Bajaj','Pulsar'],['TVS','Apache RTR'],['Royal Enfield','Classic 350'],['Yamaha','FZ'],['Suzuki','Gixxer'],['KTM','Duke']] as $b) insert('bike_models',['company'=>$b[0],'model'=>$b[1],'photo_url'=>'','source_url'=>'','permission_note'=>'Starter names only; verify exact variants and add licensed photos.']);
}
echo "Wash Free installed. Sign in with the supplied email and password. No real locations or payments were created.\n";
