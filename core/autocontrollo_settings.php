<?php

require_once __DIR__ . '/settings.php';

function autocontrollo_dashboard_date_label(?string $date, array $range): string {
    $zone = new DateTimeZone('Europe/Rome');
    if ($date !== null) return (new DateTimeImmutable($date, $zone))->format('d/m/Y');
    if (empty($range['start']) || empty($range['end'])) return 'Stagione non configurata';
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['start'], $zone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['end'], $zone);
    if (!$start || !$end || $start->format('Y-m-d') !== $range['start'] || $end->format('Y-m-d') !== $range['end'] || $start > $end) return 'Date stagione non valide';
    return 'Controlli stagionali completati';
}

function autocontrollo_parse_gas_alert_level(string $value): int {
    $value = trim($value);
    if (!ctype_digit($value) || strlen($value) > 4 || (int)$value > 1000 || (int)$value % 100 !== 0) {
        throw new InvalidArgumentException('Il livello minimo del gas deve essere tra 0 e 1000, a intervalli di 100.');
    }
    return (int)$value;
}

function autocontrollo_get_gas_alert_level(?PDO $pdo = null): int {
    try {
        return autocontrollo_parse_gas_alert_level((string)get_setting('autocontrollo_gas_alert_level', '0', $pdo));
    } catch (InvalidArgumentException $exception) {
        return 0;
    }
}

function autocontrollo_responsibility_procedures(): array {
    return [
        'electrical' => 'Quadri Elettrici',
        'pool' => 'Piscina',
        'rodent' => 'Derattizzazione',
        'grounding' => 'Messa a Terra',
        'temperature' => 'Temperature Frigoriferi',
        'fire' => 'Antincendio',
        'haccp' => 'Pulizie HACCP',
        'gas' => 'Livello Gas',
    ];
}

function autocontrollo_save_responsible(PDO $pdo, string $procedure, string $userId): void {
    if (!array_key_exists($procedure, autocontrollo_responsibility_procedures())) {
        throw new InvalidArgumentException('Procedura di autocontrollo non valida.');
    }
    if ($userId !== '') {
        if (!ctype_digit($userId) || (int)$userId <= 0) {
            throw new InvalidArgumentException('Responsabile non valido.');
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1 AND deleted_at IS NULL');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Selezionare un utente attivo come responsabile.');
        }
        $userId = (string)(int)$userId;
    }
    set_setting('autocontrollo_responsible_' . $procedure, $userId === '' ? null : $userId, $pdo);
}

function autocontrollo_user_can_perform(array $user, string $procedure, ?PDO $pdo = null): bool {
    if (user_has_department($user, 'Amministrazione')) return true;
    return !empty($user['is_active']) && (string)get_setting('autocontrollo_responsible_' . $procedure, '', $pdo) === (string)$user['id'];
}
