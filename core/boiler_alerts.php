<?php

require_once __DIR__ . '/ewelink_mcp.php';
require_once __DIR__ . '/ecowitt_alerts.php';
require_once __DIR__ . '/mailer.php';

function boiler_temperature_is_below_setpoint($temperature, float $setpoint): bool
{
    return $temperature !== null && is_numeric($temperature) && is_finite((float)$temperature) && (float)$temperature < $setpoint;
}

function boiler_temperature_alert_message(string $name, float $temperature, float $setpoint): string
{
    return 'ALLERTA CALDAIA: ' . $name . ' a ' . number_format($temperature, 1, ',', '') . ' C (setpoint ' . number_format($setpoint, 1, ',', '') . ' C). Verificare accensione caldaie.';
}

function send_boiler_temperature_alerts(PDO $pdo, array $env, ?DateTimeInterface $now = null, ?array $boilerData = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
    $result = ['season_active'=>false,'setpoint'=>null,'recipients'=>0,'sent'=>[],'emails_sent'=>0,'errors'=>[]];
    $lock = (int)$pdo->query("SELECT GET_LOCK('dojo_boiler_temperature_alerts', 0)")->fetchColumn();
    if ($lock !== 1) { $result['errors'][] = 'Un altro controllo caldaie è già in esecuzione.'; return $result; }
    try {
        $names = ewelink_mcp_config()['mcp_boiler_names'] ?? ['Boiler Appartamenti','Boiler Cottage'];
        $season = ecowitt_alert_season_is_active($pdo, $now);
        $result['season_active'] = $season;
        $setpoint = get_hot_water_setpoint($pdo);
        $result['setpoint'] = $setpoint;
        if (!$season || $setpoint === null) {
            foreach ($names as $name) {
                $key = 'boiler_alert_' . hash('sha256', (string)$name);
                set_setting($key . '_active', '0', $pdo);
                set_setting($key . '_email_active', '0', $pdo);
            }
            return $result;
        }
        $boilerData ??= ewelink_mcp_fetch_boilers(true);
        if (!empty($boilerData['error'])) $result['errors'][] = (string)$boilerData['error'];
        $phones = ecowitt_maintenance_phones($pdo);
        $emails = $pdo->query("SELECT DISTINCT email FROM users WHERE deleted_at IS NULL AND is_active=1 AND email<>'' AND FIND_IN_SET('Manutenzione', REPLACE(dipartimento,' ',''))>0")->fetchAll(PDO::FETCH_COLUMN);
        $emails = array_values(array_unique(array_filter(array_map('trim', $emails), static fn($email)=>filter_var($email, FILTER_VALIDATE_EMAIL))));
        $result['recipients'] = count($phones);
        foreach ($names as $name) {
            $temperature = $boilerData['boilers'][$name] ?? null;
            // Missing readings never reset an existing alert.
            if (!is_numeric($temperature) || !is_finite((float)$temperature)) {
                $result['errors'][] = 'Temperatura non disponibile per ' . $name;
                continue;
            }
            $key = 'boiler_alert_' . hash('sha256', (string)$name);
            if (!boiler_temperature_is_below_setpoint($temperature, $setpoint)) {
                set_setting($key . '_active', '0', $pdo);
                set_setting($key . '_email_active', '0', $pdo);
                continue;
            }
            $message = boiler_temperature_alert_message((string)$name, (float)$temperature, $setpoint);
            if ((string)get_setting($key . '_active', '0', $pdo) !== '1') {
                try {
                    if (!$phones) throw new RuntimeException('Nessun destinatario Manutenzione con telefono.');
                    if (empty($env['sms']['enabled'])) throw new RuntimeException('Invio SMS disabilitato.');
                    sms_send_message($env, $phones, $message);
                    if (empty($env['sms']['dry_run'])) {
                        set_setting($key . '_active', '1', $pdo);
                        $result['sent'][] = $name;
                    }
                } catch (Throwable $e) { $result['errors'][] = $e->getMessage(); }
            }
            if ((string)get_setting($key . '_email_active', '0', $pdo) !== '1') {
                try {
                    if (!$emails) throw new RuntimeException('Nessun destinatario Manutenzione con email valida.');
                    $html = '<h2>Allerta temperatura boiler</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>Rilevazione: ' . DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i') . '</p>';
                    $allSent = true;
                    foreach ($emails as $email) {
                        if (send_mail($email, 'Allerta temperatura ' . $name, $html)) $result['emails_sent']++;
                        else $allSent = false;
                    }
                    if (!$allSent) throw new RuntimeException('Invio email boiler non riuscito per alcuni destinatari.');
                    set_setting($key . '_email_active', '1', $pdo);
                } catch (Throwable $e) { $result['errors'][] = $e->getMessage(); }
            }
        }
    } catch (Throwable $e) { $result['errors'][] = $e->getMessage(); }
    finally { $pdo->query("SELECT RELEASE_LOCK('dojo_boiler_temperature_alerts')"); }
    foreach ($result['errors'] as $error) error_log('[Boiler temperature alerts] ' . $error);
    return $result;
}
