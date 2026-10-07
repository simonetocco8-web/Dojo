<?php
require_once __DIR__ . '/../core/autocontrollo_rodent.php';
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
if (autocontrollo_rodent_emergency_date('now', '') !== $today->format('Y-m-d')) throw new RuntimeException('Avvio immediato errato.');
$future = $today->modify('+3 days')->format('Y-m-d');
if (autocontrollo_rodent_emergency_date('date', $future) !== $future) throw new RuntimeException('Data programmata errata.');
foreach ([['date', ''], ['date', '2026-02-30'], ['date', $today->modify('-1 day')->format('Y-m-d')], ['invalid', $future]] as [$mode, $date]) {
    try { autocontrollo_rodent_emergency_date($mode, $date); }
    catch (InvalidArgumentException $exception) { continue; }
    throw new RuntimeException('Data o modalità non valida accettata.');
}
echo "Validazione date emergenza verificata.\n";
