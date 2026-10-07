<?php
// In questo processo db.php non è caricato: tutti gli helper sono assenti.
require_once __DIR__ . '/../../core/autocontrollo_schema.php';
$dsn = getenv('DOJO_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR, "Use a dedicated empty DOJO_TEST_MYSQL_DSN database.\n"); exit(1); }
$pdo = new PDO($dsn, getenv('DOJO_TEST_MYSQL_USER') ?: 'root', getenv('DOJO_TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES (1)');
foreach (['rodent','grounding','fire','pool','temperature','haccp','electrical'] as $procedure) {
    $ensure = 'ensure_autocontrollo_' . $procedure . '_inspections_tables';
    $ensure($pdo);
    $ensure($pdo);
    $pdo->query('SELECT * FROM autocontrollo_' . $procedure . '_inspections');
}
$pdo->exec("INSERT INTO autocontrollo_haccp_inspections (season_start,season_end,scheduled_date,operator_id) VALUES ('2026-05-01','2026-09-30','2026-05-01',1)");
$pdo->exec("INSERT INTO autocontrollo_haccp_inspection_results (inspection_id,surface_code,surface_label,frequency,sort_order,is_clean) VALUES (1,'test','Test surface','giornaliera',1,1)");
ensure_autocontrollo_haccp_inspections_tables($pdo);
if ((int)$pdo->query('SELECT COUNT(*) FROM autocontrollo_haccp_inspection_results')->fetchColumn() !== 1) throw new RuntimeException('Existing HACCP results lost.');
echo "All seven legacy schemas initialized twice; HACCP results preserved.\n";
