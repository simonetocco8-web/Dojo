<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/autocontrollo_schema.php';
require_once __DIR__ . '/autocontrollo_settings.php';
foreach (['rodent','grounding','fire','pool','temperature','haccp','electrical'] as $controlModule) require_once __DIR__ . '/autocontrollo_' . $controlModule . '.php';

function login_workflow_tasks(PDO $pdo, array $user): array {
    ensure_task_user_assignments_table($pdo);
    $stmt = $pdo->prepare("SELECT t.* FROM tasks t WHERE t.status='aperto' AND t.deleted_at IS NULL AND EXISTS (SELECT 1 FROM task_user_assignments a WHERE a.task_id=t.id AND a.user_id=?) ORDER BY t.due_date, t.id");
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function login_workflow_controls(PDO $pdo, array $user): array {
    $range = get_summer_season_range($pdo);
    if (empty($range['start']) || empty($range['end'])) return [];
    $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
    $paths = ['electrical'=>'impianto_elettrico','grounding'=>'messa_a_terra','rodent'=>'derattizzazione','fire'=>'antincendio','pool'=>'piscina','temperature'=>'temperature','haccp'=>'haccp'];
    $result = [];
    foreach (autocontrollo_responsibility_procedures() as $procedure => $label) {
        if ((string)get_setting('autocontrollo_responsible_' . $procedure, '', $pdo) !== (string)$user['id']) continue;
        $ensure = 'ensure_autocontrollo_' . $procedure . '_inspections_tables';
        $ensure($pdo);
        $stmt = $pdo->prepare('SELECT * FROM autocontrollo_' . $procedure . '_inspections WHERE season_start=? AND season_end=? ORDER BY id');
        $stmt->execute([$range['start'], $range['end']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $open = array_values(array_filter($rows, static fn($r) => ($r['status'] ?? '') === 'in_corso'))[0] ?? null;
        $date = null; $type = null; $slot = null; $emergency = null;
        if ($open) $date = $open['scheduled_date'] ?? $open['inspection_date'];
        elseif ($procedure === 'pool') $date = autocontrollo_pool_next_required_date($range, array_column($rows, 'inspection_date'));
        elseif ($procedure === 'temperature') {
            $next = autocontrollo_temperature_next_due($range, $rows);
            if ($next && autocontrollo_temperature_is_available($next['date'], $next['slot'])) { $date = $next['date']; $slot = $next['slot']; }
        } elseif (in_array($procedure, ['grounding','electrical'], true)) {
            $schedule = $procedure === 'electrical' ? autocontrollo_electrical_schedule($pdo) : autocontrollo_grounding_schedule($range);
            foreach ($schedule as $key => $entry) if (!in_array($key, array_column($rows, 'inspection_type'), true)) { $date = $entry['date']; $type = $key; break; }
        } else {
            $scheduleFunction = 'autocontrollo_' . $procedure . '_schedule';
            $nextFunction = 'autocontrollo_' . $procedure . '_next_date';
            $done = array_filter($rows, static fn($r) => $procedure === 'rodent' ? empty($r['is_emergency']) : $r['status'] === 'completata');
            $date = $nextFunction($scheduleFunction($range), array_column($done, 'scheduled_date'));
            if ($procedure === 'rodent') foreach ($rows as $row) {
                if ($row['status'] === 'programmata' && $row['scheduled_date'] <= $today) { $emergency = (int)$row['id']; $date = $row['scheduled_date']; break; }
            }
        }
        if ($open || ($date !== null && $date <= $today)) $result[$procedure] = ['label'=>$label,'date'=>$date,'path'=>'/autocontrollo_' . $paths[$procedure] . '.php','inspection'=>$open ? (int)$open['id'] : null,'type'=>$type,'slot'=>$slot,'emergency'=>$emergency];
    }
    return $result;
}

function login_workflow_start_control(PDO $pdo, array $user, string $procedure): string {
    $controls = login_workflow_controls($pdo, $user);
    if (!isset($controls[$procedure]) || !autocontrollo_user_can_perform($user, $procedure, $pdo)) throw new RuntimeException('Procedura non disponibile o non assegnata.');
    $control = $controls[$procedure];
    $_SESSION['login_workflow_active'] = true;
    if ($control['inspection']) return $control['path'] . '?inspection=' . $control['inspection'];
    if ($procedure === 'pool') return $control['path'];
    $range = get_summer_season_range($pdo);
    if ($control['emergency']) { autocontrollo_rodent_start_emergency($pdo, $range, $control['emergency'], (int)$user['id']); $id = $control['emergency']; }
    elseif ($procedure === 'electrical') $id = autocontrollo_electrical_start($pdo, $control['type'], (int)$user['id']);
    elseif ($procedure === 'grounding') $id = autocontrollo_grounding_start($pdo, $range, $control['type'], (int)$user['id']);
    elseif ($procedure === 'temperature') $id = autocontrollo_temperature_start($pdo, $range, $control['date'], $control['slot'], (int)$user['id']);
    else { $start = 'autocontrollo_' . $procedure . '_start'; $id = $start($pdo, $range, $control['date'], (int)$user['id']); }
    return $control['path'] . '?inspection=' . $id;
}

function login_workflow_completion_time(string $value): string {
    $zone = new DateTimeZone('Europe/Rome');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
    if (!$date || $date->format('Y-m-d\TH:i') !== $value || $date > new DateTimeImmutable('now', $zone)) throw new InvalidArgumentException('Indicare una data e ora di completamento valida, non futura.');
    return $date->format('Y-m-d H:i:s');
}
