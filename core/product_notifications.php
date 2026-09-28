<?php

require_once __DIR__ . '/sms.php';

function product_department_names(string $raw): array
{
    $decoded = json_decode($raw, true);
    $values = is_array($decoded) ? $decoded : preg_split('/\s*[,;|]\s*/', $raw);
    return array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $values ?: [])));
}

function product_deactivation_recipients(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT telefono, dipartimento FROM users
        WHERE deleted_at IS NULL AND is_active = 1 AND telefono <> ''");
    $phones = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array('Resp. Bar', product_department_names((string)($row['dipartimento'] ?? '')), true)) {
            $phones[] = trim((string)$row['telefono']);
        }
    }
    return array_values(array_unique(array_filter($phones)));
}

function product_deactivation_message(string $productTitle): string
{
    $prefix = 'INFO MAGAZZINO: prodotto "';
    $suffix = '" disattivato.';
    $available = 160 - sms_utf8_length($prefix) - sms_utf8_length($suffix);
    $title = sms_utf8_substr(sms_gsm7_sanitize($productTitle), 0, max(0, $available));
    return $prefix . $title . $suffix;
}

function send_product_deactivation_sms(PDO $pdo, array $env, string $productTitle): int
{
    $recipients = product_deactivation_recipients($pdo);
    if (!$recipients) throw new RuntimeException('Nessun utente attivo del reparto Resp. Bar con numero di telefono.');
    sms_send_message($env, $recipients, product_deactivation_message($productTitle));
    return count($recipients);
}
