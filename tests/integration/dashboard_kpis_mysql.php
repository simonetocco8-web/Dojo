<?php
require_once __DIR__ . '/../../core/dashboard_kpis.php';
$dsn=getenv('DOJO_TEST_MYSQL_DSN');
if(!$dsn){fwrite(STDERR,"Use a dedicated empty DOJO_TEST_MYSQL_DSN database.\n");exit(1);}
$p=new PDO($dsn,getenv('DOJO_TEST_MYSQL_USER')?:'root',getenv('DOJO_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY)');$p->exec('INSERT INTO users VALUES(1),(2)');
$p->exec('CREATE TABLE tasks(id INT PRIMARY KEY,status VARCHAR(20),deleted_at DATETIME NULL,dipartimento VARCHAR(50))');
$p->exec("INSERT INTO tasks VALUES(1,'aperto',NULL,'Bar'),(2,'aperto',NULL,'Bar'),(3,'aperto',NULL,'Bar'),(4,'aperto',NULL,'Bar'),(5,'aperto',NULL,'Bar'),(6,'aperto',NULL,'Bar'),(7,'completato',NULL,'Bar'),(8,'aperto',NOW(),'Bar')");
$p->exec('CREATE TABLE task_user_assignments(task_id INT,user_id INT)');$p->exec('INSERT INTO task_user_assignments VALUES(2,2),(6,1)');
$p->exec('CREATE TABLE riassetti(data_riassetto DATE,status VARCHAR(20),completed_at DATETIME NULL)');
$p->exec("INSERT INTO riassetti VALUES('2026-10-09','da_preparare',NULL),('2026-10-08','',NULL),('2026-10-09','concluso',NOW()),('2026-10-10','da_preparare',NULL)");
$p->exec('CREATE TABLE transfers_internal(when_at DATETIME,deleted_at DATETIME NULL)');
$p->exec("INSERT INTO transfers_internal VALUES('2026-10-09 00:00:00',NULL),('2026-10-09 23:59:00',NULL),('2026-10-08 12:00:00',NULL),('2026-10-09 12:00:00',NOW())");
$p->exec('CREATE TABLE transfers_external(date_time DATETIME,deleted_at DATETIME NULL,status VARCHAR(20))');
$p->exec("INSERT INTO transfers_external VALUES('2026-10-09 15:00:00',NULL,'attivo'),('2026-10-09 15:00:00',NULL,'annullato'),('2026-10-10 00:00:00',NULL,'attivo')");
$p->exec('CREATE TABLE parking_spaces(status VARCHAR(20))');$p->exec("INSERT INTO parking_spaces VALUES('libero'),('libero'),('occupato')");
$p->exec('CREATE TABLE products(id INT PRIMARY KEY,is_active TINYINT,min_qty INT)');$p->exec('INSERT INTO products VALUES(1,1,5),(2,1,1),(3,0,5),(4,1,5)');
$p->exec('CREATE TABLE stock_levels(product_id INT,qty INT)');$p->exec('INSERT INTO stock_levels VALUES(1,1),(1,1),(4,10)');
set_setting('summer_season_start','2026-05-01',$p);set_setting('summer_season_end','2026-10-31',$p);
ensure_autocontrollo_haccp_inspections_tables($p);
$p->exec("INSERT INTO autocontrollo_haccp_inspections(season_start,season_end,scheduled_date,status) VALUES('2026-05-01','2026-10-31','2026-10-09','completata'),('2025-05-01','2025-10-31','2025-10-09','completata')");
$p->exec("INSERT INTO autocontrollo_haccp_inspection_results(inspection_id,surface_code,surface_label,frequency,sort_order,is_clean) VALUES(1,'a','A','giornaliera',1,0),(1,'b','B','giornaliera',2,1),(1,'c','C','giornaliera',3,NULL),(2,'a','A','giornaliera',1,0)");
$now=new DateTimeImmutable('2026-10-09 12:00:00',new DateTimeZone('Europe/Rome'));
$cards=dashboard_kpis($p,['id'=>1,'role'=>'admin','dipartimento'=>'Amministrazione','is_active'=>1],$now);
if(array_column($cards,'value')!==[6,2,3,2,1,2])throw new RuntimeException('Wrong full totals: '.json_encode($cards));
$limited=dashboard_kpis($p,['id'=>2,'role'=>'editor','dipartimento'=>'Manutenzione','is_active'=>1],$now);
if(count($limited)!==2||$limited[0]['value']!==1)throw new RuntimeException('Permission or assignment leak.');
foreach($cards as $card)if(!str_starts_with($card['url'],'/'))throw new RuntimeException('Module link missing.');
echo "KPI: sei conteggi, totali oltre il limite dei box, date, annullamenti, stock, stagione e permessi verificati.\n";
