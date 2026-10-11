<?php
require_once __DIR__ . '/../core/user_alerts.php';
$alerts = user_alerts_weather(['pressure'=>1005.5,'wind_gust'=>30.1], 1005.5);
if (count($alerts) !== 2 || $alerts[1]['title'] !== 'Allerta pressione atmosferica') throw new RuntimeException('Weather alerts missing.');
if (user_alerts_weather(['pressure'=>1005.6,'wind_gust'=>30],1005.5) !== []) throw new RuntimeException('Normal weather generated alerts.');
if (user_alerts_weather(['pressure'=>null,'wind_gust'=>null],1013) !== []) throw new RuntimeException('Missing readings generated alerts.');
echo "Campanella: allerte meteo e soglia configurabile verificate.\n";

$tasks = [];
foreach (['2026-10-10','2026-10-11','2026-10-12',null,'2026-02-30'] as $index => $date) {
    $tasks[] = ['id'=>$index + 1,'title'=>'Task ' . $index,'due_date'=>$date];
}
$alerts = user_alerts_tasks($tasks, new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('Europe/Rome')));
if (count($alerts) !== 2 || $alerts[0]['summary'] !== 'Task aperto · Scadenza: 10/10/2026' || $alerts[1]['summary'] !== 'Task aperto · Scadenza: 11/10/2026') throw new RuntimeException('Future tasks counted or date format wrong.');
$alerts = user_alerts_tasks($tasks, new DateTimeImmutable('2026-10-10 22:30:00', new DateTimeZone('UTC')));
if (count($alerts) !== 2) throw new RuntimeException('Italian midnight boundary wrong.');
echo "Campanella: task futuri esclusi, date italiane e confine di mezzanotte verificati.\n";
