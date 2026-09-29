<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function season_end_report_range(PDO $pdo): array
{
    $range = get_summer_season_range($pdo);
    $tz = new DateTimeZone('Europe/Rome');
    $year = (int)(new DateTimeImmutable('now', $tz))->format('Y');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($range['start'] ?? ''), $tz)
        ?: new DateTimeImmutable($year . '-01-01', $tz);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($range['end'] ?? ''), $tz)
        ?: new DateTimeImmutable($year . '-12-31', $tz);
    if ($start > $end) [$start, $end] = [$end, $start];
    return [$start, $end];
}

function season_end_report_data(PDO $pdo): array
{
    ensure_tramontoday_bookings_table($pdo);
    ensure_transfer_internal_details_columns($pdo);
    ensure_transfer_locations_table($pdo);
    ensure_transfer_external_travel_columns($pdo);
    [$start, $end] = season_end_report_range($pdo);
    $from = $start->format('Y-m-d');
    $to = $end->format('Y-m-d');

    $tramonto = $pdo->prepare("SELECT COUNT(*) accesses, COALESCE(SUM(stations_count),0) stations,
        COALESCE(SUM(adults_count),0) adults, COALESCE(SUM(children_count),0) children,
        COALESCE(SUM(infants_count),0) infants, COALESCE(SUM(extra_sunbeds_count),0) extra_sunbeds,
        COALESCE(SUM(final_amount),0) revenue
        FROM tramontoday_bookings WHERE booking_date BETWEEN ? AND ? AND booking_status NOT IN ('annullata','no_show')");
    $tramonto->execute([$from, $to]);
    $tramontoData = $tramonto->fetch(PDO::FETCH_ASSOC) ?: [];
    $tramontoStatus = $pdo->prepare("SELECT
        COALESCE(SUM(booking_status='annullata'),0) cancelled,
        COALESCE(SUM(booking_status='no_show'),0) no_show
        FROM tramontoday_bookings WHERE booking_date BETWEEN ? AND ?");
    $tramontoStatus->execute([$from, $to]);
    $tramontoData += ($tramontoStatus->fetch(PDO::FETCH_ASSOC) ?: []);

    $internal = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(tl.distance_km * 2),0) km
        FROM transfers_internal ti LEFT JOIN transfer_locations tl ON tl.name=ti.location
        WHERE ti.deleted_at IS NULL AND ti.when_at >= ? AND ti.when_at < DATE_ADD(?, INTERVAL 1 DAY) AND ti.when_at <= NOW()");
    $internal->execute([$from, $to]);
    $internalData = $internal->fetch(PDO::FETCH_ASSOC) ?: [];
    $externalDate = 'COALESCE(date_time, arrival_date_time, departure_date_time)';
    $external = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(price_eur),0) customer_total,
        COALESCE(SUM(supplier_price_eur),0) supplier_total FROM transfers_external
        WHERE deleted_at IS NULL AND status <> 'annullato' AND $externalDate >= ?
        AND $externalDate < DATE_ADD(?, INTERVAL 1 DAY) AND $externalDate <= NOW()");
    $external->execute([$from, $to]);
    $externalData = $external->fetch(PDO::FETCH_ASSOC) ?: [];

    $riassetti = $pdo->prepare("SELECT COUNT(*) total,
        COALESCE(SUM(qty_matrimoniale),0) matrimoniale, COALESCE(SUM(qty_singola),0) singola,
        COALESCE(SUM(qty_set_bagno),0) set_bagno, COALESCE(SUM(pulizia_extra),0) extra
        FROM riassetti WHERE data_riassetto BETWEEN ? AND ?");
    $riassetti->execute([$from, $to]);
    $riassettiData = $riassetti->fetch(PDO::FETCH_ASSOC) ?: [];
    $costs = get_riassetti_linen_costs($pdo);
    $riassettiData['cost'] = ((int)($riassettiData['matrimoniale'] ?? 0) * $costs['matrimoniale'])
        + ((int)($riassettiData['singola'] ?? 0) * $costs['singola'])
        + ((int)($riassettiData['set_bagno'] ?? 0) * $costs['set_bagno']);

    return ['start' => $start, 'end' => $end, 'tramontoday' => $tramontoData,
        'internal' => $internalData, 'external' => $externalData, 'riassetti' => $riassettiData];
}
