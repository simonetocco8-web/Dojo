<?php
require_once __DIR__ . '/../../core/booking_linen.php';
$dsn=getenv('DOJO_TEST_MYSQL_DSN');if(!$dsn){fwrite(STDERR,"Use a dedicated empty DOJO_TEST_MYSQL_DSN database.\n");exit(1);}
$p=new PDO($dsn,getenv('DOJO_TEST_MYSQL_USER')?:'root',getenv('DOJO_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ensure_booking_linen_table($p);ensure_booking_linen_table($p);
function csv_data(string $name='Test',string $treatment='23 - BB (Adulti: 2 Bambini: 2)'):string {
 $f=fopen('php://temp','w+');fputcsv($f,['numero di riferimento','prenotante','Data inizio soggiorno','Data partenza','Num. notti','Trattamenti','Stato'],',','"','');
 fputcsv($f,['R1',$name,'01/10/2026','10/10/2026','9',$treatment,'Confermate'],',','"','');
 fputcsv($f,['OLD','Expired','01/09/2026','09/09/2026','8','2 - BB (Adulti: 2)','Confermate'],',','"','');
 fputcsv($f,['SHORT','Short','01/10/2026','08/10/2026','7','2 - BB (Adulti: 2)','Confermate'],',','"','');
 fputcsv($f,['CANCEL','Cancelled','01/10/2026','10/10/2026','9','2 - BB (Adulti: 2)','Cancellate'],',','"','');
 rewind($f);return stream_get_contents($f);
}
$now=new DateTimeImmutable('2026-10-10',new DateTimeZone('Europe/Rome'));
$r=booking_linen_import($p,csv_data(),$now);
if($r['created']!==2||$r['expired']!==1||$r['skipped']!==2)throw new RuntimeException('Initial import wrong.');
$r=booking_linen_import($p,csv_data(),$now);if($r['unchanged']!==1||$r['updated']!==0)throw new RuntimeException('Repeated import changed existing booking.');
$r=booking_linen_import($p,csv_data('Updated','16 - BB (Adulti: 4), 9 - BB (Adulti: 2 Bambini: 2)'),$now);
if($r['updated']!==1)throw new RuntimeException('Changes not applied.');
$rows=$p->query('SELECT * FROM booking_linen_reservations')->fetchAll(PDO::FETCH_ASSOC);
if(count($rows)!==1||$rows[0]['booker']!=='Updated'||count(booking_linen_schedule($rows))!==2)throw new RuntimeException('Multi-room update failed.');
try{booking_linen_import($p,csv_data('Broken','invalid'),$now);throw new LogicException('Invalid CSV accepted.');}catch(InvalidArgumentException $e){}
if($p->query('SELECT booker FROM booking_linen_reservations')->fetchColumn()!=='Updated')throw new RuntimeException('Failed import changed existing data.');
echo "Import: filtri, upsert, ripetibilità, scadenze, camere multiple e atomicità verificati.\n";
