<?php
require_once __DIR__ . '/../core/booking_linen.php';
$rooms=booking_linen_rooms('16 - BB (Adulti: 2 Bambini: 2), 9 - BB (Adulti: 2 Bambini: 2)');
if(count($rooms)!==2||$rooms[1]['room']!=='9')throw new RuntimeException('Multiple rooms lost.');
foreach([[2,2,1,2,false],[2,0,1,0,false],[3,0,1,1,false],[4,0,1,2,true],[1,0,0,1,false]] as [$adults,$children,$double,$single,$review]){
 $schedule=booking_linen_schedule([['reference'=>'T','booker'=>'Test','check_in'=>'2026-10-01','nights'=>9,'status'=>'Confermate','rooms_json'=>json_encode([['room'=>'23','adults'=>$adults,'children'=>$children]])]]);
 if($schedule[0]['date']!=='2026-10-05'||$schedule[0]['double']!==$double||$schedule[0]['single']!==$single||$schedule[0]['review']!==$review)throw new RuntimeException('Linen calculation wrong.');
}
try{booking_linen_rooms('23 - BB (Adulti: x)');throw new LogicException('Invalid room accepted.');}catch(InvalidArgumentException $e){}
try{booking_linen_date('31/02/2026');throw new LogicException('Invalid date accepted.');}catch(InvalidArgumentException $e){}
echo "CSV camere multiple, date e calcoli biancheria verificati.\n";
