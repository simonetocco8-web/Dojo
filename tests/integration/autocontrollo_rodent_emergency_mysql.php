<?php
require_once __DIR__ . '/../../core/autocontrollo_rodent.php';
// Run against a dedicated, empty database: DOJO_TEST_MYSQL_DSN=mysql:host=127.0.0.1;dbname=dojo_emergency_test
$dsn = getenv('DOJO_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR, "Set DOJO_TEST_MYSQL_DSN to a dedicated empty test database.\n"); exit(1); }
$pdo = new PDO($dsn, getenv('DOJO_TEST_MYSQL_USER') ?: 'root', getenv('DOJO_TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function fails(callable $call) { try { $call(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES (1)');
// Start with the previous schema to exercise migration and preservation.
$pdo->exec("CREATE TABLE autocontrollo_rodent_inspections (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 season_start DATE NOT NULL, season_end DATE NOT NULL, scheduled_date DATE NOT NULL,
 status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
 operator_id INT UNSIGNED DEFAULT NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME DEFAULT NULL, email_sent_at DATETIME DEFAULT NULL,
 UNIQUE KEY uq_rodent_inspection_schedule (season_start, season_end, scheduled_date),
 FOREIGN KEY (operator_id) REFERENCES users(id)
) ENGINE=InnoDB");
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
$range=['start'=>$today->format('Y-m-d'), 'end'=>$today->modify('+30 days')->format('Y-m-d')];
ensure_autocontrollo_rodent_inspections_tables($pdo);
ensure_autocontrollo_rodent_inspections_tables($pdo);
$pdo->exec("INSERT INTO autocontrollo_rodent_traps (location) VALUES ('Test trap')");
$id=autocontrollo_rodent_create_emergency($pdo,$range,'now','',1);
check($pdo->query("SELECT COUNT(*) FROM autocontrollo_rodent_inspection_results WHERE inspection_id=$id")->fetchColumn()==1,'Trap snapshot missing');
fails(fn()=>autocontrollo_rodent_create_emergency($pdo,$range,'now','',1));
$future=autocontrollo_rodent_create_emergency($pdo,$range,'date',$today->modify('+1 day')->format('Y-m-d'),1);
check($pdo->query("SELECT started_at FROM autocontrollo_rodent_inspections WHERE id=$future")->fetchColumn()===null,'Scheduled inspection already started');
$pdo->exec("UPDATE autocontrollo_rodent_inspections SET status='completata' WHERE id=$id");
fails(fn()=>autocontrollo_rodent_start_emergency($pdo,$range,$future,1));
$calendar=autocontrollo_rodent_start($pdo,$range,$range['start'],1);
check($calendar!==$id,'Emergency consumed calendar slot');
$pending=autocontrollo_rodent_create_emergency($pdo,$range,'date',$range['start'],1);
fails(fn()=>autocontrollo_rodent_start_emergency($pdo,$range,$pending,1));
$pdo->exec("UPDATE autocontrollo_rodent_inspections SET status='completata' WHERE id=$calendar");
autocontrollo_rodent_start_emergency($pdo,$range,$pending,1);
check($pdo->query("SELECT status FROM autocontrollo_rodent_inspections WHERE id=$pending")->fetchColumn()==='in_corso','Emergency not started');
fails(fn()=>autocontrollo_rodent_start_emergency($pdo,$range,$pending,1));
echo "Integration passed: migration, same-day calendar/emergency, future scheduling, trap snapshot, open-procedure guard, activation.\n";
