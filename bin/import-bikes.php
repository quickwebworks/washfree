<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(PHP_SAPI!=='cli')exit;
if($argc!==2||!is_file($argv[1])||filesize($argv[1])>5*1024*1024)exit("Usage: php bin/import-bikes.php /private/path/bike-catalogue.csv (max 5 MB)\n");
$f=fopen($argv[1],'rb');$header=fgetcsv($f,0,',','"','');$expected=['company','model','photo_url','source_url','permission_note'];
if($header!==$expected)exit('Required headers: '.implode(',',$expected)."\n");$records=[];$seen=[];
try{
    while(($r=fgetcsv($f,0,',','"',''))!==false){
        if(!array_filter($r,fn($v)=>trim((string)$v)!==''))continue;
        if(count($r)!==5)fail('Invalid column count at row '.(count($records)+2));$data=array_combine($expected,array_map('trim',$r));
        if($data['company']===''||strlen($data['company'])>100||$data['model']===''||strlen($data['model'])>150)fail('Invalid company/model.');
        foreach(['photo_url','source_url'] as $field)if($data[$field]!==''&&(!filter_var($data[$field],FILTER_VALIDATE_URL)||parse_url($data[$field],PHP_URL_SCHEME)!=='https'||strlen($data[$field])>2000))fail('Use HTTPS photo/source URLs.');
        if(strlen($data['permission_note'])>1000||($data['photo_url']!==''&&($data['permission_note']===''||$data['source_url']==='')))fail('Photos require a source and permission/licence note.');
        $key=strtolower($data['company'].'|'.$data['model']);if(isset($seen[$key]))fail('Duplicate model in file.');$seen[$key]=true;$records[]=$data;if(count($records)>10000)fail('Maximum 10,000 models per batch.');
    }fclose($f);
    atomic(function()use($records){foreach($records as $data){$old=row('SELECT id FROM bike_models WHERE company=? AND model=?',[$data['company'],$data['model']]);if($old)q('UPDATE bike_models SET photo_url=?,source_url=?,permission_note=? WHERE id=?',[$data['photo_url'],$data['source_url'],$data['permission_note'],$old['id']]);else insert('bike_models',$data+['active'=>1]);audit('catalogue_import','bike_models',(int)($old['id']??scalar('SELECT id FROM bike_models WHERE company=? AND model=?',[$data['company'],$data['model']])));}});
    echo count($records)." models imported. Supplied photo URLs are stored, not downloaded or licence-verified automatically.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
