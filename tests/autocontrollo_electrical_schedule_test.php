<?php

require_once __DIR__ . '/../core/autocontrollo_electrical.php';

$schedule = [
    'pre_apertura' => ['date' => '2026-05-24'],
    'post_chiusura' => ['date' => '2026-09-21'],
];
$range = ['start' => '2026-05-31', 'end' => '2026-09-20'];
$inspections = [
    [
        'id' => 10,
        'season_start' => '2026-05-31',
        'season_end' => '2026-09-20',
        'inspection_type' => 'pre_apertura',
        'started_at' => '2026-06-07 09:00:00',
        'status' => 'completata',
    ],
    [
        'id' => 11,
        'season_start' => '2025-06-01',
        'season_end' => '2025-09-20',
        'inspection_type' => 'post_chiusura',
        'started_at' => '2025-09-22 09:00:00',
        'status' => 'completata',
    ],
];

$matched = autocontrollo_electrical_schedule_inspections($schedule, $inspections, $range);
if (($matched['pre_apertura']['id'] ?? null) !== 10) {
    throw new RuntimeException('Il controllo pre-apertura postumo non è stato attribuito alla scadenza prevista.');
}
if (isset($matched['post_chiusura'])) {
    throw new RuntimeException('Una procedura di una stagione differente non deve risultare eseguita nella stagione corrente.');
}

echo "Attribuzione stagionale delle procedure postume verificata.\n";
