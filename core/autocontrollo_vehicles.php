<?php

function ensure_autocontrollo_vehicles_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS autocontrollo_vehicles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        brand VARCHAR(190) NOT NULL,
        model VARCHAR(190) NOT NULL,
        license_plate VARCHAR(32) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function autocontrollo_vehicle_values(string $brand, string $model, string $plate): array {
    $brand = trim($brand); $model = trim($model); $plate = trim($plate);
    foreach (['Marca'=>[$brand,190], 'Modello'=>[$model,190], 'Targa'=>[$plate,32]] as $label => [$value,$limit]) {
        if ($label !== 'Targa' && $value === '') throw new InvalidArgumentException($label . ' obbligatoria.');
        if ((function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) > $limit) throw new InvalidArgumentException($label . ': massimo ' . $limit . ' caratteri.');
    }
    return [$brand, $model, $plate === '' ? null : $plate];
}

function autocontrollo_vehicle_save(PDO $pdo, ?int $id, string $brand, string $model, string $plate): int {
    $values = autocontrollo_vehicle_values($brand, $model, $plate);
    if ($id === null) {
        $pdo->prepare('INSERT INTO autocontrollo_vehicles (brand,model,license_plate) VALUES (?,?,?)')->execute($values);
        return (int)$pdo->lastInsertId();
    }
    $check = $pdo->prepare('SELECT id FROM autocontrollo_vehicles WHERE id=?'); $check->execute([$id]);
    if ($id <= 0 || $check->fetchColumn() === false) throw new InvalidArgumentException('Veicolo non trovato.');
    $pdo->prepare('UPDATE autocontrollo_vehicles SET brand=?,model=?,license_plate=? WHERE id=?')->execute(array_merge($values, [$id]));
    return $id;
}

function autocontrollo_vehicle_delete(PDO $pdo, int $id): void {
    if ($id <= 0) throw new InvalidArgumentException('Veicolo non valido.');
    $stmt = $pdo->prepare('DELETE FROM autocontrollo_vehicles WHERE id=?'); $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Veicolo non trovato.');
}
