<?php
require_once __DIR__ . '/../core/login_workflow.php';
$value = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->modify('-1 hour')->format('Y-m-d\TH:i');
if (login_workflow_completion_time($value) !== str_replace('T', ' ', $value) . ':00') throw new RuntimeException('Completion time not preserved.');
foreach (['', '2026-02-30T12:00', (new DateTimeImmutable('now'))->modify('+2 days')->format('Y-m-d\TH:i')] as $value) {
    try { login_workflow_completion_time($value); } catch (InvalidArgumentException $exception) { continue; }
    throw new RuntimeException('Invalid completion time accepted.');
}
echo "Date completamento task verificate.\n";
