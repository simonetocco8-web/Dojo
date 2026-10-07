<?php
require_once __DIR__ . '/../../core/autocontrollo_settings.php';
$dsn = getenv('DOJO_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR, "Set DOJO_TEST_MYSQL_DSN to a dedicated empty test database.\n"); exit(1); }
$pdo = new PDO($dsn, getenv('DOJO_TEST_MYSQL_USER') ?: 'root', getenv('DOJO_TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INT PRIMARY KEY, is_active TINYINT NOT NULL, deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO users VALUES (1,1,NULL),(2,1,NULL),(3,0,NULL),(4,1,'2026-01-01')");
foreach (autocontrollo_responsibility_procedures() as $procedure => $label) {
    autocontrollo_save_responsible($pdo, $procedure, '1');
    if (get_setting('autocontrollo_responsible_' . $procedure, null, $pdo) !== '1') throw new RuntimeException('Assignment not saved.');
}
autocontrollo_save_responsible($pdo, 'haccp', '2');
if (get_setting('autocontrollo_responsible_haccp', null, $pdo) !== '2' || get_setting('autocontrollo_responsible_rodent', null, $pdo) !== '1') throw new RuntimeException('Update changed another procedure.');
foreach ([['haccp','3'],['haccp','4'],['haccp','999'],['haccp','-1'],['haccp','1x'],['unknown','1']] as [$procedure,$id]) {
    try { autocontrollo_save_responsible($pdo, $procedure, $id); }
    catch (InvalidArgumentException $exception) { continue; }
    throw new RuntimeException('Invalid assignment accepted.');
}
if (get_setting('autocontrollo_responsible_haccp', null, $pdo) !== '2') throw new RuntimeException('Rejected assignment changed saved value.');
autocontrollo_save_responsible($pdo, 'haccp', '');
if (get_setting('autocontrollo_responsible_haccp', null, $pdo) !== null) throw new RuntimeException('Assignment not removed.');
echo "Responsabili: salvataggio, modifica, rimozione, isolamento e validazione utenti verificati.\n";
