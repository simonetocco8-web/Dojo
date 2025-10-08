<?php
// core/roles.php
require_once __DIR__ . '/auth.php';

// Puoi passare $user se già disponibile, altrimenti legge current_user()
function user_is_admin($user = null) {
  if ($user === null) $user = current_user();
  return $user && (($user['role'] ?? '') === 'admin');
}

function user_is_amministrazione($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return false;
  return user_is_admin($user) || (($user['dipartimento'] ?? '') === 'Amministrazione');
}

function user_is_bar_or_amministrazione($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return false;
  if (user_is_admin($user)) return true;
  $dep = $user['dipartimento'] ?? '';
  return in_array($dep, ['Amministrazione','Bar'], true);
}


function user_is_reception_or_amministrazione($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return false;
  if (user_is_admin($user)) return true;
  $dep = $user['dipartimento'] ?? '';
  return in_array($dep, ['Amministrazione','Reception'], true);
}