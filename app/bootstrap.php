<?php
declare(strict_types=1);
const ROOT = __DIR__ . '/..';
$configFile = getenv('WASH_CONFIG') ?: ROOT . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli') http_response_code(503);
    exit("Wash Free setup required. Copy config.example.php to config.php, enter your database settings, then run: php bin/install.php admin@example.com 'YOUR_STRONG_PASSWORD'\nSee README.md.\n");
}
$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');
$db = new PDO($config['dsn'], $config['db_user'] ?? '', $config['db_password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->exec('PRAGMA foreign_keys = ON'); $db->exec('PRAGMA busy_timeout = 5000');
}
function q(string $sql, array $args = []): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($args); return $s; }
function row(string $sql, array $args = []): ?array { return q($sql,$args)->fetch() ?: null; }
function all(string $sql, array $args = []): array { return q($sql,$args)->fetchAll(); }
function scalar(string $sql, array $args = []): mixed { return q($sql,$args)->fetchColumn(); }
function insert(string $table, array $data): int {
    global $db; $columns=array_keys($data);
    q('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($data));
    return (int)$db->lastInsertId();
}
function atomic(callable $fn): mixed {
    global $db; $sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    if ($sqlite) $db->exec('BEGIN IMMEDIATE'); else $db->beginTransaction();
    try { $result=$fn(); if($sqlite) $db->exec('COMMIT'); else $db->commit(); return $result; }
    catch(Throwable $e) { if($sqlite) $db->exec('ROLLBACK'); elseif($db->inTransaction()) $db->rollBack(); throw $e; }
}
function locked(string $table,int $id): array {
    global $db; $r=row("SELECT * FROM $table WHERE id=?".($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id]);
    if(!$r) throw new RuntimeException('Record not found.'); return $r;
}
function h(mixed $v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function money(int|float $p): string {return '₹'.number_format($p/100,2);}
function now(): string {return date('Y-m-d H:i:s');}
function demo(): bool {global $config; return ($config['mode']??'production')==='demo';}
function fail(string $message): never {throw new RuntimeException($message);}
function text(string $key,int $max=250,bool $required=true): string {
    $v=trim((string)($_POST[$key]??'')); if(($required&&$v==='')||strlen($v)>$max) fail("Invalid $key."); return $v;
}
function number(string $key,int $min=1,int $max=2147483647): int {
    $v=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT); if($v===false||$v===null||$v<$min||$v>$max) fail("Invalid $key."); return $v;
}
function amount(string $key): int {
    $v=text($key,15); if(!preg_match('/^\d{1,8}(\.\d{1,2})?$/',$v)) fail('Enter a valid rupee amount.');
    $parts=explode('.',$v); return ((int)$parts[0])*100+(int)str_pad($parts[1]??'',2,'0');
}
function validDate(string $date): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date); if(!$d||$d->format('Y-m-d')!==$date) fail('Invalid date.'); return $date; }
function expiry(string $start,int $months): string {
    $d=new DateTimeImmutable($start); $first=$d->modify('first day of this month')->modify("+$months months");
    return $first->setDate((int)$first->format('Y'),(int)$first->format('m'),min((int)$d->format('d'),(int)$first->format('t')))->format('Y-m-d');
}
function plate(string $v): string { $v=strtoupper(preg_replace('/[\s-]+/','',$v)); if(!preg_match('/^[A-Z0-9]{6,15}$/',$v)) fail('Enter a valid bike registration number.'); return $v; }
function audit(string $action,string $entity,int $id,array $details=[]): void {
    insert('audit_logs',['user_id'=>$_SESSION['uid']??null,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'details'=>json_encode($details,JSON_THROW_ON_ERROR),'created_at'=>now()]);
}
const ROLES=['admin'=>'Super Admin','partner'=>'Location Partner','supervisor'=>'Quality Supervisor','captain'=>'Wash Captain','washer'=>'Wash Specialist','helper'=>'Wash Assistant','customer'=>'Customer'];
const CHECKLIST=['water'=>'Water supply ready','drainage'=>'Drainage ready','power'=>'Electricity ready','equipment'=>'Equipment inspected','staff'=>'Both shifts staffed','payment'=>'Payment setup ready','inspection'=>'Opening inspection approved'];
if(PHP_SAPI!=='cli') {
    ini_set('session.use_strict_mode','1');
    session_name('washfree'); session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>'/']); session_start();
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('Cache-Control: no-store');
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
}
function user(): ?array { return isset($_SESSION['uid'])?row('SELECT * FROM users WHERE id=? AND active=1',[$_SESSION['uid']]):null; }
function requireUser(): array {$u=user(); if(!$u) {header('Location: ?page=login');exit;}return $u;}
function allow(array $roles): array {$u=requireUser();if(!in_array($u['role'],$roles,true)) fail('You do not have permission for this action.');return $u;}
function locationAccess(int $id): void {
    $u=requireUser(); if(!row('SELECT id FROM locations WHERE id=?',[$id])) fail('Location not found.');
    if($u['role']!=='admin'&&!scalar('SELECT COUNT(*) FROM user_locations WHERE user_id=? AND location_id=?',[$u['id'],$id])) fail('Location access denied.');
}
function scope(string $column='location_id'): array {
    $u=requireUser(); if($u['role']==='admin') return ['1=1',[]];
    return ["$column IN (SELECT location_id FROM user_locations WHERE user_id=?)",[$u['id']]];
}
function csrf(): void {if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) fail('Session expired. Refresh and try again.');}
function redirect(string $page,string $message=''): never {if($message!=='') $_SESSION['flash']=$message; header('Location: ?page='.rawurlencode($page)); exit;}
