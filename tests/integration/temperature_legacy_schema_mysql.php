<?php
// Isolated process: simulate the missing helpers in an older core/db.php.
require_once __DIR__ . '/../../core/autocontrollo_temperature_schema.php';
$dsn = getenv('DOJO_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR, "Use a dedicated empty DOJO_TEST_MYSQL_DSN database.\n"); exit(1); }
$pdo = new PDO($dsn, getenv('DOJO_TEST_MYSQL_USER') ?: 'root', getenv('DOJO_TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
ensure_autocontrollo_temperature_inspections_tables($pdo);
ensure_autocontrollo_temperature_inspections_tables($pdo);
$pdo->exec("INSERT INTO users VALUES (1)");
$pdo->exec("INSERT INTO autocontrollo_refrigerators VALUES ('R1','frigorifero',4,NOW(),NOW())");
$pdo->exec("INSERT INTO autocontrollo_temperature_inspections (season_start,season_end,inspection_date,time_slot,operator_id) VALUES ('2026-05-01','2026-09-30','2026-05-01','mattina',1)");
$pdo->exec("INSERT INTO autocontrollo_temperature_results (inspection_id,refrigerator_id,refrigerator_label,appliance_type,operating_temperature,sort_order) VALUES (1,'R1','R1','frigorifero',4,1)");
if ((int)$pdo->query('SELECT COUNT(*) FROM autocontrollo_temperature_results')->fetchColumn() !== 1) throw new RuntimeException('Temperature schema unusable.');
echo "Legacy temperature schema: creation, repeatability and inspection results verified.\n";
