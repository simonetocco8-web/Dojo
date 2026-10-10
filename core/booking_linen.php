<?php
function ensure_booking_linen_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_linen_reservations (
        reference VARCHAR(128) NOT NULL PRIMARY KEY,
        booker VARCHAR(255) NOT NULL,
        check_in DATE NOT NULL,
        check_out DATE NOT NULL,
        nights INT UNSIGNED NOT NULL,
        treatments TEXT NOT NULL,
        rooms_json LONGTEXT NOT NULL,
        status VARCHAR(50) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_booking_linen_checkout(check_out)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function booking_linen_rooms(string $value): array {
    $rooms = [];
    foreach (explode(',', $value) as $entry) {
        if (!preg_match('/^\s*(\d+)\s*-\s*[^()]+\(([^()]*)\)\s*$/u', $entry, $match)) throw new InvalidArgumentException('Trattamenti non riconosciuti.');
        $room = ['room'=>$match[1], 'adults'=>0, 'children'=>0, 'infants'=>0];
        $remaining = preg_replace_callback('/(Adulti|Bambini|Neonati|Infanti)\s*:\s*(\d+)/iu', static function ($group) use (&$room) {
            $key = match (mb_strtolower($group[1], 'UTF-8')) { 'adulti'=>'adults', 'bambini'=>'children', default=>'infants' };
            $room[$key] += (int)$group[2]; return '';
        }, $match[2]);
        if (trim($remaining) !== '' || array_sum([$room['adults'],$room['children'],$room['infants']]) === 0) throw new InvalidArgumentException('Occupanti della camera non riconosciuti.');
        $rooms[] = $room;
    }
    if (!$rooms) throw new InvalidArgumentException('Nessuna camera indicata.');
    return $rooms;
}

function booking_linen_date(string $value): string {
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!d/m/Y', $value, new DateTimeZone('Europe/Rome'));
    if (!$date || $date->format('d/m/Y') !== $value) throw new InvalidArgumentException('Data non valida: usare gg/mm/aaaa.');
    return $date->format('Y-m-d');
}

function booking_linen_parse_csv(string $content): array {
    if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $stream = fopen('php://temp', 'w+'); fwrite($stream, $content); rewind($stream);
    $first = fgets($stream); rewind($stream);
    $delimiter = count(str_getcsv($first ?: '', ';', '"', '')) > count(str_getcsv($first ?: '', ',', '"', '')) ? ';' : ',';
    $headers = fgetcsv($stream, null, $delimiter, '"', '');
    if (!$headers) throw new InvalidArgumentException('CSV vuoto.');
    $headers = array_map(static fn($v)=>mb_strtolower(trim($v), 'UTF-8'), $headers);
    $required = ['numero di riferimento','prenotante','data inizio soggiorno','data partenza','num. notti','trattamenti','stato'];
    foreach ($required as $name) if (count(array_keys($headers, $name, true)) !== 1) throw new InvalidArgumentException('Colonna mancante o duplicata: ' . $name);
    $records = []; $skipped = 0; $line = 1; $seenRows = 0;
    try {
        while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($values === [null]) continue;
            $seenRows++;
            if (count($values) !== count($headers)) throw new InvalidArgumentException('Numero di colonne non valido alla riga ' . $line . '.');
            $row = array_combine($headers, $values);
            $status = trim($row['stato']); $nights = trim($row['num. notti']);
            if (!ctype_digit($nights)) throw new InvalidArgumentException('Numero notti non valido alla riga ' . $line . '.');
            if ($status !== 'Confermate' || (int)$nights <= 7) { $skipped++; continue; }
            try {
                $ref = trim($row['numero di riferimento']); $booker = trim($row['prenotante']);
                if ($ref === '' || mb_strlen($ref) > 128 || $booker === '' || mb_strlen($booker) > 255) throw new InvalidArgumentException('Riferimento o prenotante non valido.');
                $start = booking_linen_date($row['data inizio soggiorno']); $end = booking_linen_date($row['data partenza']);
                if ($end <= $start || (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days !== (int)$nights) throw new InvalidArgumentException('Date del soggiorno e numero notti non coerenti.');
                $treatments = trim($row['trattamenti']);
                $record = ['reference'=>$ref,'booker'=>$booker,'check_in'=>$start,'check_out'=>$end,'nights'=>(int)$nights,'treatments'=>$treatments,'rooms_json'=>json_encode(booking_linen_rooms($treatments), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),'status'=>$status];
                if (isset($records[$ref]) && $records[$ref] !== $record) throw new InvalidArgumentException('Riferimento duplicato con dati diversi.');
                $records[$ref] = $record;
            } catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Riga ' . $line . ': ' . $e->getMessage()); }
        }
        if (!$seenRows) throw new InvalidArgumentException('Il CSV non contiene prenotazioni.');
    } finally { fclose($stream); }
    return ['records'=>array_values($records), 'skipped'=>$skipped];
}

function booking_linen_import(PDO $pdo, string $content, ?DateTimeImmutable $now = null): array {
    $parsed = booking_linen_parse_csv($content);
    $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d');
    $result = ['created'=>0,'updated'=>0,'unchanged'=>0,'skipped'=>$parsed['skipped'],'expired'=>0];
    $pdo->beginTransaction();
    try {
        // Serializza gli import, compreso il primo caricamento senza righe.
        $lock = $pdo->query("SELECT GET_LOCK('dojo_booking_linen_import', 5)")->fetchColumn();
        if ((int)$lock !== 1) throw new RuntimeException('Un altro import è in corso. Riprova.');
        $find = $pdo->prepare('SELECT reference,booker,check_in,check_out,nights,treatments,rooms_json,status FROM booking_linen_reservations WHERE reference=? FOR UPDATE');
        $insert = $pdo->prepare('INSERT INTO booking_linen_reservations(reference,booker,check_in,check_out,nights,treatments,rooms_json,status) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($parsed['records'] as $record) {
            $find->execute([$record['reference']]); $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!$old) { $insert->execute(array_values($record)); $result['created']++; continue; }
            $changed = [];
            foreach ($record as $field=>$value) if ((string)$old[$field] !== (string)$value) $changed[$field] = $value;
            if (!$changed) { $result['unchanged']++; continue; }
            $sql = 'UPDATE booking_linen_reservations SET ' . implode(',', array_map(static fn($key)=>$key . '=?', array_keys($changed))) . ' WHERE reference=?';
            $pdo->prepare($sql)->execute(array_merge(array_values($changed), [$record['reference']])); $result['updated']++;
        }
        $delete = $pdo->prepare('DELETE FROM booking_linen_reservations WHERE check_out<?'); $delete->execute([$today]); $result['expired'] = $delete->rowCount();
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('dojo_booking_linen_import')"); }
    return $result;
}

function booking_linen_schedule(array $reservations): array {
    $schedule = [];
    foreach ($reservations as $booking) {
        if ($booking['status'] !== 'Confermate' || (int)$booking['nights'] <= 7) continue;
        $date = (new DateTimeImmutable($booking['check_in']))->modify('+' . intdiv((int)$booking['nights'], 2) . ' days')->format('Y-m-d');
        foreach (json_decode($booking['rooms_json'], true, 512, JSON_THROW_ON_ERROR) as $room) {
            $adults = (int)$room['adults'];
            $schedule[] = ['reference'=>$booking['reference'],'booker'=>$booking['booker'],'date'=>$date,'room'=>$room['room'],'adults'=>$adults,'children'=>(int)$room['children'],'infants'=>(int)($room['infants'] ?? 0),'double'=>$adults >= 2 ? 1 : 0,'single'=>($adults >= 2 ? $adults - 2 : $adults) + (int)$room['children'],'review'=>$adults >= 4 || !empty($room['infants'])];
        }
    }
    usort($schedule, static fn($a,$b)=>[$a['date'],(int)$a['room'],$a['reference']] <=> [$b['date'],(int)$b['room'],$b['reference']] );
    return $schedule;
}
