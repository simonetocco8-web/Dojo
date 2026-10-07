<?php

// Caricabile anche con un modulo derattizzazione precedente.

if (!function_exists('autocontrollo_rodent_schedule')) {
function autocontrollo_rodent_schedule(array $range): array {
  $timezone = new DateTimeZone('Europe/Rome');
  $start = !empty($range['start']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['start'], $timezone) : false;
  $end = !empty($range['end']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['end'], $timezone) : false;
  if (!$start || !$end || $start > $end) return [];
  $dates = [];
  for ($date = $start; $date <= $end; $date = $date->modify('+15 days')) $dates[] = $date->format('Y-m-d');
  return $dates;
}
}

if (!function_exists('autocontrollo_rodent_next_date')) {
function autocontrollo_rodent_next_date(array $schedule, array $startedDates): ?string {
  $started = array_fill_keys(array_map('strval', $startedDates), true);
  foreach ($schedule as $date) if (!isset($started[$date])) return $date;
  return null;
}
}
