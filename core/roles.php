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
  return user_is_admin($user) || user_has_department($user, 'Amministrazione');
}

function user_is_bar_or_amministrazione($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return false;
  if (user_is_admin($user)) return true;
  return user_has_any_department($user, ['Amministrazione','Bar']);
}


function user_is_reception_or_amministrazione($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return false;
  if (user_is_admin($user)) return true;
  return user_has_any_department($user, ['Amministrazione','Reception']);
}

function user_is_housekeeping($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return false;
  if (user_is_admin($user)) return true;
  return user_has_department($user, 'HouseKeeping');
}

function user_can_send_sms($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return false;
  if (user_is_admin($user)) return true;
  return user_has_any_department($user, ['Amministrazione','Reception','Booking']);
}
