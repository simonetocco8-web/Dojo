<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';

start_session();                         // avvia SEMPRE prima di output
$env  = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();                  // può essere null: NON fare redirect qui

function is_amministrazione() {
  $u = current_user();
  return ($u && (($u['role'] ?? '') === 'admin' || ($u['dipartimento'] ?? '') === 'Amministrazione'));
}
function is_bar_or_amministrazione() {
  $u = current_user();
  if (!$u) return false;
  if (($u['role'] ?? '') === 'admin') return true;
  $dep = $u['dipartimento'] ?? '';
  return in_array($dep, ['Amministrazione','Bar'], true);
}