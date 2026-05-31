<?php
// core/settings.php

require_once __DIR__ . '/db.php';

/**
 * Retrieve multiple settings at once.
 *
 * @param array $keys List of setting identifiers to load.
 * @param PDO|null $pdo Optional PDO instance to reuse.
 *
 * @return array<string, string|null>
 */
function get_settings(array $keys, ?PDO $pdo = null): array {
  if (!$keys) {
    return [];
  }

  if ($pdo === null) {
    try {
      $pdo = db();
    } catch (Throwable $exception) {
      return array_fill_keys($keys, null);
    }
  }

  ensure_system_settings_table($pdo);

  $placeholders = implode(',', array_fill(0, count($keys), '?'));
  $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (' . $placeholders . ')');
  $stmt->execute($keys);

  $results = array_fill_keys($keys, null);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $results[$row['setting_key']] = $row['setting_value'];
  }

  return $results;
}

/**
 * Retrieve a single setting value.
 *
 * @param string $key Setting identifier.
 * @param mixed $default Default value when the setting is missing or database unavailable.
 * @param PDO|null $pdo Optional PDO instance to reuse.
 *
 * @return mixed
 */
function get_setting(string $key, $default = null, ?PDO $pdo = null) {
  $values = get_settings([$key], $pdo);
  return $values[$key] ?? $default;
}

/**
 * Persist a setting value in the database.
 */
function set_setting(string $key, ?string $value, ?PDO $pdo = null): void {
  if ($pdo === null) {
    $pdo = db();
  }
  ensure_system_settings_table($pdo);

  $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, NOW())
      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
  $stmt->execute([
    ':key' => $key,
    ':value' => $value,
  ]);
}

/**
 * Convenience helper returning the summer season range.
 *
 * @return array{start:?string,end:?string}
 */
function get_summer_season_range(?PDO $pdo = null): array {
  $settings = get_settings(['summer_season_start', 'summer_season_end'], $pdo);
  return [
    'start' => $settings['summer_season_start'] ?? null,
    'end' => $settings['summer_season_end'] ?? null,
  ];
}

/**
 * Determine whether the provided date falls within the configured summer season range.
 */
function is_date_within_summer_season(DateTimeInterface $date, ?PDO $pdo = null): bool {
  $range = get_summer_season_range($pdo);
  $startRaw = $range['start'];
  $endRaw = $range['end'];

  if (!$startRaw || !$endRaw) {
    // No restriction configured.
    return true;
  }

  $timezone = new DateTimeZone('Europe/Rome');
  $start = DateTime::createFromFormat('Y-m-d', $startRaw, $timezone) ?: false;
  $end = DateTime::createFromFormat('Y-m-d', $endRaw, $timezone) ?: false;

  if (!$start || !$end) {
    return true;
  }

  // Normalise to full-day boundaries.
  $start->setTime(0, 0, 0);
  $end->setTime(23, 59, 59);

  if ($start > $end) {
    // Invalid configuration, treat as unrestricted to avoid hiding data unexpectedly.
    return true;
  }

  $target = (new DateTimeImmutable('@' . $date->getTimestamp()))->setTimezone($timezone);

  return $target >= DateTimeImmutable::createFromMutable($start)
      && $target <= DateTimeImmutable::createFromMutable($end);
}

/**
 * Convenience wrapper for the current date.
 */
function is_today_within_summer_season(?PDO $pdo = null): bool {
  $timezone = new DateTimeZone('Europe/Rome');
  $today = new DateTimeImmutable('now', $timezone);
  return is_date_within_summer_season($today, $pdo);
}
