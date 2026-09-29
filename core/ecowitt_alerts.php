<?php

require_once __DIR__ . '/ecowitt.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/sms.php';

function ecowitt_maintenance_phones(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT telefono
        FROM users
        WHERE deleted_at IS NULL
          AND is_active = 1
          AND telefono <> ''
          AND FIND_IN_SET('Manutenzione', REPLACE(dipartimento, ' ', '')) > 0
    ");
    return array_values(array_unique(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
}

function ecowitt_alert_season_is_active(PDO $pdo, DateTimeInterface $now): bool
{
    $range = get_summer_season_range($pdo);
    if (empty($range['start']) || empty($range['end'])) return false;
    $timezone = new DateTimeZone('Europe/Rome');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['start'], $timezone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['end'], $timezone);
    if (!$start || !$end || $start->format('Y-m-d') !== $range['start'] || $end->format('Y-m-d') !== $range['end'] || $start > $end) {
        return false;
    }
    $today = DateTimeImmutable::createFromInterface($now)->setTimezone($timezone)->setTime(0, 0);
    return $today >= $start && $today <= $end;
}

function ecowitt_weather_alert_definitions(array $weather): array
{
    $gust = $weather['wind_gust'] ?? null;
    $pressure = $weather['pressure'] ?? null;
    return [
        'wind' => [
            'active' => is_numeric($gust) && (float)$gust > 30,
            'message' => is_numeric($gust)
                ? 'ALLERTA METEO: raffica vento ' . number_format((float)$gust, 1, ',', '') . ' km/h. Verificare subito gli ombrelloni in spiaggia.'
                : '',
        ],
        'pressure' => [
            'active' => is_numeric($pressure) && (float)$pressure <= 1013,
            'message' => is_numeric($pressure)
                ? 'ALLERTA METEO: pressione ' . number_format((float)$pressure, 1, ',', '') . ' hPa. Possibili piogge nelle prossime ore o giorni. Prestare attenzione al canale di scolo acqua piovana.'
                : '',
        ],
    ];
}

/**
 * Invia un solo SMS all'ingresso in soglia e riabilita l'avviso quando il
 * valore torna normale, evitando SMS ripetuti ad ogni esecuzione del cron.
 */
function send_ecowitt_weather_alerts(PDO $pdo, array $env, ?DateTimeInterface $now = null, ?array $weather = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
    $result = ['season_active' => false, 'recipients' => 0, 'sent' => [], 'errors' => []];
    if (!ecowitt_alert_season_is_active($pdo, $now)) {
        set_setting('ecowitt_alert_wind_active', '0', $pdo);
        set_setting('ecowitt_alert_pressure_active', '0', $pdo);
        return $result;
    }
    $result['season_active'] = true;

    $lock = (int)$pdo->query("SELECT GET_LOCK('dojo_ecowitt_weather_alerts', 0)")->fetchColumn();
    if ($lock !== 1) {
        $result['errors'][] = 'Un altro processo di alert Ecowitt è già in esecuzione.';
        return $result;
    }

    try {
        $weather ??= ecowitt_fetch_temperature();
        if (!empty($weather['error'])) throw new RuntimeException((string)$weather['error']);
        $recipients = ecowitt_maintenance_phones($pdo);
        $result['recipients'] = count($recipients);
        $states = get_settings(['ecowitt_alert_wind_active', 'ecowitt_alert_pressure_active'], $pdo);

        foreach (ecowitt_weather_alert_definitions($weather) as $type => $alert) {
            $stateKey = 'ecowitt_alert_' . $type . '_active';
            if (!$alert['active']) {
                if (($states[$stateKey] ?? '0') !== '0') set_setting($stateKey, '0', $pdo);
                continue;
            }
            if (($states[$stateKey] ?? '0') === '1') continue;
            if (!$recipients) throw new RuntimeException('Nessun utente attivo nel reparto Manutenzione con numero di telefono.');

            sms_send_message($env, $recipients, $alert['message']);
            set_setting($stateKey, '1', $pdo);
            $result['sent'][] = $type;
        }
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
        error_log('[Ecowitt weather alerts] ' . $e->getMessage());
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('dojo_ecowitt_weather_alerts')");
    }
    return $result;
}
