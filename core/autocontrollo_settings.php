<?php

require_once __DIR__ . '/settings.php';

function autocontrollo_responsibility_procedures(): array {
    return [
        'electrical' => 'Quadri Elettrici',
        'pool' => 'Piscina',
        'rodent' => 'Derattizzazione',
        'grounding' => 'Messa a Terra',
        'temperature' => 'Temperature Frigoriferi',
        'fire' => 'Antincendio',
        'haccp' => 'Pulizie HACCP',
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
