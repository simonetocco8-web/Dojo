<?php
require_once __DIR__ . '/core/user_alerts.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
try {
    $user = current_user();
    if (!$user || empty($user['is_active'])) { http_response_code(401); echo json_encode(['error'=>'Autenticazione richiesta.']); exit; }
    session_write_close();
    echo json_encode(user_alerts_collect(db(), $user), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('[User alerts] ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error'=>'Alert temporaneamente non disponibili.']);
}
