<?php
require_once __DIR__ . '/../../core/user_alerts.php';
$dsn=getenv('DOJO_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR,"Use a dedicated empty DOJO_TEST_MYSQL_DSN database.\n"); exit(1); }
$pdo=new PDO($dsn,getenv('DOJO_TEST_MYSQL_USER')?:'root',getenv('DOJO_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active TINYINT DEFAULT 1,deleted_at DATETIME NULL)');
$pdo->exec('INSERT INTO users (id) VALUES (1),(2)');
$pdo->exec("CREATE TABLE tasks (id INT UNSIGNED PRIMARY KEY,title VARCHAR(190),due_date DATE,status VARCHAR(20),deleted_at DATETIME NULL)");
$pdo->exec("INSERT INTO tasks VALUES (1,'Mine',CURDATE(),'aperto',NULL),(2,'Other',CURDATE(),'aperto',NULL)");
ensure_task_user_assignments_table($pdo);$pdo->exec('INSERT INTO task_user_assignments(task_id,user_id) VALUES (1,1),(2,2)');
$user=['id'=>1,'is_active'=>1,'dipartimento'=>'Bar'];
$today=(new DateTimeImmutable('today',new DateTimeZone('Europe/Rome')))->format('Y-m-d');
set_setting('summer_season_start',$today,$pdo);set_setting('summer_season_end',$today,$pdo);
autocontrollo_save_responsible($pdo,'rodent','1');
$normal=['pressure'=>1020,'wind_gust'=>0];
$result=user_alerts_collect($pdo,$user,['pressure'=>1013,'wind_gust'=>31]);
if ($result['count']!==4 || $result['unavailable']!==[]) throw new RuntimeException('Wrong alert count or dependencies failed.');
if ($result['alerts'][0]['url']!=='/login_workflow.php?task=1') throw new RuntimeException('Task link wrong.');
if ($result['alerts'][1]['url']!=='/login_workflow.php#control-rodent') throw new RuntimeException('Control link wrong.');
$other=user_alerts_collect($pdo,['id'=>2,'is_active'=>1,'dipartimento'=>'Bar'],$normal);
if ($other['count']!==1 || $other['alerts'][0]['title']!=='Other') throw new RuntimeException('Another user alert leaked.');
$pdo->exec("UPDATE tasks SET status='completato' WHERE id=1");
if (user_alerts_collect($pdo,$user,$normal)['count']!==1) throw new RuntimeException('Completed task still counted.');
autocontrollo_save_responsible($pdo,'rodent','2');
if (user_alerts_collect($pdo,$user,$normal)['count']!==0) throw new RuntimeException('Reassigned control still counted.');
set_setting('atmospheric_pressure_alert_threshold','1000',$pdo);
if (user_alerts_collect($pdo,$user,['pressure'=>1013,'wind_gust'=>0])['count']!==0) throw new RuntimeException('Configured pressure threshold ignored.');
echo "Campanella: conteggio, link, isolamento utenti, completamento e riassegnazione verificati.\n";
