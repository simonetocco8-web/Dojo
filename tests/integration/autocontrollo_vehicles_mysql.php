<?php
require_once __DIR__ . '/../../core/autocontrollo_vehicles.php';
$dsn=getenv('DOJO_TEST_MYSQL_DSN');
if(!$dsn){fwrite(STDERR,"Use a dedicated empty DOJO_TEST_MYSQL_DSN database.\n");exit(1);}
$p=new PDO($dsn,getenv('DOJO_TEST_MYSQL_USER')?:'root',getenv('DOJO_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ensure_autocontrollo_vehicles_table($p);ensure_autocontrollo_vehicles_table($p);
$id=autocontrollo_vehicle_save($p,null,' Fiat ',' Panda ','AB123CD');
$untagged=autocontrollo_vehicle_save($p,null,'Club Car','Carryall','');
if($p->query("SELECT license_plate FROM autocontrollo_vehicles WHERE id=$untagged")->fetchColumn()!==null)throw new RuntimeException('Unplated vehicle not supported.');
autocontrollo_vehicle_save($p,$id,'Fiat','Ducato','');
$row=$p->query("SELECT * FROM autocontrollo_vehicles WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if($row['model']!=='Ducato'||$row['license_plate']!==null)throw new RuntimeException('Update failed.');
autocontrollo_vehicle_save($p,$id,'Fiat','Ducato','AB123CD');
foreach([['','Panda',''],['Fiat','',''],['Fiat','Panda',str_repeat('A',33)]] as $values){
 try{autocontrollo_vehicle_save($p,null,...$values);}catch(InvalidArgumentException $e){continue;}
 throw new RuntimeException('Invalid fields accepted.');
}
autocontrollo_vehicle_delete($p,$untagged);
if((int)$p->query('SELECT COUNT(*) FROM autocontrollo_vehicles')->fetchColumn()!==1)throw new RuntimeException('Delete failed.');
try{autocontrollo_vehicle_save($p,999,'Fiat','Panda','');throw new LogicException('Missing vehicle updated.');}catch(InvalidArgumentException $e){}
echo "Veicoli: creazione, modifica, targa facoltativa, eliminazione e validazione verificati.\n";
