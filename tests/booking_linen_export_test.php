<?php
require_once __DIR__ . '/../core/booking_linen.php';
$today = '2026-10-11';
$base = ['booker'=>"D'Angelo, \"Mario\"\nRossi",'room'=>'23','adults'=>2,'children'=>2,'infants'=>0,'double'=>1,'single'=>2,'bath_sets'=>4];
$changes = [array_merge($base,['date'=>'2026-10-10']),array_merge($base,['date'=>$today]),array_merge($base,['date'=>'2026-10-12'])];
function export_text(array $changes, string $today): string {
    $stream = fopen('php://temp','w+');
    booking_linen_export_labels($stream,$changes,$today);
    rewind($stream); $content = stream_get_contents($stream); fclose($stream);
    if (str_contains($content,"'") || str_contains($content,'"')) throw new RuntimeException('Quotes in CSV.');
    if (!str_starts_with($content,"\xEF\xBB\xBF")) throw new RuntimeException('Missing UTF-8 BOM.');
    return substr($content,3);
}
$content = export_text($changes,$today);
$lines = explode("\r\n",trim($content));
if (count($lines)!==2) throw new RuntimeException('Wrong daily filter or newline handling.');
if ($lines[0] !== 'Data cambio,Prenotante,Camera,Occupanti,Matrimoniali,Singole,Set da bagno') throw new RuntimeException('Wrong header.');
if (explode(',',$lines[1]) !== ['11/10/2026','DAngelo  Mario Rossi','23','2 adulti  2 bambini','1','2','4']) throw new RuntimeException('Wrong values or delimiters.');
if (count(explode("\r\n",trim(export_text([],$today))))!==1) throw new RuntimeException('Empty CSV wrong.');
$content=export_text([array_merge($base,['date'=>$today,'booker'=>'=1+1','infants'=>1,'bath_sets'=>5])],$today);
$row=explode(',',explode("\r\n",$content)[1]);
if ($row[1]!=='1+1'||$row[3]!=='2 adulti  2 bambini  1 neonati'||$row[6]!=='5') throw new RuntimeException('Formula or infants wrong.');
echo "Export etichette: virgole, assenza apici, sette colonne e filtro oggi verificati.\n";
