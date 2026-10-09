<?php
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/autocontrollo_settings.php';
require_once __DIR__ . '/autocontrollo_schema.php';

function dashboard_kpis(PDO $pdo, array $user, ?DateTimeImmutable $now = null): array {
    $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable($today, new DateTimeZone('Europe/Rome')))->modify('+1 day')->format('Y-m-d');
    $count = static function (string $sql, array $args = []) use ($pdo): int {
        $stmt = $pdo->prepare($sql); $stmt->execute($args); return (int)$stmt->fetchColumn();
    };
    $kpis = [];
    $where = "t.status='aperto' AND t.deleted_at IS NULL";
    $args = [];
    if (!user_is_admin($user)) {
        $departments = user_departments($user);
        $departmentCondition = $departments ? 't.dipartimento IN (' . implode(',', array_fill(0, count($departments), '?')) . ')' : '0=1';
        $where .= ' AND ((NOT EXISTS (SELECT 1 FROM task_user_assignments a WHERE a.task_id=t.id) AND ' . $departmentCondition . ') OR EXISTS (SELECT 1 FROM task_user_assignments a WHERE a.task_id=t.id AND a.user_id=?))';
        $args = array_merge($departments, [$user['id']]);
    }
    $kpis[] = ['label'=>'Task aperti','value'=>$count('SELECT COUNT(*) FROM tasks t WHERE ' . $where, $args),'detail'=>'Da completare','icon'=>'list-check','url'=>'/tasks.php?view=' . (user_is_admin($user) ? 'tutti' : 'mio')];
    if (user_is_reception_or_amministrazione($user) || user_is_housekeeping($user)) {
        $kpis[] = ['label'=>'Riassetti da fare','value'=>$count("SELECT COUNT(*) FROM riassetti WHERE data_riassetto<=? AND COALESCE(NULLIF(status,''), CASE WHEN completed_at IS NULL THEN 'da_preparare' ELSE 'concluso' END)<>'concluso'", [$today]),'detail'=>'Oggi e arretrati','icon'=>'house-check','url'=>'/riassetti.php'];
    }
    $internal = $count('SELECT COUNT(*) FROM transfers_internal WHERE deleted_at IS NULL AND when_at>=? AND when_at<?', [$today, $tomorrow]);
    $external = $count("SELECT COUNT(*) FROM transfers_external WHERE deleted_at IS NULL AND COALESCE(status,'attivo') NOT IN ('annullato','rifiutato') AND date_time>=? AND date_time<?", [$today, $tomorrow]);
    $kpis[] = ['label'=>'Transfer del giorno','value'=>$internal+$external,'detail'=>$internal . ' interni · ' . $external . ' esterni','icon'=>'bus-front','url'=>'/transfere.php'];
    if (user_is_reception_or_amministrazione($user)) {
        $kpis[] = ['label'=>'Parcheggi disponibili','value'=>$count("SELECT COUNT(*) FROM parking_spaces WHERE status='libero'"),'detail'=>'Posti auto liberi','icon'=>'p-square','url'=>'/parking.php'];
    }
    if (autocontrollo_user_can_perform($user, 'haccp', $pdo)) {
        ensure_autocontrollo_haccp_inspections_tables($pdo);
        $range = get_summer_season_range($pdo);
        $kpis[] = ['label'=>'Anomalie HACCP','value'=>$count("SELECT COUNT(*) FROM autocontrollo_haccp_inspection_results r JOIN autocontrollo_haccp_inspections i ON i.id=r.inspection_id WHERE r.is_clean=0 AND i.status='completata' AND i.season_start=? AND i.season_end=?", [$range['start'] ?? '', $range['end'] ?? '']),'detail'=>'Rilevazioni nella stagione','icon'=>'shield-exclamation','url'=>'/autocontrollo_haccp.php'];
    }
    if (user_is_bar_or_amministrazione($user)) {
        $kpis[] = ['label'=>'Prodotti sottoscorta','value'=>$count('SELECT COUNT(*) FROM (SELECT p.id FROM products p LEFT JOIN stock_levels sl ON sl.product_id=p.id WHERE p.is_active=1 GROUP BY p.id,p.min_qty HAVING COALESCE(SUM(sl.qty),0)<p.min_qty) low_stock'),'detail'=>'Sotto la scorta minima','icon'=>'box-seam','url'=>'/inventory/products.php?quantity=low'];
    }
    return $kpis;
}
