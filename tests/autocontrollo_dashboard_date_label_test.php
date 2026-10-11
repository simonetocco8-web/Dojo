<?php
require_once __DIR__ . '/../core/autocontrollo_gas.php';
$range = ['start'=>'2026-06-01','end'=>'2026-08-15'];
$schedule = autocontrollo_gas_schedule($range);
$next = autocontrollo_gas_next_date($schedule, []);
if (autocontrollo_dashboard_date_label($next, $range) !== '17/05/2026') throw new RuntimeException('Configured season next date wrong.');
$next = autocontrollo_gas_next_date($schedule, $schedule);
if (autocontrollo_dashboard_date_label($next, $range) !== 'Controlli stagionali completati') throw new RuntimeException('Completed schedule shown as unconfigured.');
if (autocontrollo_dashboard_date_label(null, ['start'=>null,'end'=>null]) !== 'Stagione non configurata') throw new RuntimeException('Missing season not detected.');
if (autocontrollo_dashboard_date_label(null, ['start'=>'2026-08-15','end'=>'2026-06-01']) !== 'Date stagione non valide') throw new RuntimeException('Reversed dates not detected.');
echo "Dashboard: prossima data, stagione mancante e controlli completati distinti.\n";
