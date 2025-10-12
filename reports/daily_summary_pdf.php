<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../dompdf/vendor/autoload.php';

/**
 * Generate the daily PDF summary for the given date (defaults to today).
 *
 * The PDF contains the top 10 tasks due on the selected day, all room makeups,
 * internal and external transfers, days off, the top 10 low-stock products and
 * the suppliers accepting orders. It returns the filesystem path of the saved
 * PDF so it can be consumed by a CRON job or any other automation.
 *
 * @param string|null $dateYmd     Date in Y-m-d format or null for today.
 * @param string|null $outputPath  Absolute path where the PDF should be saved.
 *                                 When null a file in the system temp directory
 *                                 will be created.
 *
 * @throws InvalidArgumentException When the provided date is invalid.
 *
 * @return string The absolute path to the generated PDF file.
 */
function generate_daily_summary_pdf(?string $dateYmd = null, ?string $outputPath = null): string
{
    $pdo = db();
    $tz  = new DateTimeZone('Europe/Rome');

    if ($dateYmd === null) {
        $date = new DateTime('now', $tz);
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $dateYmd, $tz);
        if (!$date) {
            throw new InvalidArgumentException('Formato data non valido, usa YYYY-MM-DD.');
        }
    }

    $dayYmd      = $date->format('Y-m-d');
    $displayDate = $date->format('d/m/Y');

    // --- Tasks (top 10 for the day) ---
    $taskStmt = $pdo->prepare(
        "SELECT title, description, priority, dipartimento, status\n         FROM tasks\n         WHERE deleted_at IS NULL\n           AND due_date = ?\n         ORDER BY FIELD(priority, 'urgente','alta','media','bassa') ASC, id DESC\n         LIMIT 10"
    );
    $taskStmt->execute([$dayYmd]);
    $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Riassetti of the day ---
    $riassettiStmt = $pdo->prepare(
        "SELECT room, qty_matrimoniale, qty_singola, qty_set_bagno, pulizia_extra, note\n         FROM riassetti\n         WHERE data_riassetto = ?\n         ORDER BY room ASC, id ASC"
    );
    $riassettiStmt->execute([$dayYmd]);
    $riassetti = $riassettiStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Internal transfers ---
    $internalStmt = $pdo->prepare(
        "SELECT room_number, direction, location, when_at\n         FROM transfers_internal\n         WHERE deleted_at IS NULL\n           AND DATE(when_at) = ?\n         ORDER BY when_at ASC, id ASC"
    );
    $internalStmt->execute([$dayYmd]);
    $internalTransfers = $internalStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- External transfers ---
    $externalStmt = $pdo->prepare(
        "SELECT type, place, date_time, pickup_time, room_number, guest_name, service_company, booked, paid, status\n         FROM transfers_external\n         WHERE deleted_at IS NULL\n           AND DATE(date_time) = ?\n         ORDER BY date_time ASC, id ASC"
    );
    $externalStmt->execute([$dayYmd]);
    $externalTransfers = $externalStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Days off ---
    $daysOffStmt = $pdo->prepare(
        "SELECT u.nome, u.cognome, u.dipartimento, d.note\n         FROM days_off d\n         JOIN users u ON u.id = d.user_id\n         WHERE d.deleted_at IS NULL\n           AND d.day = ?\n         ORDER BY u.cognome ASC, u.nome ASC"
    );
    $daysOffStmt->execute([$dayYmd]);
    $daysOff = $daysOffStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Low stock products (top 10) ---
    $lowStockStmt = $pdo->query(
        "SELECT\n            p.title,\n            p.category,\n            p.min_qty,\n            COALESCE(SUM(sl.qty), 0) AS total_qty\n         FROM products p\n         LEFT JOIN stock_levels sl ON sl.product_id = p.id\n         GROUP BY p.id, p.title, p.category, p.min_qty\n         HAVING COALESCE(SUM(sl.qty), 0) < p.min_qty\n         ORDER BY total_qty ASC, p.title ASC\n         LIMIT 10"
    );
    $lowStock = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Suppliers accepting orders today ---
    $weekdayIndex = (int)$date->format('w'); // 0=Sun ... 6=Sat in PHP
    $supplierStmt = $pdo->prepare(
        "SELECT s.name, s.phone\n         FROM suppliers s\n         JOIN supplier_days d\n           ON d.supplier_id = s.id\n          AND d.kind = 'order'\n          AND d.day = :day\n         ORDER BY s.name ASC"
    );
    $supplierStmt->execute([':day' => $weekdayIndex]);
    $suppliersToday = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);

    $weekdayLabels = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
    $weekdayName   = $weekdayLabels[$weekdayIndex] ?? '';

    $priorityLabel = static function (string $priority): string {
        return match ($priority) {
            'urgente' => 'Urgente',
            'alta'    => 'Alta',
            'media'   => 'Media',
            'bassa'   => 'Bassa',
            default   => ucfirst($priority),
        };
    };

    $statusLabel = static function (string $status): string {
        return match ($status) {
            'aperto'         => 'Aperto',
            'completato'     => 'Completato',
            'non_fattibile'  => 'Non fattibile',
            default          => ucfirst(str_replace('_', ' ', $status)),
        };
    };

    $linenSummary = static function (array $row): string {
        $parts = [];
        if (!empty($row['qty_matrimoniale'])) {
            $parts[] = $row['qty_matrimoniale'] . ' Matrimoniale';
        }
        if (!empty($row['qty_singola'])) {
            $parts[] = $row['qty_singola'] . ' Singola';
        }
        if (!empty($row['qty_set_bagno'])) {
            $parts[] = $row['qty_set_bagno'] . ' Set Bagno';
        }
        return $parts ? implode(', ', $parts) : '—';
    };

    ob_start();
    ?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 12px; color: #222; }
    h1 { font-size: 20px; margin-bottom: 12px; }
    h2 { font-size: 16px; margin-top: 24px; margin-bottom: 8px; border-bottom: 1px solid #999; padding-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #bbb; padding: 6px 8px; vertical-align: top; }
    th { background: #f4f4f4; text-align: left; }
    .small { font-size: 11px; color: #555; }
    .muted { color: #777; }
    .section-description { margin-bottom: 6px; }
  </style>
</head>
<body>
  <h1>Riepilogo giornaliero — <?= e($displayDate) ?></h1>

  <h2>Task del giorno (Top 10)</h2>
  <?php if ($tasks): ?>
  <table>
    <thead>
      <tr>
        <th>Dipartimento</th>
        <th>Titolo</th>
        <th>Priorità</th>
        <th>Stato</th>
        <th>Descrizione</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tasks as $task): ?>
      <tr>
        <td><?= e($task['dipartimento'] ?? '') ?></td>
        <td><?= e($task['title'] ?? '') ?></td>
        <td><?= e($priorityLabel($task['priority'] ?? '')) ?></td>
        <td><?= e($statusLabel($task['status'] ?? '')) ?></td>
        <td><?= nl2br(e($task['description'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">Nessun task con data odierna.</p>
  <?php endif; ?>

  <h2>Riassetti</h2>
  <?php if ($riassetti): ?>
  <table>
    <thead>
      <tr>
        <th>Camera</th>
        <th>Biancheria</th>
        <th>Pulizia extra</th>
        <th>Note</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($riassetti as $row): ?>
      <tr>
        <td><?= e($row['room'] ?? '') ?></td>
        <td><?= e($linenSummary($row)) ?></td>
        <td><?= !empty($row['pulizia_extra']) ? 'Sì' : 'No' ?></td>
        <td><?= nl2br(e($row['note'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">Nessun riassetto programmato per oggi.</p>
  <?php endif; ?>

  <h2>Transfer interni</h2>
  <?php if ($internalTransfers): ?>
  <table>
    <thead>
      <tr>
        <th>Ora</th>
        <th>Camera</th>
        <th>Verso</th>
        <th>Località</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($internalTransfers as $row): ?>
      <?php $dt = $row['when_at'] ? new DateTime($row['when_at'], $tz) : null; ?>
      <tr>
        <td><?= $dt ? e($dt->format('H:i')) : '—' ?></td>
        <td><?= e($row['room_number'] ?? '') ?></td>
        <td><?= e(strtoupper($row['direction'] ?? '')) ?></td>
        <td><?= e($row['location'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">Nessun transfer interno per oggi.</p>
  <?php endif; ?>

  <h2>Transfer esterni</h2>
  <?php if ($externalTransfers): ?>
  <table>
    <thead>
      <tr>
        <th>Ora</th>
        <th>Tipo</th>
        <th>Luogo</th>
        <th>Camera</th>
        <th>Ospite</th>
        <th>Compagnia</th>
        <th>Prenotato</th>
        <th>Pagato</th>
        <th>Stato</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($externalTransfers as $row): ?>
      <?php $dt = $row['date_time'] ? new DateTime($row['date_time'], $tz) : null; ?>
      <tr>
        <td><?= $dt ? e($dt->format('H:i')) : '—' ?></td>
        <td><?= e(ucfirst($row['type'] ?? '')) ?></td>
        <td><?= e($row['place'] ?? '') ?></td>
        <td><?= e($row['room_number'] ?? '') ?></td>
        <td><?= e($row['guest_name'] ?? '') ?></td>
        <td><?= e($row['service_company'] ?? '') ?></td>
        <td><?= !empty($row['booked']) ? 'Sì' : 'No' ?></td>
        <td><?= !empty($row['paid']) ? 'Sì' : 'No' ?></td>
        <td><?= e($statusLabel($row['status'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">Nessun transfer esterno per oggi.</p>
  <?php endif; ?>

  <h2>Giorni liberi</h2>
  <?php if ($daysOff): ?>
  <table>
    <thead>
      <tr>
        <th>Nome</th>
        <th>Dipartimento</th>
        <th>Note</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($daysOff as $row): ?>
      <tr>
        <td><?= e(trim(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? ''))) ?></td>
        <td><?= e($row['dipartimento'] ?? '') ?></td>
        <td><?= nl2br(e($row['note'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">Nessun giorno libero registrato per oggi.</p>
  <?php endif; ?>

  <h2>Prodotti sottoscorta (Top 10)</h2>
  <?php if ($lowStock): ?>
  <table>
    <thead>
      <tr>
        <th>Prodotto</th>
        <th>Categoria</th>
        <th>Disponibilità</th>
        <th>Scorta minima</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lowStock as $row): ?>
      <tr>
        <td><?= e($row['title'] ?? '') ?></td>
        <td><?= e($row['category'] ?? '') ?></td>
        <td><?= e((string)($row['total_qty'] ?? 0)) ?></td>
        <td><?= e((string)($row['min_qty'] ?? 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">Nessun prodotto risulta sottoscorta.</p>
  <?php endif; ?>

  <h2>Fornitori che accettano ordini (<?= e($weekdayName) ?>)</h2>
  <?php if ($suppliersToday): ?>
  <table>
    <thead>
      <tr>
        <th>Nome</th>
        <th>Telefono</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($suppliersToday as $row): ?>
      <tr>
        <td><?= e($row['name'] ?? '') ?></td>
        <td><?= $row['phone'] ? e($row['phone']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">Nessun fornitore accetta ordini oggi.</p>
  <?php endif; ?>
</body>
</html>
<?php
    $html = ob_get_clean();

    if (!class_exists(Dompdf::class)) {
        throw new RuntimeException('Libreria Dompdf non disponibile.');
    }

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfContent = $dompdf->output();

    if ($outputPath === null) {
        $outputPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'daily_summary_' . $date->format('Ymd_His') . '.pdf';
    }

    $directory = dirname($outputPath);
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossibile creare la cartella di destinazione: ' . $directory);
        }
    }

    file_put_contents($outputPath, $pdfContent);

    return $outputPath;
}

/**
 * Recupera gli indirizzi email degli utenti attivi dei reparti Amministrazione e Reception.
 *
 * @param PDO $pdo
 * @return string[]
 */
function fetch_daily_summary_recipients(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT email\n         FROM users\n         WHERE deleted_at IS NULL\n           AND is_active = 1\n           AND email IS NOT NULL\n           AND email <> ''\n           AND dipartimento IN ('Amministrazione','Reception')"
    );
    $stmt->execute();

    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $validEmails = [];
    foreach ($emails as $email) {
        $email = trim((string)$email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validEmails[$email] = true; // usa le chiavi per evitare duplicati
        }
    }

    return array_keys($validEmails);
}

function encode_mime_header_utf8(string $value): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function log_mail_fallback(string $to, string $subject, string $body): void
{
    $logDir = __DIR__ . '/../storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logPath = $logDir . '/mail.log';
    $entry   = '[' . date('c') . "] TO:$to\nSUBJECT:$subject\n$body\n\n";
    file_put_contents($logPath, $entry, FILE_APPEND);
}

/**
 * Invia il PDF generato via email ai destinatari indicati.
 *
 * @param string[] $recipients
 * @throws RuntimeException
 */
function send_daily_summary_pdf_email(array $recipients, string $subject, string $htmlBody, string $attachmentPath, string $attachmentFilename): void
{
    if (!$recipients) {
        return;
    }

    if (!is_readable($attachmentPath)) {
        throw new RuntimeException('Impossibile leggere il PDF generato per l\'invio.');
    }

    $pdfData = file_get_contents($attachmentPath);
    if ($pdfData === false) {
        throw new RuntimeException('Impossibile caricare il contenuto del PDF da allegare.');
    }

    $env       = require __DIR__ . '/../config/env.php';
    $fromEmail = $env['mail']['from'] ?? 'no-reply@example.com';
    $fromName  = $env['mail']['from_name'] ?? 'Admin';

    $fromHeader   = encode_mime_header_utf8($fromName) . " <{$fromEmail}>";
    $subjectFinal = encode_mime_header_utf8($subject);

    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $attachmentFilename);
    if ($safeFilename === '') {
        $safeFilename = 'riepilogo.pdf';
    }

    $encodedPdf = chunk_split(base64_encode($pdfData));

    foreach ($recipients as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $boundary = '=_Part_' . bin2hex(random_bytes(16));

        $headers = [
            'From: ' . $fromHeader,
            'Reply-To: ' . $fromHeader,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];

        $messageParts = [
            '--' . $boundary,
            'Content-Type: text/html; charset="UTF-8"',
            'Content-Transfer-Encoding: 8bit',
            '',
            $htmlBody,
            '',
            '--' . $boundary,
            'Content-Type: application/pdf; name="' . $safeFilename . '"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="' . $safeFilename . '"',
            '',
            $encodedPdf,
            '',
            '--' . $boundary . '--',
            '',
        ];

        $message = implode("\r\n", $messageParts);
        $headerString = implode("\r\n", $headers);

        $ok = @mail($email, $subjectFinal, $message, $headerString);
        if (!$ok) {
            log_mail_fallback($email, $subject, $htmlBody . "\n[allegato: " . $safeFilename . ']');
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $dateArg = $argv[1] ?? null;
    $pathArg = $argv[2] ?? null;

    try {
        $generatedPath = generate_daily_summary_pdf($dateArg ?: null, $pathArg ?: null);
        fwrite(STDOUT, "PDF generato: {$generatedPath}\n");

        $tz = new DateTimeZone('Europe/Rome');
        $reportDate = $dateArg
            ? DateTime::createFromFormat('Y-m-d', $dateArg, $tz)
            : new DateTime('now', $tz);

        if ($dateArg && !$reportDate) {
            throw new RuntimeException('Formato data non valido.');
        }

        $pdo         = db();
        $recipients  = fetch_daily_summary_recipients($pdo);
        $displayDate = $reportDate->format('d/m/Y');

        if ($recipients) {
            $subject = 'Riepilogo giornaliero ' . $displayDate;
            $body    = '<p>Buongiorno,</p>'
                . '<p>in allegato trovi il riepilogo giornaliero del ' . $displayDate . '.</p>'
                . '<p>Questo messaggio è stato generato automaticamente.</p>';
            $filename = 'Riepilogo_' . $reportDate->format('Ymd') . '.pdf';

            send_daily_summary_pdf_email($recipients, $subject, $body, $generatedPath, $filename);
            fwrite(STDOUT, 'Email inviate a: ' . implode(', ', $recipients) . "\n");
        } else {
            fwrite(STDOUT, "Nessun destinatario trovato per l'invio del report.\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Errore: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
