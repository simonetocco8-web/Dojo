<?php
require_once __DIR__ . '/login_workflow.php';
require_once __DIR__ . '/ecowitt_alerts.php';

function user_alerts_weather(array $weather, float $threshold): array {
    $alerts = [];
    foreach (ecowitt_weather_alert_definitions($weather, $threshold) as $type => $alert) {
        if (!$alert['active']) continue;
        $alerts[] = ['type'=>'weather', 'title'=>$type === 'pressure' ? 'Allerta pressione atmosferica' : 'Allerta raffiche di vento', 'summary'=>$alert['message'], 'url'=>'/dashboard.php#weather-alerts'];
    }
    return $alerts;
}

function user_alerts_collect(PDO $pdo, array $user, ?array $weather = null): array {
    $alerts = []; $errors = [];
    try {
        foreach (login_workflow_tasks($pdo, $user) as $task) {
            $alerts[] = ['type'=>'task','title'=>$task['title'],'summary'=>'Task aperto · Scadenza: ' . $task['due_date'], 'url'=>'/login_workflow.php?task=' . (int)$task['id']];
        }
    } catch (Throwable $exception) { error_log('[User alerts tasks] ' . $exception->getMessage()); $errors[] = 'Task'; }
    try {
        foreach (login_workflow_controls($pdo, $user) as $procedure => $control) {
            $alerts[] = ['type'=>'control','title'=>$control['label'],'summary'=>($control['inspection'] ? 'Procedura in corso' : 'Procedura da eseguire') . ' · ' . $control['date'] . (!empty($control['slot']) ? ' · ' . $control['slot'] : ''), 'url'=>'/login_workflow.php#control-' . $procedure];
        }
    } catch (Throwable $exception) { error_log('[User alerts controls] ' . $exception->getMessage()); $errors[] = 'Autocontrolli'; }
    try {
        $weather ??= ecowitt_fetch_temperature();
        if (!empty($weather['error'])) throw new RuntimeException((string)$weather['error']);
        $alerts = array_merge($alerts, user_alerts_weather($weather, get_atmospheric_pressure_alert_threshold($pdo)));
    } catch (Throwable $exception) { error_log('[User alerts weather] ' . $exception->getMessage()); $errors[] = 'Meteo'; }
    return ['alerts'=>$alerts,'count'=>count($alerts),'unavailable'=>$errors];
}
