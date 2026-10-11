<?php
require_once __DIR__ . '/../../core/login_workflow.php';
$dsn = getenv('DOJO_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR, "Use a dedicated empty test database.\n"); exit(1); }
$p = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,nome VARCHAR(50),cognome VARCHAR(50),email VARCHAR(100),dipartimento VARCHAR(100),is_active TINYINT,deleted_at DATETIME NULL)');
$p->exec("INSERT INTO users VALUES (1,'Test','Operator','operator@example.invalid','Manutenzione',1,NULL),(2,'Test','Admin','admin@example.invalid','Amministrazione',1,NULL),(3,'Inactive','Admin','inactive@example.invalid','Amministrazione',0,NULL)");
ensure_autocontrollo_gas_inspections_tables($p);
ensure_autocontrollo_gas_inspections_tables($p);
set_setting('summer_season_start','2026-06-01',$p);set_setting('summer_season_end','2026-08-15',$p);
autocontrollo_save_responsible($p,'gas','1');set_setting('autocontrollo_gas_alert_level','200',$p);
$user=['id'=>1,'is_active'=>1,'dipartimento'=>'Manutenzione'];
$controls=login_workflow_controls($p,$user);
if (($controls['gas']['date']??null)!=='2026-05-17') throw new RuntimeException('Gas not in due workflow.');
$range=get_summer_season_range($p);
$id=autocontrollo_gas_start($p,$range,'2026-05-17',1);
if(autocontrollo_gas_start($p,$range,'2026-05-17',1)!==$id)throw new RuntimeException('Duplicate start.');
autocontrollo_gas_complete($p,$id,'200',1);
$row=$p->query('SELECT * FROM autocontrollo_gas_inspections')->fetch(PDO::FETCH_ASSOC);
if($row['status']!=='completata'||(float)$row['liters']!==200.0||(int)$row['alert_threshold']!==200)throw new RuntimeException('Completion not persisted.');
try{autocontrollo_gas_complete($p,$id,'100',1);throw new LogicException('Completed record overwritten.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
set_setting('autocontrollo_gas_alert_level','500',$p);
if((int)$p->query('SELECT alert_threshold FROM autocontrollo_gas_inspections')->fetchColumn()!==200)throw new RuntimeException('Historical threshold changed.');
if(login_workflow_controls($p,$user)['gas']['date']!=='2026-06-16')throw new RuntimeException('Next date wrong.');
// The runner must override sendmail_path with a local sink, never deliver email.
$report=autocontrollo_gas_send_report($p,$id);
if($report!==['sent'=>1,'total'=>1])throw new RuntimeException('Recipient filtering wrong.');
echo "Gas DB: avvio, completamento, soglia storica, workflow e destinatari verificati.\n";
