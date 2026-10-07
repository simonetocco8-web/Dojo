<?php
// Simula il caricamento della dashboard con un vecchio modulo privo degli helper.
require_once __DIR__ . '/../core/autocontrollo_rodent_schedule.php';
require_once __DIR__ . '/../core/autocontrollo_rodent_schedule.php';
$range = ['start'=>'2026-05-01', 'end'=>'2026-06-01'];
$schedule = autocontrollo_rodent_schedule($range);
if ($schedule !== ['2026-05-01','2026-05-16','2026-05-31']) throw new RuntimeException('Scadenze quindicinali errate.');
if (autocontrollo_rodent_next_date($schedule, ['2026-05-01']) !== '2026-05-16') throw new RuntimeException('Scadenza successiva errata.');
if (autocontrollo_rodent_next_date($schedule, $schedule) !== null) throw new RuntimeException('Stagione completata non riconosciuta.');
if (autocontrollo_rodent_schedule([]) !== []) throw new RuntimeException('Stagione non configurata non riconosciuta.');
// Il modulo aggiornato deve poter essere caricato anche dopo il fallback.
require_once __DIR__ . '/../core/autocontrollo_rodent.php';
echo "Compatibilità pianificazione derattizzazione e caricamento ripetuto verificati.\n";
