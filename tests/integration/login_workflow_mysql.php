<?php
require_once __DIR__ . '/../../core/login_workflow.php';
$dsn=getenv('DOJO_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR,"Use a dedicated empty DOJO_TEST_MYSQL_DSN database.\n"); exit(1); }
$pdo=new PDO($dsn,getenv('DOJO_TEST_MYSQL_USER')?:'root',getenv('DOJO_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active TINYINT DEFAULT 1,deleted_at DATETIME NULL)');
$pdo->exec('INSERT INTO users (id) VALUES (1),(2)');
$pdo->exec("CREATE TABLE tasks (id INT UNSIGNED PRIMARY KEY,title VARCHAR(190),description TEXT,due_date DATE,priority VARCHAR(20),status VARCHAR(20),deleted_at DATETIME NULL)");
$pdo->exec("INSERT INTO tasks VALUES (1,'Mine','Detail',CURDATE(),'alta','aperto',NULL),(2,'Other','Detail',CURDATE(),'alta','aperto',NULL),(3,'Done','Detail',CURDATE(),'alta','completato',NULL),(4,'Deleted','Detail',CURDATE(),'alta','aperto',NOW())");
ensure_task_user_assignments_table($pdo);
$pdo->exec('INSERT INTO task_user_assignments (task_id,user_id) VALUES (1,1),(2,2),(3,1),(4,1)');
function expect($condition,$message) { if(!$condition)throw new RuntimeException($message); }
$user=['id'=>1,'is_active'=>1,'dipartimento'=>'Bar'];
expect(array_column(login_workflow_tasks($pdo,$user),'id')===[1],'Task visibility wrong');
$today=new DateTimeImmutable('today',new DateTimeZone('Europe/Rome'));
$range=['start'=>$today->format('Y-m-d'),'end'=>$today->modify('+30 days')->format('Y-m-d')];
set_setting('summer_season_start',$range['start'],$pdo);set_setting('summer_season_end',$range['end'],$pdo);
expect(login_workflow_controls($pdo,$user)===[],'Unassigned controls shown');
autocontrollo_save_responsible($pdo,'rodent','1');
expect(autocontrollo_user_can_perform($user,'rodent',$pdo),'Responsible denied');
expect(!autocontrollo_user_can_perform($user,'haccp',$pdo),'Unassigned procedure accessible');
$controls=login_workflow_controls($pdo,$user);expect(isset($controls['rodent']),'Due rodent missing');
$pdo->exec("INSERT INTO autocontrollo_rodent_traps (location) VALUES ('Test trap')");
$url=login_workflow_start_control($pdo,$user,'rodent');expect(str_contains($url,'?inspection='),'Procedure not started');
expect(login_workflow_controls($pdo,$user)['rodent']['inspection']!==null,'Open procedure missing');
$pdo->exec("UPDATE autocontrollo_rodent_inspections SET status='completata'");
expect(login_workflow_controls($pdo,$user)===[],'Future control shown');
foreach (['electrical','grounding','fire','pool','temperature','haccp'] as $procedure) autocontrollo_save_responsible($pdo,$procedure,'1');
$allControls=login_workflow_controls($pdo,$user);
foreach (['electrical','grounding','fire','pool','temperature','haccp'] as $procedure) expect(isset($allControls[$procedure]), 'Due control missing: '.$procedure);
foreach (['electrical','grounding','fire','pool','temperature','haccp'] as $procedure) autocontrollo_save_responsible($pdo,$procedure,'');
autocontrollo_save_responsible($pdo,'rodent','2');
expect(login_workflow_controls($pdo,$user)===[],'Reassigned control shown');
try { login_workflow_start_control($pdo,$user,'rodent'); throw new LogicException('Reassigned control started'); } catch (RuntimeException $e) {}
echo "Login workflow: task isolation, due/future/completed controls, non-admin responsibility, start/resume and reassignment verified.\n";
