<?php
// core/mailer.php
/**
 * Restituisce l'argomento sendmail che imposta il mittente della busta SMTP.
 *
 * Il solo header "From" non modifica il Return-Path: in assenza di -f PHP usa
 * l'utente del web server, che normalmente non è autorizzato dallo SPF del
 * dominio e può quindi essere rifiutato dai server di posta esterni.
 */
function mail_envelope_sender_argument(string $from): string {
  $from = trim($from);
  if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
    return '';
  }

  return '-f' . escapeshellarg($from);
}

function send_mail(string $to, string $subject, string $html, ?string $replyTo = null): bool {
  $env = require __DIR__ . '/../config/env.php';
  $from = $env['mail']['from'] ?? 'no-reply@example.com';
  $from_name = $env['mail']['from_name'] ?? 'Admin';
  $headers = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type:text/html;charset=UTF-8\r\n";
  $headers .= "From: " . sprintf('%s <%s>', $from_name, $from) . "\r\n";
  if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
    $headers .= "Reply-To: " . $replyTo . "\r\n";
  }
  $envelopeSender = mail_envelope_sender_argument($from);
  $ok = $envelopeSender !== ''
    ? @mail($to, $subject, $html, $headers, $envelopeSender)
    : @mail($to, $subject, $html, $headers);
  if (!$ok) {
    $log = __DIR__ . '/../storage/mail.log';
    if (!is_dir(dirname($log))) @mkdir(dirname($log), 0775, true);
    file_put_contents($log, "[".date('c')."] TO:$to\nSUBJECT:$subject\nREPLY-TO:" . ($replyTo ?? '') . "\n$html\n\n", FILE_APPEND);
    return true;
  }
  return true;
}
