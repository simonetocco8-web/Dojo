<?php
require_once __DIR__ . '/autocontrollo_settings.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

function ensure_autocontrollo_gas_inspections_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS autocontrollo_gas_inspections (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        season_start DATE NOT NULL, season_end DATE NOT NULL, scheduled_date DATE NOT NULL,
        status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
        operator_id INT UNSIGNED DEFAULT NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL, liters DECIMAL(12,2) DEFAULT NULL,
        alert_threshold INT UNSIGNED DEFAULT NULL, email_sent_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_gas_date (season_start,season_end,scheduled_date),
        FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function autocontrollo_gas_schedule(array $range): array {
    $zone = new DateTimeZone('Europe/Rome');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($range['start'] ?? ''), $zone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($range['end'] ?? ''), $zone);
    if (!$start || !$end || $start->format('Y-m-d') !== $range['start'] || $end->format('Y-m-d') !== $range['end'] || $start > $end) return [];
    $dates = [];
    for ($date = $start->modify('-15 days'); $date <= $end; $date = $date->modify('+30 days')) $dates[] = $date->format('Y-m-d');
    return $dates;
}

function autocontrollo_gas_next_date(array $schedule, array $completed): ?string {
    foreach ($schedule as $date) if (!in_array($date, $completed, true)) return $date;
    return null;
}

function autocontrollo_gas_parse_liters(string $value): string {
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('Inserisci una giacenza in litri non negativa, con al massimo due decimali.');
    return number_format((float)$value, 2, '.', '');
}

function autocontrollo_gas_start(PDO $pdo, array $range, string $date, int $operatorId): int {
    ensure_autocontrollo_gas_inspections_tables($pdo);
    if (!in_array($date, autocontrollo_gas_schedule($range), true) || $date > (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d')) throw new InvalidArgumentException('Controllo non previsto o non ancora disponibile.');
    // Serialize starts within the season, including when no inspection exists yet.
    $lock = 'dojo_gas_' . $range['start'] . '_' . $range['end'];
    $stmt = $pdo->prepare('SELECT GET_LOCK(?,5)'); $stmt->execute([$lock]);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Procedura occupata, riprova.');
    try {
        $stmt = $pdo->prepare('SELECT id,status,scheduled_date FROM autocontrollo_gas_inspections WHERE season_start=? AND season_end=? ORDER BY scheduled_date');
        $stmt->execute([$range['start'], $range['end']]); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) if ($row['status'] === 'in_corso') return (int)$row['id'];
        if ($date !== autocontrollo_gas_next_date(autocontrollo_gas_schedule($range), array_column($rows, 'scheduled_date'))) throw new RuntimeException('Avvia il prossimo controllo previsto.');
        $stmt = $pdo->prepare('INSERT INTO autocontrollo_gas_inspections (season_start,season_end,scheduled_date,operator_id) VALUES (?,?,?,?)');
        $stmt->execute([$range['start'], $range['end'], $date, $operatorId]);
        return (int)$pdo->lastInsertId();
    } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
}

function autocontrollo_gas_complete(PDO $pdo, int $id, string $liters, int $operatorId): void {
    $value = autocontrollo_gas_parse_liters($liters);
    $threshold = autocontrollo_get_gas_alert_level($pdo);
    $stmt = $pdo->prepare("UPDATE autocontrollo_gas_inspections SET liters=?,alert_threshold=?,operator_id=?,status='completata',completed_at=NOW() WHERE id=? AND status='in_corso'");
    $stmt->execute([$value, $threshold, $operatorId, $id]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Procedura inesistente o già completata.');
}

function autocontrollo_gas_report_html(array $inspection): string {
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $low = (float)$inspection['liters'] <= (int)$inspection['alert_threshold'];
    $value = $escape(number_format((float)$inspection['liters'], 2, ',', '.')) . ' litri';
    if ($low) $value = '<strong><u>' . $value . '</u></strong> — GIACENZA PARI O SOTTO SOGLIA';
    return '<h2>Autocontrollo Livello Gas</h2><p>Data prevista: ' . $escape($inspection['scheduled_date']) . '<br>Rilevazione: ' . $escape($inspection['completed_at']) . '<br>Operatore: ' . $escape($inspection['operator_name'] ?: ($inspection['operator_email'] ?? '')) . '<br>Giacenza: ' . $value . '<br>Soglia minima: ' . (int)$inspection['alert_threshold'] . ' litri</p>';
}

function autocontrollo_gas_send_report(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT i.*,TRIM(CONCAT_WS(' ',u.nome,u.cognome)) operator_name,u.email operator_email FROM autocontrollo_gas_inspections i LEFT JOIN users u ON u.id=i.operator_id WHERE i.id=? AND i.status='completata'");
    $stmt->execute([$id]); $inspection = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inspection) throw new RuntimeException('Rilevazione non completata.');
    $sent = 0; $total = 0;
    foreach ($pdo->query("SELECT email,dipartimento FROM users WHERE is_active=1 AND deleted_at IS NULL AND email<>''")->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
        if (!user_has_department($recipient, 'Amministrazione') || !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) continue;
        $total++;
        if (send_mail($recipient['email'], 'Esito autocontrollo Livello Gas' . ((float)$inspection['liters'] <= (int)$inspection['alert_threshold'] ? ' — ALLERTA GIACENZA' : ''), autocontrollo_gas_report_html($inspection))) $sent++;
    }
    if ($sent > 0) $pdo->prepare('UPDATE autocontrollo_gas_inspections SET email_sent_at=NOW() WHERE id=?')->execute([$id]);
    return ['sent'=>$sent, 'total'=>$total];
}
