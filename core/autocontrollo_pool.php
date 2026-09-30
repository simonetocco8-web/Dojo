<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function autocontrollo_pool_next_required_date(array $range, array $completedDates): ?string {
  $timezone = new DateTimeZone('Europe/Rome');
  $start = !empty($range['start']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['start'], $timezone) : false;
  $end = !empty($range['end']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['end'], $timezone) : false;
  if (!$start || !$end || $start > $end) return null;
  $completed = array_fill_keys(array_map('strval', $completedDates), true);
  for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
    $value = $day->format('Y-m-d');
    if (!isset($completed[$value])) return $value;
  }
  return null;
}

function autocontrollo_pool_decimal($value, string $label, float $minimum, float $maximum): float {
  $normalized = str_replace(',', '.', trim((string)$value));
  if ($normalized === '' || !is_numeric($normalized)) throw new InvalidArgumentException($label . ' non valido.');
  $number = (float)$normalized;
  if ($number < $minimum || $number > $maximum) throw new InvalidArgumentException($label . ' fuori dall’intervallo consentito.');
  return $number;
}

function autocontrollo_pool_create(PDO $pdo, array $range, string $requiredDate, int $operatorId, array $input): int {
  $completedStmt = $pdo->prepare('SELECT inspection_date FROM autocontrollo_pool_inspections WHERE season_start=? AND season_end=? ORDER BY inspection_date');
  $completedStmt->execute([$range['start'], $range['end']]);
  $actualRequiredDate = autocontrollo_pool_next_required_date($range, $completedStmt->fetchAll(PDO::FETCH_COLUMN));
  if ($actualRequiredDate === null || $actualRequiredDate !== $requiredDate) {
    throw new RuntimeException('La data richiesta non è più disponibile. Ricaricare la pagina.');
  }
  $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
  if (new DateTimeImmutable($requiredDate, new DateTimeZone('Europe/Rome')) > $today) {
    throw new RuntimeException('Non è possibile registrare il controllo prima della data prevista.');
  }
  $time = trim((string)($input['inspection_time'] ?? ''));
  $parsedTime = DateTimeImmutable::createFromFormat('!H:i', $time, new DateTimeZone('Europe/Rome'));
  if (!$parsedTime || $parsedTime->format('H:i') !== $time) throw new InvalidArgumentException('Orario non valido.');
  $chlorine = autocontrollo_pool_decimal($input['chlorine'] ?? '', 'Cloro rilevato', 0, 100);
  $temperature = autocontrollo_pool_decimal($input['water_temperature'] ?? '', 'Temperatura', -10, 60);
  $ph = autocontrollo_pool_decimal($input['ph_value'] ?? '', 'Valore pH', 0, 14);
  $people = filter_var($input['people_in_pool'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
  if ($people === false) throw new InvalidArgumentException('Numero di persone in vasca non valido.');
  $backwashRaw = trim((string)($input['backwash_minutes'] ?? ''));
  $backwash = null;
  if ($backwashRaw !== '') {
    $backwash = filter_var($backwashRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);
    if ($backwash === false) throw new InvalidArgumentException('Durata del controlavaggio non valida.');
  }
  $sampleLocation = (string)($input['sample_location'] ?? '');
  if (!in_array($sampleLocation, ['interno', 'esterno'], true)) throw new InvalidArgumentException('Indicare il punto di prelievo dei campioni.');
  $productQuantities = $input['product_quantities'] ?? [];
  if (!is_array($productQuantities)) throw new InvalidArgumentException('Prodotti piscina non validi.');
  $selectedProducts = [];
  foreach ($productQuantities as $productId => $quantityRaw) {
    $quantityRaw = trim((string)$quantityRaw);
    if ($quantityRaw === '') continue;
    $productId = (int)$productId;
    if ($productId <= 0) throw new InvalidArgumentException('Prodotto piscina non valido.');
    $stmt = $pdo->prepare('SELECT id, description FROM autocontrollo_pool_products WHERE id=?');
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new InvalidArgumentException('Prodotto piscina non valido.');
    $selectedProducts[] = [$product, autocontrollo_pool_decimal($quantityRaw, 'Quantità prodotto', 0, 99999)];
  }

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_pool_inspections (season_start, season_end, inspection_date, inspection_time, operator_id, chlorine, water_temperature, ph_value, people_in_pool, backwash_minutes, sample_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$range['start'], $range['end'], $requiredDate, $time . ':00', $operatorId, $chlorine, $temperature, $ph, $people, $backwash, $sampleLocation]);
    $inspectionId = (int)$pdo->lastInsertId();
    foreach ($selectedProducts as [$product, $quantity]) {
      $stmt = $pdo->prepare('INSERT INTO autocontrollo_pool_inspection_products (inspection_id, product_id, product_description, quantity_kg) VALUES (?, ?, ?, ?)');
      $stmt->execute([$inspectionId, $product['id'], $product['description'], $quantity]);
    }
    $pdo->commit();
    return $inspectionId;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}
