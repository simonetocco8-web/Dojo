<?php

require_once __DIR__ . '/ewelink_mcp.php';
require_once __DIR__ . '/ecowitt_alerts.php';

function boiler_temperature_is_below_setpoint($temperature, float $setpoint): bool
{
    return $temperature !== null && is_numeric($temperature) && (float)$temperature <= $setpoint;
}

function boiler_temperature_alert_message(string $name, float $temperature, float $setpoint): string
{
    return 'ALLERTA CALDAIA: ' . $name . ' a ' . number_format($temperature, 1, ',', '') . ' C (setpoint ' . number_format($setpoint, 1, ',', '') . ' C). Verificare accensione caldaie.';
}

function send_boiler_temperature_alerts(PDO $pdo, array $env, ?DateTimeInterface $now = null, ?array $boilerData = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
    $result = ['season_active' => false, 'setpoint' => null, 'recipients' => 0, 'sent' => [], 'errors' => []];
    if (!ecowitt_alert_season_is_active($pdo, $now)) {
        $cfg = ewelink_mcp_config();
        foreach (($cfg['mcp_boiler_names'] ?? ['Boiler Appartamenti', 'Boiler Cottage']) as $name) {
            set_setting('boiler_alert_' . hash('sha256', (string)$name) . '_active', '0', $pdo);
        }
        return $result;
    }
    $result['season_active'] = true;
    $setpoint = get_hot_water_setpoint($pdo);
    $result['setpoint'] = $setpoint;
    if ($setpoint === null) return $result;

    $lock = (int)$pdo->query("SELECT GET_LOCK('dojo_boiler_temperature_alerts', 0)")->fetchColumn();
    if ($lock !== 1) { $result['errors'][] = 'Un altro controllo caldaie è già in esecuzione.'; return $result; }
    try {
        $boilerData ??= ewelink_mcp_fetch_boilers(true);
        if (!empty($boilerData['error'])) {
            $result['errors'][] = (string)$boilerData['error'];
            error_log('[Boiler temperature alerts] ' . $boilerData['error']);
        }
        if (!array_filter($boilerData['boilers'] ?? [], 'is_numeric')) return $result;
        $recipients = ecowitt_maintenance_phones($pdo);
        $result['recipients'] = count($recipients);
        foreach (($boilerData['boilers'] ?? []) as $name => $temperature) {
            $stateKey = 'boiler_alert_' . hash('sha256', (string)$name) . '_active';
            $active = boiler_temperature_is_below_setpoint($temperature, $setpoint);
            $previous = (string)get_setting($stateKey, '0', $pdo);
            if (!$active) { if ($previous === '1') set_setting($stateKey, '0', $pdo); continue; }
            if ($previous === '1') continue;
            if (!$recipients) throw new RuntimeException('Nessun utente attivo nel reparto Manutenzione con numero di telefono.');
            $message = boiler_temperature_alert_message((string)$name, (float)$temperature, $setpoint);
            sms_send_message($env, $recipients, $message);
            set_setting($stateKey, '1', $pdo);
            $result['sent'][] = $name;
        }
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
        error_log('[Boiler temperature alerts] ' . $e->getMessage());
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('dojo_boiler_temperature_alerts')");
    }
    return $result;
}
