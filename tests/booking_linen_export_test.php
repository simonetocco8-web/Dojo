<?php
require_once __DIR__ . '/../core/booking_linen.php';
$today = '2026-10-11';
$base = ['booker'=>'Rossi; "Mario"','room'=>'23','adults'=>2,'children'=>2,'infants'=>0,'double'=>1,'single'=>2,'bath_sets'=>4];
$changes = [array_merge($base,['date'=>'2026-10-10']),array_merge($base,['date'=>$today]),array_merge($base,['date'=>'2026-10-12'])];
$stream = fopen('php://temp','w+');
booking_linen_export_labels($stream,$changes,$today);
rewind($stream);
if(fread($stream,3)!=="\xEF\xBB\xBF")throw new RuntimeException('Missing UTF-8 BOM.');
$head=fgetcsv($stream,0,';','"','');$row=fgetcsv($stream,0,';','"','');
if($head!==['Data cambio','Prenotante','Camera','Occupanti','Matrimoniali','Singole','Set da bagno'])throw new RuntimeException('Wrong export columns.');
if($row!==['11/10/2026','Rossi; "Mario"','23','2 adulti, 2 bambini','1','2','4']||fgetcsv($stream,0,';','"','')!==false)throw new RuntimeException('Wrong rows or escaping.');
fclose($stream);
$stream=fopen('php://temp','w+');booking_linen_export_labels($stream,[],$today);rewind($stream);fread($stream,3);fgetcsv($stream,0,';','"','');if(fgetcsv($stream,0,';','"','')!==false)throw new RuntimeException('Empty export wrong.');fclose($stream);
$stream=fopen('php://temp','w+');booking_linen_export_labels($stream,[array_merge($base,['date'=>$today,'booker'=>'=1+1','infants'=>1,'bath_sets'=>5])],$today);rewind($stream);fread($stream,3);fgetcsv($stream,0,';','"','');$row=fgetcsv($stream,0,';','"','');if($row[1]!=="'=1+1"||$row[3]!=='2 adulti, 2 bambini, 1 neonati'||$row[6]!=='5')throw new RuntimeException('Imported text or infants wrong.');fclose($stream);
echo "Export etichette: filtro oggi, colonne, CSV e file vuoto verificati.\n";
