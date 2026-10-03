<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

function autocontrollo_haccp_surfaces(): array {
  return [
    ['code'=>'work_surface','label'=>'Superficie Lavoro','frequency'=>'giornaliera'],
    ['code'=>'fridge_inside','label'=>'Frigo Superficie Interna','frequency'=>'settimanale'],
    ['code'=>'floor','label'=>'Pavimento','frequency'=>'giornaliera'],
    ['code'=>'waste_container','label'=>'Contenitore per Rifiuti','frequency'=>'giornaliera'],
    ['code'=>'utensils','label'=>'Utensili Vari','frequency'=>'giornaliera'],
    ['code'=>'fridge_outside','label'=>'Frigorifero Superficie Esterna','frequency'=>'giornaliera'],
  ];
}

function autocontrollo_haccp_schedule(array $range): array {
  $tz=new DateTimeZone('Europe/Rome');$start=!empty($range['start'])?DateTimeImmutable::createFromFormat('!Y-m-d',(string)$range['start'],$tz):false;$end=!empty($range['end'])?DateTimeImmutable::createFromFormat('!Y-m-d',(string)$range['end'],$tz):false;
  if(!$start||!$end||$start>$end)return[];$dates=[];for($date=$start;$date<=$end;$date=$date->modify('+1 day'))$dates[]=$date->format('Y-m-d');return $dates;
}

function autocontrollo_haccp_surfaces_for_date(array $range,string $date):array {
  $start=new DateTimeImmutable((string)$range['start']);$current=new DateTimeImmutable($date);$weekly=((int)$start->diff($current)->format('%a'))%7===0;
  return array_values(array_filter(autocontrollo_haccp_surfaces(),static fn($surface)=>$surface['frequency']==='giornaliera'||$weekly));
}

function autocontrollo_haccp_next_date(array $schedule,array $completedDates):?string{$completed=array_fill_keys(array_map('strval',$completedDates),true);foreach($schedule as $date)if(!isset($completed[$date]))return$date;return null;}

function autocontrollo_haccp_start(PDO $pdo,array $range,string $date,int $operatorId):int{
  $schedule=autocontrollo_haccp_schedule($range);if(!in_array($date,$schedule,true))throw new RuntimeException('Data HACCP non valida.');$today=new DateTimeImmutable('today',new DateTimeZone('Europe/Rome'));if($date>$today->format('Y-m-d'))throw new RuntimeException('Il controllo non è ancora disponibile.');
  $open=$pdo->prepare("SELECT id FROM autocontrollo_haccp_inspections WHERE season_start=? AND season_end=? AND status='in_corso' LIMIT 1");$open->execute([$range['start'],$range['end']]);if($open->fetchColumn()!==false)throw new RuntimeException('Completare la procedura già in corso.');
  $done=$pdo->prepare("SELECT scheduled_date FROM autocontrollo_haccp_inspections WHERE season_start=? AND season_end=? AND status='completata'");$done->execute([$range['start'],$range['end']]);if(autocontrollo_haccp_next_date($schedule,$done->fetchAll(PDO::FETCH_COLUMN))!==$date)throw new RuntimeException('Completare prima il controllo HACCP antecedente.');
  $pdo->beginTransaction();try{$stmt=$pdo->prepare('INSERT INTO autocontrollo_haccp_inspections(season_start,season_end,scheduled_date,operator_id)VALUES(?,?,?,?)');$stmt->execute([$range['start'],$range['end'],$date,$operatorId]);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare('INSERT INTO autocontrollo_haccp_inspection_results(inspection_id,surface_code,surface_label,frequency,sort_order)VALUES(?,?,?,?,?)');foreach(autocontrollo_haccp_surfaces_for_date($range,$date) as $index=>$surface)$insert->execute([$id,$surface['code'],$surface['label'],$surface['frequency'],$index+1]);$pdo->commit();return$id;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
}

function autocontrollo_haccp_send_report(PDO $pdo,int $id):int{
  $stmt=$pdo->prepare("SELECT i.*,TRIM(CONCAT_WS(' ',NULLIF(u.nome,''),NULLIF(u.cognome,''))) operator_name,u.email operator_email FROM autocontrollo_haccp_inspections i LEFT JOIN users u ON u.id=i.operator_id WHERE i.id=?");$stmt->execute([$id]);$inspection=$stmt->fetch(PDO::FETCH_ASSOC);if(!$inspection)return 0;$stmt=$pdo->prepare('SELECT * FROM autocontrollo_haccp_inspection_results WHERE inspection_id=? ORDER BY sort_order');$stmt->execute([$id]);$rows='';$anomalies=0;foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$row){$ok=(int)$row['is_clean']===1;if(!$ok)$anomalies++;$style=$ok?'':' style="background:#f8d7da;color:#842029;font-weight:bold"';$rows.='<tr'.$style.'><td>'.htmlspecialchars($row['surface_label'],ENT_QUOTES,'UTF-8').'</td><td>'.ucfirst($row['frequency']).'</td><td>'.($ok?'Sì':'NO — ANOMALIA').'</td></tr>';}$operator=trim((string)$inspection['operator_name'])?:(string)$inspection['operator_email'];$html='<h2>Autocontrollo Pulizia HACCP</h2><p><strong>Data:</strong> '.date('d/m/Y',strtotime($inspection['scheduled_date'])).'<br><strong>Eseguito:</strong> '.date('d/m/Y H:i',strtotime($inspection['completed_at']?:$inspection['started_at'])).'<br><strong>Operatore:</strong> '.htmlspecialchars($operator,ENT_QUOTES,'UTF-8').'<br><strong>Anomalie:</strong> '.$anomalies.'</p><table border="1" cellpadding="7"><tr><th>Superficie</th><th>Frequenza</th><th>Pulizia corretta</th></tr>'.$rows.'</table>';$users=$pdo->query("SELECT email,dipartimento FROM users WHERE is_active=1 AND deleted_at IS NULL AND email<>''")->fetchAll(PDO::FETCH_ASSOC);$sent=0;foreach($users as$recipient)if(user_has_department($recipient,'Amministrazione')&&filter_var($recipient['email'],FILTER_VALIDATE_EMAIL)&&send_mail($recipient['email'],'Esito autocontrollo pulizia HACCP'.($anomalies?' — ANOMALIE':''),$html))$sent++;if($sent)$pdo->prepare('UPDATE autocontrollo_haccp_inspections SET email_sent_at=NOW() WHERE id=?')->execute([$id]);return$sent;
}
