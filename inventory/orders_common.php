<?php
declare(strict_types=1);

function inventory_order_status_labels(): array {
  return [
    'generato' => 'Generato',
    'inviato' => 'Inviato',
    'consegnato' => 'Consegnato',
    'variato' => 'Variato',
    'annullato' => 'Annullato',
  ];
}

function inventory_order_warehouses(): array {
  return ['Tizzo', 'Tramonto'];
}

function inventory_order_quantity(mixed $value): float {
  $normalized = str_replace(',', '.', trim((string)$value));
  return is_numeric($normalized) ? round((float)$normalized, 3) : 0.0;
}

function inventory_order_items_from_post(PDO $pdo, array $source): array {
  $quantities = [];
  foreach ($source as $productId => $rawQuantity) {
    $productId = (int)$productId;
    $quantity = inventory_order_quantity($rawQuantity);
    if ($productId > 0 && $quantity > 0) {
      $quantities[$productId] = $quantity;
    }
  }
  if (!$quantities) return [];

  $placeholders = implode(',', array_fill(0, count($quantities), '?'));
  $stmt = $pdo->prepare("SELECT id, title, unit, supplier_id FROM products WHERE id IN ($placeholders) AND COALESCE(is_active, 1) = 1");
  $stmt->execute(array_keys($quantities));
  $items = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
    $id = (int)$product['id'];
    $items[] = [
      'product_id' => $id,
      'quantity' => $quantities[$id],
      'product_name' => (string)$product['title'],
      'product_unit' => (string)($product['unit'] ?? ''),
      'product_supplier_id' => $product['supplier_id'] !== null ? (int)$product['supplier_id'] : null,
    ];
  }
  usort($items, static fn(array $a, array $b): int => strcasecmp($a['product_name'], $b['product_name']));
  return $items;
}

function inventory_order_insert_items(PDO $pdo, int $orderId, array $items): void {
  $stmt = $pdo->prepare('INSERT INTO inventory_order_items (order_id, product_id, quantity, product_name, product_unit, product_supplier_id) VALUES (?, ?, ?, ?, ?, ?)');
  foreach ($items as $item) {
    $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $item['product_name'], $item['product_unit'] ?: null, $item['product_supplier_id']]);
  }
}

function inventory_order_format_quantity(mixed $quantity): string {
  return rtrim(rtrim(number_format((float)$quantity, 3, ',', ''), '0'), ',');
}
