<?php
require __DIR__ . '/core/db.php';
try {
  $pdo = db();
  echo "<p>OK: Connessione DB riuscita.</p>";
  $row = $pdo->query("SELECT COUNT(*) c FROM users")->fetch();
  echo "<p>Utenti in tabella: " . (int)$row['c'] . "</p>";
  $admin = $pdo->query("SELECT email, role, is_active FROM users WHERE email='admin@example.com'")->fetch();
  if ($admin) {
    echo "<p>Admin trovato: {$admin['email']} – role={$admin['role']} – attivo=" . ($admin['is_active']?'1':'0') . "</p>";
  } else {
    echo "<p>Nessun admin di default trovato.</p>";
  }
} catch (Throwable $e) {
  echo "<p>ERRORE DB: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}